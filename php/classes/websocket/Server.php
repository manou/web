<?php
/**
 * WebSocket server to handle multiple clients connections and maintain WebSocket services
 *
 * @package    Class
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\websocket;

use \classes\ExceptionManager as Exception;
use \classes\IniManager as Ini;
use \classes\entitiesManager\UserEntityManager as UserEntityManager;

/**
 * WebSocket server to handle multiple clients connections and maintain WebSocket services
 */
class Server
{
    use \traits\EchoTrait;

    /**
     * @var        string  $protocol    The server socket protocol
     */
    private $protocol;
    /**
     * @var        string  $address     The server socket address
     */
    private $address;
    /**
     * @var        int   $port  The server socket port
     */
    private $port;
    /**
     * @var        string  $serverAddress   The server address
     */
    private $serverAddress;
    /**
     * @var        boolean  $verbose    True to print info in console else false
     */
    private $verbose;
    /**
     * @var        int   $errorNum  The error code if an error occured
     */
    private $errorNum;
    /**
     * @var        string  $errorString     The error string if an error occured
     */
    private $errorString;
    /**
     * @var        resource[]  $clients     The clients socket stream
     */
    private $clients = array();
    /**
     * @var        array  $services     The services functions / methods implemented
     */
    private $services = array();
    /**
     * @var        string  $serviceRegex    The regex to get service log entries
     */
    private $serviceRegex;
    /**
     * @var        string  $notificationService     The notification service name
     */
    private $notificationService;
    /**
     * @var        string  $websocketService    The websocket service name
     */
    private $websocketService;
    /**
     * @var        string  $serverKey   A key to authentificate the server when sending data to services
     */
    protected $serverKey;
    /**
     * @var        resource  $server    The server socket resource
     */
    protected $server;

    /*=====================================
    =            Magic methods            =
    =====================================*/

    /**
     * Constructor that load parameters in the ini conf file and run the WebSocket server
     */
    public function __construct()
    {
        cli_set_process_title('PHP socket server');

        $params                    = Ini::getSectionParams('Socket');
        $this->serviceRegex        = '/^' . $params['serviceKey'] . '(.*)/';
        $this->serverKey           = $params['serverKey'];
        $this->notificationService = $params['notificationService'];
        $this->websocketService    = $params['websocketService'];
        $this->protocol            = $params['protocol'];
        $this->address             = $params['address'];
        $this->port                = $params['port'];
        $this->verbose             = $params['verbose'];
        $this->serverAddress       = $this->protocol . '://' . $this->address . ':' . $this->port;
        $this->server              = stream_socket_server($this->serverAddress, $this->errorNum, $this->errorString);

        if ($this->server === false) {
            throw new Exception('Error ' . $this->errorNum . '::' . $this->errorString, Exception::$ERROR);
        }

        $this->run();
    }

    /*=====  End of Magic methods  ======*/

    /*=========================================
    =            Protected methods            =
    =========================================*/

    /**
     * Get the client name from his socket stream
     *
     * @param      resource  $socket  The client socket
     *
     * @return     string    The client name
     */
    protected function getClientName($socket): string
    {
        return md5(stream_socket_get_name($socket, true));
    }

    /**
     * Get the client ip from his socket stream
     *
     * @param      resource  $socket  The client socket
     *
     * @return     string    The client ip
     */
    protected function getClientIp($socket): string
    {
        $ipClient = stream_socket_get_name($socket, true);

        // Extract only the ip and not the port
        if (strpos($ipClient, ':') !== false) {
            $ipClient = substr($ipClient, 0, strpos($ipClient, ':'));
        }

        return $ipClient;
    }

    /**
     * Send data to a client via stream socket
     *
     * @param      resource  $socket  The client stream socket
     * @param      string    $data    The data to send
     */
    protected function send($socket, string $data)
    {
        stream_socket_sendto($socket, $data);
    }

    /**
     * Disconnect a client
     *
     * @param      resource  $socket      The client socket
     * @param      string    $clientName  OPTIONAL the client name
     */
    protected function disconnect($socket, string $clientName = null)
    {
        if ($clientName === null) {
            $clientName = $this->getClientName($socket);
        }

        $data = array('action' => $this->serverKey . 'disconnect', 'clientSocket' => $socket);

        foreach (array_keys($this->services) as $serviceName) {
            call_user_func_array($this->services[$serviceName], array($socket, $data));
        }

        stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
        unset($this->clients[$clientName]);

        $this->log('[SERVER] Client disconnected : ' . $clientName);
    }

