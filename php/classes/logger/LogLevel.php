<?php
/**
 * Log Level to report an exception
 *
 * @package    Constants
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */
namespace classes\logger;

/**
 * Log level constants
 */
class LogLevel
{
    const EMERGENCY = 0;
    const ALERT     = 1;
    const CRITICAL  = 2;
    const ERROR     = 3;
    const WARNING   = 4;
    const NOTICE    = 5;
    const INFO      = 6;
    const DEBUG     = 7;
    const PARAMETER = self::ERROR;
}
