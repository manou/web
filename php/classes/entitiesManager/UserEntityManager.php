<?php
/**
 * Entity manager for he entity User
 *
 * @package    EntityManager
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\entitiesManager;

use \abstracts\EntityManager as EntityManager;
use \classes\entitiesManager\UsersRightsEntityManager as UsersRightsEntityManager;
use \classes\entitiesManager\UsersChatRightsEntityManager as UsersChatRightsEntityManager;
use \classes\entities\User as User;
use \classes\DataBase as DB;
use \classes\IniManager as Ini;

/**
 * Performed database action relative to the User entity class
 *
 * @property   User  $entity  The user entity
 */
class UserEntityManager extends EntityManager
{
    use \traits\FiltersTrait;

    /**
     * Constructor that can take a User entity as first parameter and a Collection as second parameter
     *
     * @param      User        $entity            A user entity object DEFAULT null
     * @param      Collection  $entityCollection  A colection oject DEFAULT null
     */
    public function __construct(User $entity = null, Collection $entityCollection = null)
    {
        parent::__construct($entity, $entityCollection);

        if ($entity === null) {
            $this->entity = new User();
        }
    }

    /**
     * Load user entity and userRights entity linked to the user entity
     *
     * @param      int|array  $id     The id value
     */
    public function loadEntity($id)
    {
        parent::loadEntity($id);

        $usersRightsEntityManager = new UsersRightsEntityManager($this->entity->getUserRights());
        $usersRightsEntityManager->loadEntity($id);
    }

    /**
     * Get a user id by his pseudonym
     *
     * @param      string  $pseudonym  The user pseudonym
     *
     * @return     int     The user id
     */
    public function getUserIdByPseudonym(string $pseudonym): int
    {
        $user       = new User();
        $sqlMarks   = 'SELECT id FROM %s WHERE pseudonym = %s';
        $sql        = static::sqlFormater($sqlMarks, $user->getTableName(), DB::quote($pseudonym));

        return (int) DB::query($sql)->fetchColumn();
    }

    /**
     * Register a user and return errors if errors occured
     *
     * @param      array  $inputs  The user inputs in an array($columnName => $value) pairs to set the object
     *
     * @return     array  The occured errors or success in a array
     */
    public function register(array $inputs): array
    {
        $success = false;
        $errors  = $this->checkMustDefinedField(array_keys($inputs));

        if (count($errors['SERVER']) === 0) {
            $user     = new User();
            $query    = 'SELECT MAX(id) FROM ' . $user->getTableName();
            $user->id = DB::query($query)->fetchColumn() + 1;

            $user->bindInputs($inputs);
            $errors = $user->getErrors();

            if (count($errors) === 0) {
                $success = $this->saveEntity($user);
            }
        }

        return array('success' => $success, 'errors' => $errors, 'user' => $user->__toArray());
    }

    /**
     * Connect a user with his login / password combinaison
     *
     * @param      string[]  $inputs  Inputs array containing array('login' => 'login', 'password' => 'password')
     *
     * @return     array  The occured errors or success in a array
     * @todo       refacto make it shorter...
     */
    public function connect(array $inputs): array
    {
        $errors   = array();
        $success  = false;
        $login    = @$this->getInput($inputs['login']);
        $password = @$this->getInput($inputs['password']);

        if ($login === null || $login === '') {
            $errors['login'] = _('Login can\'t be empty');
        } else {
            $login = DB::quote($login);
        }

        if ($password === null || $password === '') {
            $errors['password'] = _('Password can\'t be empty');
        }

        if (count($errors) === 0) {
            $user       = new User();
            $sqlMarks   = 'SELECT * FROM %s WHERE email = %s OR pseudonym = %s';
            $sql        = static::sqlFormater($sqlMarks, $user->getTableName(), $login, $login);
            $userParams = DB::query($sql)->fetch();
            $now        = new \DateTime();
            $continue   = true;

            if ($userParams !== false) {
                $user->setAttributes($userParams);

                if ((int) $user->connectionAttempt === -1) {
                    $lastConnectionAttempt = new \DateTime($user->lastConnectionAttempt);
                    $intervalInSec         = $this->dateIntervalToSec($now->diff($lastConnectionAttempt));
                    $minInterval           = (int) Ini::getParam('User', 'minTimeAttempt');

                    if ($intervalInSec < $minInterval) {
                        $continue         = false;
                        $errors['SERVER'] = _(
                            'You have to wait ' . ($minInterval - $intervalInSec) . ' sec before trying to reconnect'
                        );
                    } else {
                        $user->connectionAttempt = 1;
                    }
                } else {
                    $user->connectionAttempt++;
                    $user->ipAttempt             = $_SERVER['REMOTE_ADDR'];
                    $user->lastConnectionAttempt = $now->format('Y-m-d H:i:s');
                }

                if ($user->ipAttempt === $_SERVER['REMOTE_ADDR']) {
                    if ($user->connectionAttempt === (int) Ini::getParam('User', 'maxFailConnectAttempt')) {
                        $user->connectionAttempt = -1;
                    }
                } else {
                    $user->connectionAttempt = 1;
                    $user->ipAttempt         = $_SERVER['REMOTE_ADDR'];
                }

                if ($continue) {
                    if (hash_equals($userParams['password'], crypt($password, $userParams['password']))) {
                        $success                 = true;
                        $user->lastConnection    = $now->format('Y-m-d H:i:s');
                        $user->connectionAttempt = 0;
                        $user->ip                = $_SERVER['REMOTE_ADDR'];
                    } else {
                        $errors['password'] = _('Incorrect password');
                    }
                }

                $this->saveEntity($user);
            } else {
                $errors['login'] = _('This login does not exist');
            }
        }

        $response = array('success' => $success, 'errors' => $errors);

        if ($success) {
            $usersChatRightsEntityManager   = new UsersChatRightsEntityManager();
            $user->password                 = $password;
            $response['user']               = $user->__toArray();
            $response['user']['chatRights'] = $usersChatRightsEntityManager->getAllUserChatRights($user->id);
        }

        return $response;
    }