    /**
     * Encode a text to send to client via ws://
     *
     * @param      string  $message      The message to encode
     * @param      string  $messageType
     *
     * @return     string
     */
    protected function encode(string $message, string $messageType = 'text')
    {
        switch ($messageType) {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = 1;
                break;
            case 'binary':
                $b1 = 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
        }

        $b1 += 128;
        $length = strlen($message);
        $lengthField = "";

        if ($length < 126) {
            $b2 = $length;
        } elseif ($length <= 65536) {
            $b2 = 126;
            $hexLength = dechex($length);

            if (strlen($hexLength)%2 == 1) {
                $hexLength = '0' . $hexLength;
            }

            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }

            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } else {
            $b2 = 127;
            $hexLength = dechex($length);

            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }

            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }

            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }

        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    /*=====  End of Protected methods  ======*/

    /*=======================================
    =            Private methods            =
    =======================================*/

    /**
     * Run the server, accept connections and handle them
     */
    private function run()
    {
        $this->log('[SERVER] Server running on ' . stream_socket_get_name($this->server, false));

        while (1) {
            $sockets   = $this->clients;
            $sockets[] = $this->server;

            if (@stream_select($sockets, $write = null, $except = null, null) === false) {
                throw new Exception('Error on stream_select', Exception::$ERROR);
            }

            if (count($sockets) > 0) {
                foreach ($sockets as $socket) {
                    if ($socket === $this->server) {
                        $client     = stream_socket_accept($this->server, 30);
                        $clientName = $this->getClientName($client);
                        $data       = $this->get($client);

                        if (preg_match($this->serviceRegex, $data, $match) === 1) {
                            $this->log($match[1]);
                        } elseif (!in_array($clientName, $this->clients)) {
                            $this->clients[$clientName] = $client;
                            $this->handshake($client, $data);
                        }
                    } else {
                        $this->treatDataRecieved($socket);
                    }
                }
            }
        }
    }

    /**
     * Get data from a client via stream socket
     *
     * @param      resource  $socket  The client stream socket
     *
     * @return     string    The client data
     */
    private function get($socket): string
    {
        return stream_socket_recvfrom($socket, 1500);
    }

    /**
     * Treat recieved data from a client socket and perform actions depending on data recieved and services implemented
     * The ping / pong protocol is handled Server management is processing here (add / remove / list services)
     *
     * @param      resource  $socket  The client socket
     */
    private function treatDataRecieved($socket)
    {
        $clientName = $this->getClientName($socket);
        $data       = $this->get($socket);

        if (strlen($data) < 2) {
            $this->disconnect($socket, $clientName);
        } else {
            $data = $this->unmask($data);

            if (trim(strtolower($data)) === 'ping') {
                $this->send($socket, $this->encode('PONG', 'pong'));
            } else {
                $data = json_decode($data, true);

                if (isset($data['action']) && $data['action'] === 'manageServer') {
                    if (!$this->checkAuthentication($data)) {
                        $response = array(
                            'service' => 'notificationService',
                            'success' => false,
                            'text'    => _('Authentication failed')
                        );
                    } else {
                        if (isset($data['addService'])) {
                            $response = $this->addService($data['addService']);
                        } elseif (isset($data['removeService'])) {
                            $response = $this->removeService($data['removeService']);
                        } elseif (isset($data['listServices'])) {
                            $response = array('services' => $this->listServices($data['listServices']));
                        }
                    }

                    $this->send($socket, $this->encode(json_encode($response)));
                } else {
                    if (isset($data['service']) && is_array($data['service'])) {
                        foreach ($data['service'] as $serviceName) {
                            if (in_array($serviceName, array_keys($this->services))) {
                                call_user_func_array($this->services[$serviceName], array($socket, $data));
                            } else {
                                $this->send(
                                    $socket,
                                    $this->encode(
                                        json_encode(
                                            array(
                                            'service' => $this->notificationService,
                                            'success' => false,
                                            'text'    => sprintf(_('The service "%s" is not running'), $serviceName)
                                            )
                                        )
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Perform an handshake with the remote client by sending a specific HTTP response
     *
     * @param      resource  $client  The client socket
     * @param      string    $data    The client data sent to perform the handshake
     */
    private function handshake($client, string $data)
    {
        // Retrieves the header and get the WebSocket-Key
        preg_match('/Sec-WebSocket-Key\: (.*)/', $data, $match);

        // Send the accept key built on the base64 encode of the WebSocket-Key, concated with the magic key, sha1 hash
        $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($match[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) .
        "\r\n\r\n";

        $this->send($client, $upgrade);
        $this->log('[SERVER] New client added : ' . $this->getClientName($client));
    }

    /**
     * Unmask a received payload
     *
     * @param      string  $payload  The buffer string
     *
     * @return     string
     */
    private function unmask(string $payload)
    {
        $length = ord($payload[1]) & 127;
        $text   = '';

        if ($length === 126) {
            $masks = substr($payload, 4, 4);
            $data  = substr($payload, 8);
        } elseif ($length === 127) {
            $masks = substr($payload, 10, 4);
            $data  = substr($payload, 14);
        } else {
            $masks = substr($payload, 2, 4);
            $data  = substr($payload, 6);
        }


        for ($i = 0; $i < strlen($data); $i++) {
            $text .= $data[$i] ^ $masks[$i%4];
        }

        return $text;
    }

    /**
     * Add a service to the server
     *
     * @param      string    $serviceName  The service name
     *
     * @return     string[]  Array containing error or success message
     */
    private function addService(string $serviceName): array
    {
        $message = sprintf(_('The service "%s" is now running'), $serviceName);
        $success = false;

        if (array_key_exists($serviceName, $this->services)) {
            $message = sprintf(_('The service "%s" is already running'), $serviceName);
        } else {
            $servicePath = Ini::getParam('Socket', 'servicesPath') . DIRECTORY_SEPARATOR . $serviceName;

            if (stream_resolve_include_path($servicePath . '.php') === false) {
                $message = sprintf(_('The service "%s" does not exist'), $serviceName);
            } else {
                $service                      = new $servicePath($this->serverAddress);
                $this->services[$serviceName] = array($service, 'service');
                $success                      = true;
                $this->log('[SERVER] Service "' . $serviceName . '" is now running');
            }
        }

        return array(
            'service'     => $this->notificationService,
            'success'     => $success,
            'text'        => $message
        );
    }

    /**
     * Remove a service from the server
     *
     * @param      string    $serviceName  The service name
     *
     * @return     string[]  Array containing errors or empty array if success
     */
    private function removeService(string $serviceName): array
    {
        $message  = sprintf(_('The service "%s" is now stopped'), $serviceName);
        $success = false;

        if (!array_key_exists($serviceName, $this->services)) {
            $message = sprintf(_('The service "%s" is not running'), $serviceName);
        } else {
            unset($this->services[$serviceName]);
            $success = true;
            $this->log('[SERVER] Service "' . $serviceName . '" is now stopped');
        }

        return array(
            'service' => $this->notificationService,
            'success' => $success,
            'text'    => $message
        );
    }

    /**
     * List all the service name which are currently running
     *
     * @return     string[]  The services name list
     */
    private function listServices(): array
    {
        return array('service' => $this->websocketService, 'services' => array_keys($this->services));
    }

    /**
     * Check the authentication to perform administration action on the WebSocket server
     *
     * @param      array  $data   JSON decoded client data
     *
     * @return     bool   True if the authentication succeed else false
     */
    private function checkAuthentication(array $data): bool
    {
        $userEntityManager = new UserEntityManager();
        $user              = $userEntityManager->authenticateUser($data['login'], $data['password']);

        if ($user === false) {
            $check = false;
        } else {
            $check = (int) $user->getUserRights()->webSocket === 1;
        }

        return $check;
    }

    /**
     * Log a message to the server if verbose mode is activated
     *
     * @param      string  $message  The message to output
     */
    private function log(string $message)
    {
        if ($this->verbose) {
            static::out('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
        }
    }

    /*=====  End of Private methods  ======*/
}
