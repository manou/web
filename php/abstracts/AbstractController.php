<?php
/**
 * Abstract Controller class to harmonize standards controllers methods
 *
 * @category Abstract
 * @author   Romain Laneuville <romain.laneuville@hotmail.fr>
 */
namespace abstracts;

/**
 * Abstract Controller class to harmonize standards controllers methods
 *
 * @abstract
 * @class AbstractController
 */
abstract class AbstractController
{
    /**
     * Output a JSON reponse from a data array passed in parameter
     *
     * @param array $data The data to output
     */
    public function JSONresponse($data)
    {
        echo json_encode($data, true);
    }
}