    /**
     * Authenticate a User by his login / password combinaison and return the User object on success or false on fail
     *
     * @param      string      $login     The user login (email or pseudonym)
     * @param      string      $password  The user password
     *
     * @return     User|false  The User instanciated object or false is the authentication failed
     */
    public function authenticateUser(string $login, string $password)
    {
        $user       = new User();
        $login      = DB::quote($login);
        $sqlMarks   = 'SELECT id, password FROM %s WHERE email = %s OR pseudonym = %s';
        $sql        = static::sqlFormater($sqlMarks, $user->getTableName(), $login, $login);
        $userParams = DB::query($sql)->fetch();

        if ($userParams !== false) {
            if (!hash_equals($userParams['password'], crypt($password, $userParams['password']))) {
                $user = false;
            } else {
                $this->loadEntity($userParams['id']);
                $user = $this->entity;
            }
        } else {
            $user = false;
        }

        return $user;
    }

    /*====================================
    =            Chat section            =
    ====================================*/

    /**
     * Check if a user have the admin access to the WebSocker server
     *
     * @param      string  $login     The user login
     * @param      string  $password  The user password
     *
     * @return     bool    True if the User has the right else false
     */
    public function connectWebSocketServer(string $login, string $password): bool
    {
        $success = false;
        $user    = $this->authenticateUser($login, $password);

        if ($user !== false) {
            if ((int) $this->entity->getUserRights()->webSocket === 1) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Check if a user has the right to ckick a user
     *
     * @param      string  $login     The user login
     * @param      string  $password  The user password
     *
     * @return     bool    True if a user has the right to kick a player from a room else false
     */
    public function hasChatAdminRight(string $login, string $password): bool
    {
        $success = false;
        $user    = $this->authenticateUser($login, $password);

        if ($user !== false) {
            if ((int) $this->entity->getUserRights()->chatAdmin === 1) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Get a user pseudonym
     *
     * @return     string  The user pseudonym (first name + last name if not defined)
     */
    public function getPseudonymForChat(): string
    {
        if ($this->entity->pseudonym !== '' && $this->entity->pseudonym !== null) {
            $pseudonym = $this->entity->pseudonym;
        } else {
            $pseudonym = $this->entity->firstName . ' ' . $this->entity->lastName;
        }

        return $pseudonym;
    }

    /**
     * Check if a pseudonym exists in the database
     *
     * @param      string  $pseudonym  The pseudonym
     *
     * @return     bool    True if the pseudonym exists else false
     */
    public function isPseudonymExist(string $pseudonym): bool
    {
        $user     = new User();
        $sqlMarks = 'SELECT count(*) FROM %s WHERE pseudonym = %s';
        $sql      = static::sqlFormater($sqlMarks, $user->getTableName(), DB::quote($pseudonym));

        return (int) DB::query($sql)->fetchColumn() > 0;
    }

    /*=====  End of Chat section  ======*/

    /**
     * Check all the must defined fields and fill an errors array if not
     *
     * @param      array  $fields  The fields to check
     *
     * @return     array  The errors array with any missing must defined fields
     */
    private function checkMustDefinedField(array $fields): array
    {
        $errors           = array();
        $errors['SERVER'] = array();

        foreach (User::$mustDefinedFields as $field) {
            if (!in_array($field, $fields)) {
                $errors['SERVER'][] = _($field . ' must be defined');
            }
        }

        return $errors;
    }
}
