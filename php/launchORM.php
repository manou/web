<?php
/**
 * Launch an ORM instance
 *
 * @package    Launcher
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

use \classes\console\Orm as Orm;

require_once 'autoloader.php';

try {
    $console = new Orm();
} catch (\Throwable $t) {
} finally {
    exit(0);
}
