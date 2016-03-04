<?php

namespace classes\websocket;

use Icicle\Http\Message\Request;
use Icicle\Http\Message\Response;
use Icicle\Log\{Log, function log};
use Icicle\Socket\Socket;
use Icicle\WebSocket\Application as Application;
use Icicle\WebSocket\Connection;
// use Icicle\Concurrent\Sync\SharedMemoryParcel as SharedMemoryParcel; => Icicle\Concurrent\Sync\PosixSemaphore requires the sysvmsg extension
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
     * @var \Icicle\Log\Log
     */
    private $log;
    /**
     * @var $clients array The clients pool
     */
    private $clients;
    protected $clientShare;

    /**
     * @param \Icicle\Log\Log|null $log
     */
    public function __construct(Log $log = null)
    {
        $this->log = $log ?: log();
        $this->clients  = array();
        $this->clientShare = shmop_open(1, 'c', 0644, 2048);
        $this->services = array(
            'chatService' => new ChatService()
        );

        var_dump($this->clientShare);
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

        $this->clients[$this->getConnectionHash($connection)] = array('connection' => 'toto', 'user' => false);
        shmop_write($this->clientShare, serialize($this->clients), 0);
        yield $this->services['chatService']->testClassAttributeSync();
        $iterator                                             = $connection->read()->getIterator();

        // Add the user to the chat app or use a common base of users connected ...
        // test if service can share class access attributes with sync

        while (yield $iterator->isValid()) {
            /** @var \Icicle\WebSocket\Message $message */
            $message = $iterator->getCurrent();
            yield $this->serviceSelector(json_decode($message->getData(), true), $connection);
        }
        /** @var \Icicle\WebSocket\Close $close */
        $close = $iterator->getReturn(); // Only needs to be called if the close reason is needed.
        yield $this->log->log(
            Log::INFO,
            'WebSocket connection from %s:%d closed; Code %d; Data: %s',
            $connection->getRemoteAddress(),
            $connection->getRemotePort(),
            $close->getCode(),
            $close->getData()
        );
    }

    private function serviceSelector(array $data, Connection $connection)
    {
        yield $this->log->log(Log::INFO, 'Data: %s', $this->formatVariable($data));

        switch ($data['service']) {
            case 'server':
                $this->serverAction($data, $connection);
                break;

            case 'chatService':
                $this->services['chatService']->process($data, $connection);
                break;

            default:
        }
    }

    private function serverAction(array $data, Connection $connection)
    {
        switch ($data['action']) {
            case 'register':
                $this->clients[$this->getConnectionHash($connection)]['user'] = new User($data['user']);
                yield $this->log->log(Log::INFO, 'Data: %s', $this->formatVariable($this->clients));
                break;

            default:
        }
    }

    /**
     * Get the connection hash like a Connecton ID
     *
     * @param      Connection  $connection  The connection to get the hash from
     *
     * @return     string The connection hash
     */
    private function getConnectionHash(Connection $connection): string
    {
        return md5($connection->getRemoteAddress() + $connection->getRemotePort());
    }
}
