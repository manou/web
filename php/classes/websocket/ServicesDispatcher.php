<?php

namespace classes\websocket;

use Icicle\Http\Message\Request;
use Icicle\Http\Message\Response;
use Icicle\Log\{Log, function log};
use Icicle\Socket\Socket;
use Icicle\WebSocket\Application as Application;
use Icicle\WebSocket\Connection;
use Icicle\Stream\DuplexStream;
use Icicle\Stream\MemoryStream;
use classes\entities\User as User;
use classes\websocket\services\ChatService as ChatService;

class ServicesDispatcher implements Application
{
    use \traits\PrettyOutputTrait;

    /**
     * @var $services array The differents services
     */
    private $services;
    /**
     * @var $log \Icicle\Log\Log
     */
    private $log;
    /**
     * @var $clients array The clients pool
     */
    private $clients = array();
    /**
     * @var $steams MemoryStream[]
     */
    protected $steams = array();

    /**
     * @param \Icicle\Log\Log|null $log
     */
    public function __construct(Log $log = null)
    {
        $this->log                     = $log ?: log();
        $this->steams['chatService']   = new MemoryStream();
        $this->services['chatService'] = new ChatService($this->steams['chatService']);
    }

    /**
     * {@inheritdoc}
     */
    public function onHandshake(Response $response, Request $request, Socket $socket)
    {
        // Cookies may be set and returned on a new Response object, e.g.: return $response->withCookie(...);
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function onConnection(Connection $connection, Response $response, Request $request)
    {
        yield $this->log->log(
            Log::INFO,
            'WebSocket connection from %s:%d opened',
            $connection->getRemoteAddress(),
            $connection->getRemotePort()
        );

        $this->clients[$this->getConnectionHash($connection)] = array('Connection' => $connection, 'User' => null);
        $iterator                                             = $connection->read()->getIterator();

        while (yield $iterator->isValid()) {
            yield $this->serviceSelector(
                json_decode($iterator->getCurrent()->getData(), true),
                $this->clients[$this->getConnectionHash($connection)]
            );
        }

        yield $this->log->log(
            Log::INFO,
            'WebSocket connection from %s:%d closed; Code %d; Data: %s',
            $connection->getRemoteAddress(),
            $connection->getRemotePort(),
            $close->getCode(),
            $close->getData()
        );
    }

    /**
     * Get the connection hash like a Connecton ID
     *
     * @param      Connection  $connection  The connection to get the hash from
     *
     * @return     string The connection hash
     */
    protected function getConnectionHash(Connection $connection): string
    {
        return md5($connection->getRemoteAddress() + $connection->getRemotePort());
    }

    private function serviceSelector(array $data, array $user)
    {
        yield $this->log->log(Log::INFO, 'Data: %s', $this->formatVariable($data));

        switch ($data['service']) {
            case 'server':
                $this->serverAction($data, $user);
                break;

            case 'chatService':
                $this->services['chatService']->process($data, $user);
                break;
        }
    }

    private function serverAction(array $data, array $user)
    {
        switch ($data['action']) {
            case 'register':
                yield $this->log->log(Log::INFO, 'Data: %s', $this->formatVariable($this->clients));
                $this->clients[$this->getConnectionHash($user['Connection'])]['User'] = new User($data['user']);
                break;

            default:
        }
    }
}
