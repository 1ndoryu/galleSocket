<?php

namespace Galle;

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class Chat implements MessageComponentInterface
{
    protected $clients;
    protected $users;
    protected $autenticados;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->users = [];
        $this->autenticados = [];
        echo "Chat instance created\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";

        // Envía una instrucción para que el cliente envíe su token de autenticación
        $conn->send(json_encode(['type' => 'auth', 'message' => 'Por favor, envía tu token de autenticación.']));
    }

    /* 


    */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        // echo "Mensaje recibido de {$from->resourceId}: " . $msg . "\n"; // Comentado: Log innecesario

        // Intentar decodificar el mensaje JSON
        $data = json_decode($msg, true);

        if (!$data) {
            // echo "Error: Mensaje no es JSON válido\n"; // Comentado: Log innecesario
            return;
        }

        // echo "Datos del mensaje decodificado:\n"; // Comentado: Log innecesario
        // print_r($data); // Comentado: Log innecesario


        // Si el mensaje es de autenticación
        if (isset($data['type']) && $data['type'] === 'auth') {
            // echo "Mensaje de autenticación recibido. Verificando token...\n"; // Comentado: Log innecesario
            $this->verificarToken($from, $data['token'], $data['emisor']);
            return;
        }

        // Verificar si el usuario está autenticado
        if (!isset($this->autenticados[$from->resourceId])) {
            // echo "Error: Usuario no autenticado para la conexión {$from->resourceId}\n"; // Comentado: Log innecesario
            $from->send(json_encode(['error' => 'No autenticado']));
            // echo "Enviado al {$from->resourceId}: " . json_encode(['error' => 'No autenticado']) . "\n";
            return;
        }

        // Si el mensaje es un ping, responder con un pong
        if (isset($data['type']) && $data['type'] === 'ping') {
            // echo "Ping recibido de {$from->resourceId}, enviando pong...\n"; // Comentado: Log innecesario
            $from->send(json_encode(['type' => 'pong']));
            // echo "Enviado al {$from->resourceId}: " . json_encode(['type' => 'pong']) . "\n";
            return;
        }

        // Si hay un emisor pero no se ha asociado aún a la conexión
        if (isset($data['emisor']) && !isset($this->users[$from->resourceId])) {
            $this->users[$from->resourceId] = $data['emisor'];
            // echo "Emisor {$data['emisor']} asociado con conexión {$from->resourceId}\n"; // Comentado: Log innecesario
        }

        // Verificar si el emisor está correctamente asociado
        if (!isset($this->users[$from->resourceId])) {
            // echo "Error: Emisor no está asociado a la conexión {$from->resourceId}\n"; // Comentado: Log innecesario
            return;
        }

        // Si hay un receptor especificado, intentar enviar el mensaje
        if (isset($data['receptor'])) {
            // Intentar decodificar el campo 'receptor' como un array JSON
            $receptorIds = json_decode($data['receptor'], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($receptorIds)) {
                // Es un mensaje grupal
                // echo "Mensaje grupal a los receptores: " . implode(', ', $receptorIds) . "\n"; // Comentado: Log innecesario

                // Omitir al emisor de la lista de receptores si está presente
                $receptorIds = array_diff($receptorIds, [$data['emisor']]);

                $receptoresEncontrados = [];
                foreach ($this->clients as $client) {
                    if (isset($this->users[$client->resourceId]) && in_array($this->users[$client->resourceId], $receptorIds)) {
                        $client->send($msg);
                        echo "Enviado a {$this->users[$client->resourceId]} (conexión {$client->resourceId}): " . $msg . "\n";
                        $receptoresEncontrados[] = $this->users[$client->resourceId];
                    }
                }

                // Verificar si hay receptores que no están conectados
                $receptoresNoConectados = array_diff($receptorIds, $receptoresEncontrados);
                if (!empty($receptoresNoConectados)) {
                    // echo "Receptores no conectados: " . implode(', ', $receptoresNoConectados) . "\n"; // Comentado: Log innecesario
                }
            } else {
                // No es un array, tratar como receptor único
                $receptorId = $data['receptor'];
                // echo "Buscando receptor con ID: {$receptorId}\n"; // Comentado: Log innecesario

                $receptorEncontrado = false;
                foreach ($this->clients as $client) {
                    if (isset($this->users[$client->resourceId]) && $this->users[$client->resourceId] == $receptorId) {
                        $client->send($msg);
                        echo "Enviado a {$receptorId} (conexión {$client->resourceId}): " . $msg . "\n";
                        $receptorEncontrado = true;
                        break;
                    }
                }

                if (!$receptorEncontrado) {
                    // echo "Receptor {$receptorId} no encontrado o no conectado\n"; // Comentado: Log innecesario
                }
            }
        } else {
            // echo "Mensaje sin receptor\n"; // Comentado: Log innecesario
        }

        // Guardar el mensaje en WordPress
        // echo "Intentando guardar mensaje en WordPress...\n"; // Comentado: Log innecesario

        // Verificar si hay un token autenticado asociado
        if (isset($this->autenticados[$from->resourceId])) {
            // echo "Token autenticado: " . $this->autenticados[$from->resourceId] . "\n"; // Comentado: Log innecesario

            // Obtener el user_id (emisor) para pasarlo junto con el token
            if (isset($data['emisor'])) {
                $user_id = $data['emisor'];
                $conversacion_id = $data['conversacion_id'] ?? null; // Obtener la conversacion_id si está presente
                $this->guardarMensajeEnWordPress($from, $data, $this->autenticados[$from->resourceId], $user_id, $conversacion_id);
                // echo "Mensaje guardado en WordPress para el usuario {$user_id}\n"; // Opcional: Puedes descomentar si necesitas este log
            } else {
                // echo "Error: No se proporcionó un emisor en los datos\n"; // Comentado: Log innecesario
            }
        } else {
            // echo "Error: No se encontró un token autenticado para la conexión {$from->resourceId}\n"; // Comentado: Log innecesario
        }
    }

    private function verificarToken(ConnectionInterface $conn, $token, $emisor)
    {
        // Log para ver el token y el emisor que se están verificando
        // echo "Iniciando verificación del token para el emisor: {$emisor} en la conexión {$conn->resourceId}\n";
        // echo "Token recibido: {$token}\n";

        // URL del endpoint de verificación de token
        $url = 'https://2upra.com/wp-json/galle/v2/verificartoken';

        // Iniciar cURL
        $ch = curl_init($url);

        // Configurar opciones de cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);

        // Enviar tanto el token como el emisor en los datos POST
        $postData = json_encode(['token' => $token, 'user_id' => $emisor]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        // Log para mostrar los datos que se están enviando
        echo "Datos enviados a WordPress: {$postData}\n";

        // Ejecutar la solicitud cURL
        $result = curl_exec($ch);
        $error = curl_error($ch);

        curl_close($ch);

        // Si cURL falla
        if ($result === FALSE) {
            echo "Error de cURL: No se pudo contactar con el servidor de autenticación de WordPress. Detalles: {$error}\n";
            $conn->send(json_encode(['type' => 'auth', 'status' => 'error', 'message' => 'No se pudo contactar con el servidor de autenticación.']));
            return;
        }

        // Log para mostrar la respuesta recibida de WordPress
        echo "Respuesta recibida de WordPress: {$result}\n";

        // Decodificar la respuesta de WordPress
        $response = json_decode($result, true);

        // Verificar si la respuesta es válida y tiene el formato esperado
        if ($response === null) {
            echo "Error: La respuesta de WordPress no es un JSON válido.\n";
            $conn->send(json_encode(['type' => 'auth', 'status' => 'error', 'message' => 'Error en la respuesta del servidor de autenticación.']));
            return;
        }

        // Verificar si la respuesta es válida y correcta
        if (isset($response['valid']) && $response['valid']) {
            // Asociar el emisor y el token con la conexión
            $this->users[$conn->resourceId] = $emisor;
            $this->autenticados[$conn->resourceId] = $token;

            // Enviar respuesta de éxito al cliente
            $conn->send(json_encode(['type' => 'auth', 'status' => 'success']));
            // echo "Autenticación exitosa para el emisor: {$emisor} en la conexión {$conn->resourceId}\n";
        } else {
            // Si el token es inválido
            echo "Error: Token inválido para el emisor: {$emisor} en la conexión {$conn->resourceId}\n";
            $conn->send(json_encode(['type' => 'auth', 'status' => 'failed', 'message' => 'Token inválido.']));
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        unset($this->users[$conn->resourceId]);
        unset($this->autenticados[$conn->resourceId]);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error en la conexión {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    private function guardarMensajeEnWordPress($from, $data, $token, $user_id, $conversacion_id = null)
    {
        // echo "Datos a enviar a WordPress: " . json_encode($data) . "\n";
        // echo "Token usado para autenticar en WordPress: $token\n";
        // echo "User ID usado para autenticar en WordPress: $user_id\n";

        $url = 'https://2upra.com/wp-json/galle/v2/procesarmensaje';
        $max_intentos = 5; // Número máximo de intentos
        $intento_actual = 0;

        // Añadir la conversacion_id si está presente
        if ($conversacion_id) {
            $data['conversacion_id'] = $conversacion_id;
            echo "Conversación ID: $conversacion_id añadida al mensaje.\n";
        }

        while ($intento_actual < $max_intentos) {
            // Iniciar cURL
            $ch = curl_init($url);

            // Configurar opciones de cURL
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "X-WP-Token: $token",   // Cambia a X-WP-Token o cualquier nombre adecuado
                "X-User-ID: $user_id"   // Envía el user_id en los encabezados
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            // Ejecutar la solicitud cURL
            $result = curl_exec($ch);
            $error = curl_error($ch);

            // Si cURL tiene éxito
            if ($result !== FALSE) {
                echo "Respuesta de WordPress: {$result}\n";

                // **Enviar confirmación al cliente**
                $confirmation = [
                    'type' => 'message_saved',
                    'message_id' => $result['id'] ?? null, // Si WordPress devuelve un ID de mensaje
                    'timestamp' => time(),
                    'original_message' => $data
                ];
                $from->send(json_encode($confirmation));

                curl_close($ch);
                break; // Salir del bucle si la solicitud es exitosa
            } else {
                echo "Error de cURL: No se pudo guardar el mensaje en WordPress. Detalles: {$error}\n";
                print_r(curl_getinfo($ch)); // Muestra información de depuración sobre la solicitud cURL
            }

            curl_close($ch);

            // Incrementar el contador de intentos
            $intento_actual++;

            // Esperar antes de reintentar (opcional)
            if ($intento_actual < $max_intentos) {
                sleep(1); // Espera 2 segundos antes de reintentar
                echo "Reintentando (intento $intento_actual de $max_intentos)...\n";
            }
        }

        if ($intento_actual == $max_intentos) {
            echo "Se alcanzó el número máximo de intentos. El mensaje no se pudo guardar.\n";

            // **Enviar mensaje de error al cliente**
            $errorResponse = [
                'type' => 'message_error',
                'error' => 'No se pudo guardar el mensaje en el servidor.',
                'original_message' => $data
            ];
            $from->send(json_encode($errorResponse));
        }
    }
}

// Configuración del servidor
$chat = new Chat();

// Crear el servidor
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            $chat
        )
    ),
    8082
);

echo "Servidor WebSocket iniciado en el puerto 8082\n";
$server->run();
