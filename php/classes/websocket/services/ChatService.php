<?php
/**
 * Chat services to manage a chat with a WebSocket server
 *
 * @category WebSocket service
 * @author   Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\websocket\services;

use \classes\websocket\Server as Server;
use \interfaces\ServiceInterface as Service;
use \classes\IniManager as Ini;
use \classes\entities\User as User;
use \classes\entities\UsersChatRights as UsersChatRights;
use \classes\entitiesManager\UserEntityManager as UserEntityManager;
use \classes\entitiesManager\UsersChatRightsEntityManager as UsersChatRightsEntityManager;

/**
 * Chat services to manage a chat with a WebSocket server
 *
 * @todo       Sonar this class
 */
class ChatService extends Server implements Service
{
    use \traits\ShortcutsTrait;

    /**
     * @var        string  $serverAddress   The server adress to connect
     */
    private $serverAddress;
    /**
     * @var        string  $chatService     The chat service name
     */
    private $chatService;
    /**
     * @var        string  $savingDir   The absolute path from the lib path where conversations will be stored
     */
    private $savingDir;
    /**
     * @var        integer  $maxMessagesPerFile     The maximum number of messages per file saved
     */
    private $maxMessagesPerFile;
    /**
     * @var        string[]  $roomsName     Array containing all the rooms name that exists
     */
    private $roomsName;
    /**
     * @var        string  $roomsNamePath   The path of the file storing the list of rooms name
     */
    private $roomsNamePath;
    /**
     * @var        string[]  $usersRooms    All rooms where users are connected to indexed by their socketHash
     */
    private $usersRooms = array();
    /**
     * @var array $rooms Rooms live sessions
     *
     * array(
     *     'room name' => array(
     *         'sockets'      => array(socketHash1 => socket, socketHash2 => socket, ...),
     *         'pseudonyms'   => array(socketHash1 => pseudonym1, socketHash2 => pseudonym2, ...)
     *         'usersRights'  => array(pseudonym1 => UsersChatRights, pseudonym2 => UsersChatRights, ...)
     *         'creator'      => User,
     *         'type'         => 'public' || 'private',
     *         'password'     => 'password',
     *         'creationDate' => DateTime,
     *         'maxUsers'     => integer,
     *         'usersBanned'  => array(
     *             'ip'        => ipAddress,
     *             'pseudonym' => the banned user pseudonym,
     *             'admin'     => the admin pseudonym who banned the user,
     *             'reason'    => the reason of the ban,
     *             'date'      => the banned timestamp
     *         ),
     *         'historic'     => array(
     *             'part'          => the part number,
     *             'conversations' => array(
     *                 'text' => the text message,
     *                 'time' => the message sent time,
     *                 'from' => the pseudonym of the message owner,
     *                 'to'   => the pseudonym of the message reciever or 'all'
     *             )
     *         )
     *     )
     * )
     */
    private $rooms = array();

    /*=====================================
    =            Magic methods            =
    =====================================*/

    /**
     * Constructor that sets the WebSocket server adress and create en empty default room
     *
     * @param      string  $serverAddress  The WebSocket server adress
     */
    public function __construct(string $serverAddress)
    {
        Ini::setIniFileName(Ini::INI_CONF_FILE);
        $params                   = Ini::getSectionParams('Chat service');
        $this->serverKey          = Ini::getParam('Socket', 'serverKey');
        $this->serverAddress      = $serverAddress;
        $this->chatService        = $params['serviceName'];
        $this->savingDir          = $params['savingDir'];
        $this->maxMessagesPerFile = $params['maxMessagesPerFile'];
        $this->roomsNamePath      = $this->savingDir . DIRECTORY_SEPARATOR . 'rooms_name';
        $this->roomsName          = $this->getRoomsName();

        // Create the default room
        $this->rooms['default'] = array(
            'sockets'      => array(),
            'pseudonyms'   => array(),
            'usersRights'  => array(),
            'type'         => 'public',
            'password'     => '',
            'creationDate' => new \DateTime(),
            'maxUsers'     => $params['maxUsers'],
            'usersBanned'  => array(),
            'historic'     => array('part' => 0, 'conversations' => array())
        );

        $this->loadHistoric('default', $this->getLastPartNumber('default'));
    }

    /*=====  End of Magic methods  ======*/

    /*======================================
    =            Public methods            =
    ======================================*/

    /**
     * Method to recieves data from the WebSocket server
     *
     * @param      resource  $socket  The client socket
     * @param      array     $data    JSON decoded client data
     */
    public function service($socket, array $data)
    {
        switch ($data['action']) {
            case $this->serverKey . 'disconnect':
                // Action called by the server
                $this->disconnectUser($data['clientSocket']);

                break;

            case 'sendMessage':
                $this->sendMessage($socket, $data);

                break;

            case 'connectRoom':
                $this->connectUser($socket, $data);

                break;

            case 'disconnect':
                $this->disconnectUser($socket);

                break;

            case 'disconnectFromRoom':
                $this->disconnectUserFromRoom($socket, $data);

                break;

            case 'createRoom':
                $this->createRoom($socket, $data);

                break;

            case 'getHistoric':
                $this->getHistoric($socket, $data);

                break;

            case 'kickUser':
                $this->kickUser($socket, $data);

                break;

            case 'banUser':
                $this->banUser($socket, $data);

                break;

            case 'updateRoomUserRight':
                $this->updateRoomUserRight($socket, $data);

                break;

            case 'setRoomInfo':
                $this->setRoomInfo($socket, $data);

                break;

            case 'getRoomsInfo':
                $this->getRoomsInfo($socket, $data);

                break;

            default:
                $this->send(
                    $socket,
                    $this->encode(
                        json_encode(
                            array(
                            'service' => $this->chatService,
                            'success' => false,
                            'text'    => _('Unknown action')
                            )
                        )
                    )
                );
        }
    }

