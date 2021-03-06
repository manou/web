<?php
/**
 * Service interface to normalize WebSocket protocole services
 *
 * @package    Interface
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */
namespace interfaces;

/**
 * Service interface to normalize WebSocket protocole services
 */
interface ServiceInterface
{
    /**
     * Method to recieves data from the WebSocket server
     *
     * @param      resource  $socket  The client socket
     * @param      array     $data    JSON decoded client data
     */
    public function service($socket, array $data);
}