    /*=====  End of Public methods  ======*/

    /*=======================================
    =            Private methods            =
    =======================================*/

    /**
     * Create a chat room by an authenticated user request
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function createRoom($socket, array $data)
    {
        $success = false;
        @$this->setIfIsSet($password, $data['password'], null);
        @$this->setIfIsSet($roomPassword, $data['roomPassword'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($login, $data['login'], null);
        @$this->setIfIsSetAndTrim($type, $data['type'], null);
        @$this->setIfIsSetAndTrim($maxUsers, $data['maxUsers'], null);

        if ($roomName === null || $roomName === '') {
            $message = _('The room name is required');
        } elseif (in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room name "%s" already exists'), $roomName);
        } elseif ($type !== 'public' && $type !== 'private') {
            $message = _('The room type must be "public" or "private"');
        } elseif ($type === 'private' && ($password === null || strlen($password) === 0)) {
            $message = _('The password is required and must not be empty');
        } elseif (!is_numeric($maxUsers) || $maxUsers < 2) {
            $message = _('The max number of users must be a number and must no be less than 2');
        } else {
            $userEntityManager = new UserEntityManager();
            $user              = $userEntityManager->authenticateUser($login, $password);

            if ($user === false) {
                $message = _('Authentication failed');
            } else {
                $usersChatRights              = new UsersChatRights();
                $usersChatRightsEntityManager = new UsersChatRightsEntityManager($usersChatRights);
                $usersChatRights->idUser      = $user->id;
                $usersChatRights->roomName    = $roomName;
                $usersChatRightsEntityManager->addRoomName($roomName);
                $usersChatRightsEntityManager->grantAll();

                $socketHash             = $this->getClientName($socket);
                $pseudonym              = $userEntityManager->getPseudonymForChat();
                $this->roomsName[]      = $roomName;
                $this->rooms[$roomName] = array(
                    'sockets'      => array($socketHash => $socket),
                    'pseudonyms'   => array($socketHash => $pseudonym),
                    'usersRights'  => array(),
                    'creator'      => $user->email,
                    'type'         => $type,
                    'password'     => $roomPassword,
                    'creationDate' => new \DateTime(),
                    'maxUsers'     => $maxUsers,
                    'usersBanned'  => array(),
                    'historic'     => array('part' => 0, 'conversations' => array())
                );

                mkdir(stream_resolve_include_path($this->savingDir) . DIRECTORY_SEPARATOR . $roomName);
                $this->addUserRoom($socketHash, $roomName);
                $this->setRoomsName();
                $this->setLastPartNumber($roomName, 0);
                $this->saveRoom($roomName);

                $success = true;
                $message = sprintf(_('The chat room name "%s" is successfully created !'), $roomName);
                $this->log(
                    sprintf(
                        _('[chatService] New room added "%s" (%s) maxUsers = %s and password = "%s" by %s'),
                        $roomName,
                        $type,
                        $maxUsers,
                        $roomPassword,
                        $pseudonym
                    )
                );
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'createRoom',
                    'success'  => $success,
                    'roomName' => $roomName,
                    'type'     => $type,
                    'maxUsers' => $maxUsers,
                    'password' => $roomPassword,
                    'text'     => $message
                    )
                )
            )
        );
    }

    /**
     * Connect a user to one chat room as a registered or a guest user
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function connectUser($socket, array $data)
    {
        $success  = false;
        $user     = false;
        $response = array();
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSet($roomPassword, $data['password'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($pseudonym, $data['pseudonym'], null);

        if ($roomName === null) {
            $message = _('The chat room name cannot be empty');
        } elseif (!in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room "%s" does not exist'), $roomName);
        } else {
            if (!isset($this->rooms[$roomName])) {
                // Load the room if it is not in cache
                $this->loadRoom($roomName);
            }

            if (count($this->rooms[$roomName]['sockets']) >= $this->rooms[$roomName]['maxUsers']) {
                $message = _('The room is full');
            } elseif (!$this->checkPrivateRoomPassword($roomName, $roomPassword)) {
                $message = _('You cannot access to this room or the password is incorrect');
            } elseif ($this->inSubArray($this->getClientIp($socket), $this->rooms[$roomName]['usersBanned'], 'ip')) {
                $message = _('You are banned from this room');
            } elseif ($email !== null && $password !== null) {
                // Authenticated user
                $userEntityManager = new UserEntityManager();
                $user              = $userEntityManager->authenticateUser($email, $password);

                if ($user !== false) {
                    $usersChatRightsEntityManager = new UsersChatRightsEntityManager();
                    $usersChatRightsEntityManager->loadEntity(array('idUser' => $user->id, 'roomName' => $roomName));
                    $success                                           = true;
                    $pseudonym                                         = $userEntityManager->getPseudonymForChat();
                    $this->rooms[$roomName]['usersRights'][$pseudonym] = $usersChatRightsEntityManager->getEntity()
                        ->__toArray();
                } else {
                    $message = _('The authentication failed');
                }
            } elseif ($pseudonym !== null && $pseudonym !== '') {
                // Guest user
                if ($this->isPseudonymUsabled($pseudonym, $roomName)) {
                    $success = true;
                } else {
                    $message = sprintf(_('The pseudonym "%s" is already used'), $pseudonym);
                }
            } else {
                $message = _('The pseudonym can\'t be empty');
            }

            if ($success) {
                // Add user to the room
                $socketHash                                        = $this->getClientName($socket);
                $this->rooms[$roomName]['sockets'][$socketHash]    = $socket;
                $this->rooms[$roomName]['pseudonyms'][$socketHash] = $pseudonym;

                $this->addUserRoom($socketHash, $roomName);

                $this->log(sprintf(_('[chatService] New user "%s" added in the room "%s"'), $pseudonym, $roomName));

                $message                = sprintf(_('You\'re connected to the chat room "%s" !'), $roomName);
                $response['roomName']   = $roomName;
                $response['type']       = $this->rooms[$roomName]['type'];
                $response['pseudonym']  = $pseudonym;
                $response['maxUsers']   = $this->rooms[$roomName]['maxUsers'];
                $response['password']   = $this->rooms[$roomName]['password'];
                $response['pseudonyms'] = array_values($this->rooms[$roomName]['pseudonyms']);
                $response['historic']   = $this->filterConversations(
                    $this->rooms[$roomName]['historic']['conversations'],
                    $pseudonym
                );

                if ($user !== false) {
                    $response['usersRights'] = $this->rooms[$roomName]['usersRights'];
                    $response['usersBanned'] = $this->rooms[$roomName]['usersBanned'];
                }
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array_merge(
                        $response,
                        array(
                        'service' => $this->chatService,
                        'action'  => 'connectRoom',
                        'success' => $success,
                        'text'    => $message
                        )
                    )
                )
            )
        );
    }

    /**
     * Send a public message to all the users in the room or a private message to one user in the room
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function sendMessage($socket, array $data)
    {
        $success    = false;
        $message    = _('Message successfully sent !');
        $socketHash = $this->getClientName($socket);
        $response   = array();
        @$this->setIfIsSet($password, $data['password'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($recievers, $data['recievers'], null);
        @$this->setIfIsSetAndTrim($text, $data['message'], null);

        if ($text === null || $text === '') {
            $message = _('The message cannot be empty');
        } elseif ($roomName === null) {
            $message = _('The chat room name cannot be empty');
        } elseif ($this->rooms[$roomName]['type'] === 'private' && $password !== $this->rooms[$roomName]['password']) {
            $message = _('Incorrect password');
        } elseif (!array_key_exists($socketHash, $this->rooms[$roomName]['sockets'])) {
            $message = sprintf(_('You are not connected to the room %s'), $roomName);
        } elseif ($recievers === null) {
            $message = _('You must precise a reciever for your message (all or a pseudonym)');
        } elseif ($recievers !== 'all' && !$this->pseudonymIsInRoom($recievers, $roomName)) {
            $message = sprintf(_('The user "%" is not connected to the room "%"'), $recievers, $roomName);
        } else {
            $now       = date('Y-m-d H:i:s');
            $pseudonym = $this->rooms[$roomName]['pseudonyms'][$socketHash];

            if ($recievers === 'all') {
                // Send the message to all the users in the chat room
                foreach ($this->rooms[$roomName]['sockets'] as $userSocket) {
                    $this->sendMessageToUser($socket, $userSocket, $text, $roomName, 'public', $now);
                }
            } else {
                // Send the message to one user
                $recieverHash        = $this->getUserHashByPseudonym($roomName, $recievers);
                $recieverSocket      = $this->rooms[$roomName]['sockets'][$recieverHash];
                $response['message'] = $text;
                $response['type']    = 'private';

                $this->sendMessageToUser($socket, $recieverSocket, $text, $roomName, 'private', $now);
                $this->sendMessageToUser($socket, $socket, $text, $roomName, 'private', $now);
            }

            $this->log(
                sprintf(
                    _('[chatService] Message "%s" sent by "%s" to "%s" in the room "%s"'),
                    $text,
                    $pseudonym,
                    $recievers,
                    $roomName
                )
            );

            $this->updateHistoric($roomName, $now, $text, $pseudonym, $recievers);
            $success = true;
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array_merge(
                        $response,
                        array(
                        'service' => $this->chatService,
                        'action'  => 'sendMessage',
                        'success' => $success,
                        'text'    => $message
                        )
                    )
                )
            )
        );
    }

    /**
     * Get the next chat conversation historic part of a room
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function getHistoric($socket, array $data)
    {
        $success  = false;
        $message  = _('Historic successfully loaded !');
        $historic = array();
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], '');
        @$this->setIfIsSetAndTrim($password, $data['roomPassword'], '');
        @$this->setIfIsSetAndTrim($part, $data['historicLoaded'], '');

        if ($roomName === '') {
            $message = _('The room name is required');
        } elseif (!in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room name "%s" does not exists'), $roomName);
        } elseif ($this->rooms[$roomName]['type'] === 'private' && $this->rooms[$roomName]['password'] !== $password) {
            $message = _('You cannot access to this room or the password is incorrect');
        } elseif (!is_numeric($part)) {
            $message = _('The part must be numeric');
        } else {
            $success  = true;
            $lastPart = $this->getLastPartNumber($roomName);

            if ($lastPart < $part) {
                $message = _('There is no more conversation historic for this chat');
            } else {
                $historic = $this->filterConversations(
                    $this->getHistoricPart($roomName, $lastPart - $part)['conversations'],
                    $this->rooms[$roomName]['pseudonyms'][$this->getClientName($socket)]
                );
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'getHistoric',
                    'success'  => $success,
                    'text'     => $message,
                    'historic' => $historic,
                    'roomName' => $roomName
                    )
                )
            )
        );
    }

    /**
     * Kick a user from a room
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function kickUser($socket, array $data)
    {
        $success = false;
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($pseudonym, $data['pseudonym'], null);
        @$this->setIfIsSetAndTrim($reason, $data['reason'], '');

        $userEntityManager = new UserEntityManager();
        $user              = $userEntityManager->authenticateUser($email, $password);

        if ($user === false) {
            $message = _('Authentication failed');
        } else {
            $usersChatRightsEntityManager = new UsersChatRightsEntityManager();
            $usersChatRightsEntityManager->loadEntity(array('idUser' => $user->id, 'roomName' => $roomName));

            if ($user->getUserRights()->chatAdmin || $usersChatRightsEntityManager->getEntity()->kick === 1) {
                $userHash = $this->getUserHashByPseudonym($roomName, $pseudonym);

                if ($userHash !== false) {
                    if ($reason !== '') {
                        $reason = sprintf(_(' because %s'), $reason);
                    }

                    $success        = true;
                    $message        = sprintf(_('You kicked "%s" from the room "%s"'), $pseudonym, $roomName) . $reason;
                    $adminPseudonym = $this->rooms[$roomName]['pseudonyms'][$this->getClientName($socket)];

                    $this->send(
                        $this->rooms[$roomName]['sockets'][$userHash],
                        $this->encode(
                            json_encode(
                                array(
                                'service'  => $this->chatService,
                                'action'   => 'getKicked',
                                'text'     => sprintf(_('You got kicked from the room by "%s"'), $adminPseudonym) . $reason,
                                'roomName' => $roomName
                                )
                            )
                        )
                    );

                    $this->disconnectUser($this->rooms[$roomName]['sockets'][$userHash]);

                    foreach ($this->rooms[$roomName]['sockets'] as $userSocket) {
                        $this->sendMessageToUser(
                            $this->server,
                            $userSocket,
                            sprintf(_('The user "%s" got kicked by "%s"'), $pseudonym, $adminPseudonym) . $reason,
                            $roomName,
                            'public',
                            date('Y-m-d H:i:s')
                        );
                    }
                } else {
                    $message = sprintf(_('The user "%s" cannot be found in the room "%s"'), $pseudonym, $roomName);
                }
            } else {
                $message = _('You do not have the right to kick a user on this room');
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'kickUser',
                    'success'  => $success,
                    'text'     => $message,
                    'roomName' => $roomName
                    )
                )
            )
        );
    }

    /**
     * Ban a user from a room
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function banUser($socket, array $data)
    {
        $success = false;
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($pseudonym, $data['pseudonym'], null);
        @$this->setIfIsSetAndTrim($reasonInput, $data['reason'], '');

        $userEntityManager = new UserEntityManager();
        $user              = $userEntityManager->authenticateUser($email, $password);

        if ($user === false) {
            $message = _('Authentication failed');
        } else {
            $usersChatRightsEntityManager = new UsersChatRightsEntityManager();
            $usersChatRightsEntityManager->loadEntity(array('idUser' => $user->id, 'roomName' => $roomName));

            if ($user->getUserRights()->chatAdmin || $usersChatRightsEntityManager->getEntity()->ban === 1) {
                $userHash = $this->getUserHashByPseudonym($roomName, $pseudonym);

                if ($userHash !== false) {
                    if ($reasonInput !== '') {
                        $reason = sprintf(_(' for the reason %s'), $reasonInput);
                    } else {
                        $reason = '';
                    }

                    $success        = true;
                    $message        = sprintf(_('You banned "%s" from the room "%s"'), $pseudonym, $roomName) . $reason;
                    $adminPseudonym = $this->rooms[$roomName]['pseudonyms'][$this->getClientName($socket)];
                    $userSocket     = $this->rooms[$roomName]['sockets'][$userHash];
                    $banInfo        = array(
                        'ip'        => $this->getClientIp($userSocket),
                        'pseudonym' => $pseudonym,
                        'admin'     => $adminPseudonym,
                        'reason'    => $reasonInput,
                        'date'      => date('Y-m-d H:i:s')
                    );

                    $this->send(
                        $userSocket,
                        $this->encode(
                            json_encode(
                                array(
                                'service'  => $this->chatService,
                                'action'   => 'getBanned',
                                'text'     => sprintf(_('You got banned from the room by "%s"'), $adminPseudonym) . $reason,
                                'roomName' => $roomName
                                )
                            )
                        )
                    );

                    $this->rooms[$roomName]['usersBanned'][] = $banInfo;
                    $this->disconnectUser($userSocket);

                    foreach ($this->rooms[$roomName]['sockets'] as $userSocket) {
                        // Update all admin users panel
                        if ($this->isRegistered(
                            $roomName,
                            $this->rooms[$roomName]['pseudonyms'][$this->getClientName($userSocket)]
                        )) {
                            $this->updateRoomUsersBanned($userSocket, $roomName);
                        }

                        $this->sendMessageToUser(
                            $this->server,
                            $userSocket,
                            sprintf(_('The user "%s" got banned by "%s"'), $pseudonym, $adminPseudonym) . $reason,
                            $roomName,
                            'public',
                            date('Y-m-d H:i:s')
                        );
                    }
                } else {
                    $message = sprintf(_('The user "%s" cannot be found in the room "%s"'), $pseudonym, $roomName);
                }
            } else {
                $message = _('You do not have the right to ban a user on this room');
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'banUser',
                    'success'  => $success,
                    'text'     => $message,
                    'roomName' => $roomName
                    )
                )
            )
        );
    }

    /**
     * Update a user right for a room
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function updateRoomUserRight($socket, array $data)
    {
        $success = false;
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($pseudonym, $data['pseudonym'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], '');
        @$this->setIfIsSetAndTrim($rightName, $data['rightName'], '');
        @$this->setIfIsSetAndTrim($rightValue, $data['rightValue'], '');

        $userEntityManager = new UserEntityManager();
        $user              = $userEntityManager->authenticateUser($email, $password);

        if ($roomName === '') {
            $message = _('The room name is required');
        } elseif (!in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room name "%s" does not exists'), $roomName);
        } elseif ($user === false) {
            $message = _('Authentication failed');
        } else {
            $usersChatRightsEntityManager = new UsersChatRightsEntityManager();
            $usersChatRightsEntityManager->loadEntity(array('idUser' => $user->id, 'roomName' => $roomName));

            if ($user->getUserRights()->chatAdmin || $usersChatRightsEntityManager->getEntity()->grant === 1) {
                $usersChatRightsEntityManager->loadEntity(
                    array(
                    'idUser'   => $userEntityManager->getUserIdByPseudonym($pseudonym),
                    'roomName' => $roomName
                    )
                );

                $usersChatRightsEntityManager->getEntity()->{$rightName} = (int) $rightValue;
                $usersChatRightsEntityManager->saveEntity();
                $messageForUsers = sprintf(
                    _('The user %s has now %s the right to %s in the room %s'),
                    $pseudonym,
                    ($rightValue ? '' : _('not')),
                    $rightName,
                    $roomName
                );

                // Update all others admin users panel
                foreach ($this->rooms[$roomName]['sockets'] as $userSocket) {
                    if ($userSocket !== $socket && $this->isRegistered($roomName, $pseudonym)) {
                        $this->updateRoomUsersRights($userSocket, $roomName);
                    }
                }

                $this->sendMessageToRoom($this->server, $messageForUsers, $roomName, 'public', date('Y-m-d H:i:s'));
                $success = true;
                $message = _('User right successfully updated');
            } else {
                $message = _('You do not have the right to grant a user right on this room');
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'updateRoomUserRight',
                    'success'  => $success,
                    'text'     => $message,
                    'roomName' => $roomName
                    )
                )
            )
        );
    }

    /**
     * Change a room name / password
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function setRoomInfo($socket, array $data)
    {
        $success        = false;
        $messageToUsers = array();
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($oldRoomName, $data['oldRoomName'], '');
        @$this->setIfIsSetAndTrim($newRoomName, $data['newRoomName'], '');
        @$this->setIfIsSetAndTrim($oldRoomPassword, $data['oldRoomPassword'], '');
        @$this->setIfIsSetAndTrim($newRoomPassword, $data['newRoomPassword'], '');

        $userEntityManager = new UserEntityManager();
        $user              = $userEntityManager->authenticateUser($email, $password);

        if ($oldRoomName === '') {
            $message[] = _('The room name is required');
        } elseif ($newRoomName === '') {
            $message[] = _('The new room name is required');
        } elseif (!in_array($oldRoomName, $this->roomsName)) {
            $message[] = sprintf(_('The chat room name "%s" does not exists'), $oldRoomName);
        } elseif ($oldRoomName !== $newRoomName && in_array($newRoomName, $this->roomsName)) {
            $message[] = sprintf(_('The chat room name "%s" already exists'), $newRoomName);
        } elseif ($user === false) {
            $message[] = _('Authentication failed');
        } else {
            $usersChatRightsEntityManager = new UsersChatRightsEntityManager();
            $usersChatRightsEntityManager->loadEntity(array('idUser' => $user->id, 'roomName' => $oldRoomName));

            if ($oldRoomPassword !== $newRoomPassword) {
                if ($user->getUserRights()->chatAdmin || $usersChatRightsEntityManager->getEntity()->password === 1) {
                    $success                               = true;
                    $this->rooms[$oldRoomName]['password'] = $newRoomPassword;
                    $message[]                             = _('The room password has been successfully updated');
                    $messageToUsers[]                      = sprintf(
                        _('The room password has been updated from "%s" to "%s"'),
                        $oldRoomPassword,
                        $newRoomPassword
                    );

                    if ($newRoomPassword === '') {
                        $this->rooms[$oldRoomName]['type'] = 'public';
                    } else {
                        $this->rooms[$oldRoomName]['type'] = 'private';
                    }
                } else {
                    $message[] = _('You do not have the right to change the room password');
                }
            }

            if ($oldRoomName !== $newRoomName) {
                if ($user->getUserRights()->chatAdmin || $usersChatRightsEntityManager->getEntity()->rename === 1) {
                    $success                   = true;
                    $this->rooms[$newRoomName] = $this->rooms[$oldRoomName];
                    $message[]                 = _('The room name has been successfully updated');
                    unset($this->rooms[$oldRoomName]);
                    $this->roomsName[array_search($oldRoomName, $this->roomsName)] = $newRoomName;

                    foreach ($this->usersRooms as &$roomName) {
                        if ($roomName === $oldRoomName) {
                            $roomName = $newRoomName;
                        }
                    }

                    rename(
                        stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $oldRoomName),
                        stream_resolve_include_path($this->savingDir) . DIRECTORY_SEPARATOR . $newRoomName
                    );

                    $usersChatRightsEntityManager->changeRoomName($oldRoomName, $newRoomName);

                    $this->setRoomsName();

                    $messageToUsers[] = sprintf(
                        _('The room name has been updated from "%s" to "%s"'),
                        $oldRoomName,
                        $newRoomName
                    );
                } else {
                    $message[] = _('You do not have the right to change the room name');
                }
            }

            $this->saveRoom($newRoomName);

            foreach ($this->rooms[$newRoomName]['sockets'] as $userSocket) {
                $this->send(
                    $userSocket,
                    $this->encode(
                        json_encode(
                            array(
                            'service'         => $this->chatService,
                            'action'          => 'changeRoomInfo',
                            'oldRoomName'     => $oldRoomName,
                            'newRoomName'     => $newRoomName,
                            'oldRoomPassword' => $oldRoomPassword,
                            'newRoomPassword' => $newRoomPassword,
                            'pseudonym'       => 'SERVER',
                            'time'            => date('Y-m-d H:i:s'),
                            'roomName'        => $newRoomName,
                            'type'            => 'public',
                            'text'            => $messageToUsers
                            )
                        )
                    )
                );
            }
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'         => $this->chatService,
                    'action'          => 'setRoomInfo',
                    'success'         => $success,
                    'text'            => implode('. ', $message),
                    'oldRoomName'     => $oldRoomName,
                    'newRoomName'     => $newRoomName,
                    'oldRoomPassword' => $oldRoomPassword,
                    'newRoomPassword' => $newRoomPassword
                    )
                )
            )
        );
    }

    /**
     * Get the rooms basic information (name, type, usersMax, usersConnected)
     *
     * @param      resource  $socket  The user socket
     */
    private function getRoomsInfo($socket)
    {
        $roomsInfo = array();

        foreach ($this->roomsName as $roomName) {
            if (isset($this->rooms[$roomName])) {
                $roomInfo       = $this->rooms[$roomName];
                $usersConnected = count($this->rooms[$roomName]['sockets']);
            } else {
                $roomInfo       = $this->getRoomInfo($roomName);
                $usersConnected = 0;
            }

            $roomsInfo[] = array(
                'name'           => $roomName,
                'type'           => $roomInfo['type'],
                'maxUsers'       => $roomInfo['maxUsers'],
                'usersConnected' => $usersConnected
            );
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'   => $this->chatService,
                    'action'    => 'getRoomsInfo',
                    'roomsInfo' => $roomsInfo
                    )
                )
            )
        );
    }

    /**
     * Update the connected users list in a room
     *
     * @param      resource  $socket    The user socket
     * @param      string    $roomName  The room name
     */
    private function updateRoomUsers($socket, string $roomName)
    {
        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'    => $this->chatService,
                    'action'     => 'updateRoomUsers',
                    'roomName'   => $roomName,
                    'pseudonyms' => array_values($this->rooms[$roomName]['pseudonyms'])
                    )
                )
            )
        );
    }

    /**
     * Update the connected users rights list in a room
     *
     * @param      resource  $socket    The user socket
     * @param      string    $roomName  The room name
     */
    private function updateRoomUsersRights($socket, string $roomName)
    {
        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'     => $this->chatService,
                    'action'      => 'updateRoomUsersRights',
                    'roomName'    => $roomName,
                    'usersRights' => $this->rooms[$roomName]['usersRights']
                    )
                )
            )
        );
    }

    /**
     * Update the ip banned list in a room
     *
     * @param      resource  $socket    The user socket
     * @param      string    $roomName  The room name
     */
    private function updateRoomUsersBanned($socket, string $roomName)
    {
        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'     => $this->chatService,
                    'action'      => 'updateRoomUsersBanned',
                    'roomName'    => $roomName,
                    'usersBanned' => $this->rooms[$roomName]['usersBanned']
                    )
                )
            )
        );
    }

    /**
     * Disconnect a user from a room
     *
     * @param      string  $socketHash  The user socket hash
     * @param      string  $roomName    The room name
     */
    private function disconnectUserFromRoomAction(string $socketHash, string $roomName)
    {
        $pseudonym = $this->rooms[$roomName]['pseudonyms'][$socketHash];

        unset($this->rooms[$roomName]['sockets'][$socketHash]);
        unset($this->rooms[$roomName]['pseudonyms'][$socketHash]);

        // Save and close the chat room if noone is in
        if (count($this->rooms[$roomName]['sockets']) === 0) {
            $this->saveHistoric($roomName);
            unset($this->rooms[$roomName]);
        } else {
            foreach ($this->rooms[$roomName]['sockets'] as $socket) {
                if ($this->isRegistered($roomName, $pseudonym)) {
                    unset($this->rooms[$roomName]['usersRights'][$pseudonym]);
                    $this->updateRoomUsersRights($socket, $roomName);
                }

                $this->updateRoomUsers($socket, $roomName);
                $this->sendMessageToUser(
                    $this->server,
                    $socket,
                    sprintf(_('User "%s" disconnected'), $pseudonym),
                    $roomName,
                    'public',
                    date('Y-m-d H:i:s')
                );
            }
        }
    }

    /**
     * Disconnet a user from all the chat he was connected to
     *
     * @param      resource  $socket  The user socket
     */
    private function disconnectUser($socket)
    {
        $socketHash = $this->getClientName($socket);

        if (isset($this->usersRooms[$socketHash])) {
            foreach ($this->usersRooms[$socketHash] as $roomName) {
                $this->disconnectUserFromRoomAction($socketHash, $roomName);
            }
        }
    }

    /**
     * Disconnet a user from a room he was connected to
     *
     * @param      resource  $socket  The user socket
     * @param      array     $data    JSON decoded client data
     */
    private function disconnectUserFromRoom($socket, array $data)
    {
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        $socketHash = $this->getClientName($socket);
        $success    = false;

        if (!isset($this->usersRooms[$socketHash])) {
            $message = _('An error occured');
        } elseif (!in_array($roomName, $this->usersRooms[$socketHash])) {
            $message = sprintf(_('You are not connected to the room %s'), $roomName);
        } else {
            $this->disconnectUserFromRoomAction($socketHash, $roomName);
            $message = sprintf(_('You are disconnected from the room %s'), $roomName);
            $success = true;
        }

        $this->send(
            $socket,
            $this->encode(
                json_encode(
                    array(
                    'service'  => $this->chatService,
                    'action'   => 'disconnectFromRoom',
                    'success'  => $success,
                    'text'     => $message,
                    'roomName' => $roomName
                    )
                )
            )
        );
    }

    /**
     * Add a room to the user when he is connected to this room
     *
     * @param      string  $socketHash  The user socket hash
     * @param      string  $roomName    The room name
     */
    private function addUserRoom(string $socketHash, string $roomName)
    {
        if (!isset($this->usersRooms[$socketHash])) {
            $this->usersRooms[$socketHash] = array();
        }

        $this->usersRooms[$socketHash][] = $roomName;
        $pseudonym                       = $this->rooms[$roomName]['pseudonyms'][$socketHash];

        foreach ($this->rooms[$roomName]['sockets'] as $socket) {
            if ($this->isRegistered($roomName, $pseudonym)) {
                $this->updateRoomUsersRights($socket, $roomName);
            }

            $this->updateRoomUsers($socket, $roomName);
            $this->sendMessageToUser(
                $this->server,
                $socket,
                sprintf(_('User "%s" connected'), $pseudonym),
                $roomName,
                'public',
                date('Y-m-d H:i:s')
            );
        }
    }

    /**
     * Check if a user has the right to enter a private room
     *
     * @param      string  $roomName      The room name
     * @param      string  $roomPassword  The room password the user sent
     *
     * @return     bool    True if the user have the right to enter the room else false
     */
    private function checkPrivateRoomPassword(string $roomName, string $roomPassword): bool
    {
        if ($this->rooms[$roomName]['type'] === 'private' && $this->rooms[$roomName]['password'] !== $roomPassword) {
            $authorized = false;
        } else {
            $authorized = true;
        }

        return $authorized;
    }

    /**
     * Check if a pseudonym is already used in the defined room
     *
     * @param      string  $pseudonym  The pseudonym
     * @param      string  $roomName   The room name to connect to
     *
     * @return     bool    True if the pseudonym exists in the room else false
     */
    private function pseudonymIsInRoom(string $pseudonym, string $roomName): bool
    {
        return in_array($pseudonym, $this->rooms[$roomName]['pseudonyms']);
    }

    /**
     * Check if a pseudonym is usabled to be used in a room (not already in the room and not used by a registered user)
     *
     * @param      string  $pseudonym  The pseudonym
     * @param      string  $roomName   The room name to connect to
     *
     * @return     bool    True if the pseudonym is usabled else false
     */
    private function isPseudonymUsabled(string $pseudonym, string $roomName): bool
    {
        $isUsabled = !$this->pseudonymIsInRoom($pseudonym, $roomName);

        if ($isUsabled) {
            $userEntityManager = new UserEntityManager();
            $isUsabled         = !$userEntityManager->isPseudonymExist($pseudonym);
        }

        return $isUsabled;
    }

    /**
     * Get the user hash by his pseudonym and the room name where he's connected
     *
     * @param      string       $roomName   The room name
     * @param      string       $pseudonym  The user pseudonym
     *
     * @return     string|bool  The user hash or false if an the user cannot be found
     */
    private function getUserHashByPseudonym(string $roomName, string $pseudonym)
    {
        return array_search($pseudonym, $this->rooms[$roomName]['pseudonyms']);
    }

    /**
     * Tell if a user is registered or not
     *
     * @param      string  $roomName   The room name
     * @param      string  $pseudonym  The user pseudonym
     *
     * @return     bool    True if the user is registered else false
     */
    private function isRegistered(string $roomName, string $pseudonym): bool
    {
        return array_key_exists($pseudonym, $this->rooms[$roomName]['usersRights']);
    }

    /**
     * Send a message to a user
     *
     * @param      resource  $socketFrom  The user socket to send the message from
     * @param      resource  $socketTo    The user socket to send the message to
     * @param      string    $message     The text message
     * @param      string    $roomName    The room name
     * @param      string    $type        The message type ('public' || 'private')
     * @param      string    $date        The server date at the moment the message was processed (Y-m-d H:i:s)
     */
    private function sendMessageToUser(
        $socketFrom,
        $socketTo,
        string $message,
        string $roomName,
        string $type,
        string $date
    ) {
        if ($socketFrom === $this->server) {
            $pseudonym = 'SERVER';
        } else {
            $pseudonym = $this->rooms[$roomName]['pseudonyms'][$this->getClientName($socketFrom)];
        }

        $this->send(
            $socketTo,
            $this->encode(
                json_encode(
                    array(
                    'service'   => $this->chatService,
                    'action'    => 'recieveMessage',
                    'pseudonym' => $pseudonym,
                    'time'      => $date,
                    'roomName'  => $roomName,
                    'type'      => $type,
                    'text'      => $message
                    )
                )
            )
        );
    }

    /**
     * Send a message to all the room users
     *
     * @param      resource  $socketFrom  The user socket to send the message from
     * @param      string    $message     The text message
     * @param      string    $roomName    The room name
     * @param      string    $type        The message type ('public' || 'private')
     * @param      string    $date        The server date at the moment the message was processed (Y-m-d H:i:s)
     */
    private function sendMessageToRoom(
        $socketFrom,
        string $message,
        string $roomName,
        string $type,
        string $date
    ) {
        foreach ($this->rooms[$roomName]['sockets'] as $socketTo) {
            $this->sendMessageToUser($socketFrom, $socketTo, $message, $roomName, $type, $date);
        }
    }

    /**
     * Filter conversations to delete private message which must not be viewed by the user and parse the content
     *
     * @param      array   $conversations  The conversations
     * @param      string  $pseudonym      The user pseudonym
     *
     * @return     array   The filtered conversations
     */
    private function filterConversations(array $conversations, string $pseudonym): array
    {
        $filteredConversations = array();

        if (count($conversations) > 0) {
            foreach ($conversations as $conversation) {
                $filteredConversation['pseudonym'] = $conversation['from'];
                $filteredConversation['time']      = $conversation['time'];
                $filteredConversation['text']      = $conversation['text'];

                if ($conversation['to'] !== 'all') {
                    if ($conversation['from'] === $pseudonym) {
                        $filteredConversation['type'] = 'private';
                        $filteredConversations[]      = $filteredConversation;
                    }
                } else {
                    $filteredConversation['type'] = 'public';
                    $filteredConversations[]      = $filteredConversation;
                }
            }
        }

        return $filteredConversations;
    }

    /**
     * Store a room in a file to recover it later
     *
     * @param      string  $roomName  The room name
     */
    private function saveRoom(string $roomName)
    {
        $tmpSockets                           = $this->rooms[$roomName]['sockets'];
        $tmpPseudonyms                        = $this->rooms[$roomName]['pseudonyms'];
        $tmpHistoric                          = $this->rooms[$roomName]['historic'];
        $this->rooms[$roomName]['sockets']    = array();
        $this->rooms[$roomName]['pseudonyms'] = array();
        $this->rooms[$roomName]['historic']   = array();

        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'room.json',
            json_encode($this->rooms[$roomName])
        );

        $this->rooms[$roomName]['sockets']    = $tmpSockets;
        $this->rooms[$roomName]['pseudonyms'] = $tmpPseudonyms;
        $this->rooms[$roomName]['historic']   = $tmpHistoric;
    }

    /**
     * Get the room information stored in a JSON file
     *
     * @param      string  $roomName  The room name
     *
     * @return     array   The JSON decoded room information as associative array
     */
    private function getRoomInfo(string $roomName): array
    {
        return json_decode(
            file_get_contents(
                stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR .$roomName) .
                DIRECTORY_SEPARATOR . 'room.json'
            ),
            true
        );
    }

    /**
     * Load a room that was stored in a file
     *
     * @param      string  $roomName  The room name
     */
    private function loadRoom(string $roomName)
    {
        $this->rooms[$roomName] = $this->getRoomInfo($roomName);

        $this->loadHistoric($roomName, $this->getLastPartNumber($roomName));
    }

    /**
     * Update a conversation historic with a new message
     *
     * @param      string  $roomName  The room name
     * @param      string  $time      The server message sent time
     * @param      string  $message   The text message
     * @param      string  $from      The pseudonym of the user message owner
     * @param      string  $to        The pseudonym of the user message reviever or 'all'
     */
    private function updateHistoric(string $roomName, string $time, string $message, string $from, string $to)
    {
        if (count($this->rooms[$roomName]['historic']['conversations']) >= $this->maxMessagesPerFile) {
            $this->saveHistoric($roomName);
            $this->rooms[$roomName]['historic']['conversations'] = array();
            $this->setLastPartNumber($roomName, ++$this->rooms[$roomName]['historic']['part']);
        }

        $this->rooms[$roomName]['historic']['conversations'][] = array(
            'text' => $message,
            'time' => $time,
            'from' => $from,
            'to'   => $to
        );
    }

    /**
     * Store the conversation historic into a JSON text file
     *
     * @param      string  $roomName  The room name
     */
    private function saveHistoric(string $roomName)
    {
        $part = $this->rooms[$roomName]['historic']['part'];

        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-part-' . $part . '.json',
            json_encode($this->rooms[$roomName]['historic'])
        );
    }

    /**
     * Load an historic
     *
     * @param      string  $roomName  The room name
     * @param      int     $part      The historic part
     */
    private function loadHistoric(string $roomName, int $part)
    {
        $historic = $this->getHistoricPart($roomName, $part);

        if ($historic === false || $historic === null) {
            $historic = array('part' => 0, 'conversations' => array());
        }

        $this->rooms[$roomName]['historic'] = $historic;
    }

    /**
     * Get a conversation historic part
     *
     * @param      string       $roomName  The room name
     * @param      int          $part      The conversation part
     *
     * @return     array|false  The conversation historic as an array or false if an error occured
     */
    private function getHistoricPart(string $roomName, int $part)
    {
        return json_decode(
            @file_get_contents(
                stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
                DIRECTORY_SEPARATOR . 'historic-part-' . $part . '.json'
            ),
            true
        );
    }

    /**
     * Get the last part number of room historic
     *
     * @param      string  $roomName  The room name
     *
     * @return     int     The last part number
     */
    private function getLastPartNumber(string $roomName): int
    {
        return (int) file_get_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-last-part'
        );
    }

    /**
     * Set the last part number of room historic
     *
     * @param      string  $roomName  The room name
     * @param      int     $part      The last part number
     */
    private function setLastPartNumber(string $roomName, int $part)
    {
        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-last-part',
            $part
        );
    }

    /**
     * Get the rooms name
     *
     * @return     string[]  The rooms name
     */
    private function getRoomsName(): array
    {
        return json_decode(file_get_contents($this->roomsNamePath, FILE_USE_INCLUDE_PATH), true);
    }

    /**
     * Update the rooms name
     */
    private function setRoomsName()
    {
        file_put_contents($this->roomsNamePath, json_encode($this->roomsName), FILE_USE_INCLUDE_PATH);
    }

    /**
     * Log a message to the server if verbose mode is activated
     *
     * @param      string  $message  The message to output
     */
    private function log(string $message)
    {
        $serverSocket = stream_socket_client($this->serverAddress);
        Ini::setIniFileName(Ini::INI_CONF_FILE);
        $this->send($serverSocket, Ini::getParam('Socket', 'serviceKey') . $message);
        fclose($serverSocket);
    }

    /*=====  End of Private methods  ======*/
}
