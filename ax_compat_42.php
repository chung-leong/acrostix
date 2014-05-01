<?php
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2004 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: David Irvine <dave@codexweb.co.za>                          |
// |          Aidan Lister <aidan@php.net>                                |
// +----------------------------------------------------------------------+
//
// $Id: html_entity_decode.php,v 1.8 2005/12/07 21:08:57 aidan Exp $


if (!defined('ENT_NOQUOTES')) {
    define('ENT_NOQUOTES', 0);
}

if (!defined('ENT_COMPAT')) {
    define('ENT_COMPAT', 2);
}

if (!defined('ENT_QUOTES')) {
    define('ENT_QUOTES', 3);
}


/**
 * Replace html_entity_decode()
 *
 * @category    PHP
 * @package     PHP_Compat
 * @link        http://php.net/function.html_entity_decode
 * @author      David Irvine <dave@codexweb.co.za>
 * @author      Aidan Lister <aidan@php.net>
 * @version     $Revision: 1.8 $
 * @since       PHP 4.3.0
 * @internal    Setting the charset will not do anything
 * @require     PHP 4.0.0 (user_error)
 */
if (!function_exists('html_entity_decode')) {
    function html_entity_decode($string, $quote_style = ENT_COMPAT, $charset = null)
    {
        if (!is_int($quote_style)) {
            user_error('html_entity_decode() expects parameter 2 to be long, ' .
                gettype($quote_style) . ' given', E_USER_WARNING);
            return;
        }

        $trans_tbl = get_html_translation_table(HTML_ENTITIES);
        $trans_tbl = array_flip($trans_tbl);

        // Add single quote to translation table;
        $trans_tbl['&#039;'] = '\'';

        // Not translating double quotes
        if ($quote_style & ENT_NOQUOTES) {
            // Remove double quote from translation table
            unset($trans_tbl['&quot;']);
        }

        return strtr($string, $trans_tbl);
    }
}

?>
<?php
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2004 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Aidan Lister <aidan@php.net>                                |
// +----------------------------------------------------------------------+
//
// $Id: array_diff_assoc.php,v 1.12 2005/12/07 21:08:57 aidan Exp $


/**
 * Replace array_diff_assoc()
 *
 * @category    PHP
 * @package     PHP_Compat
 * @link        http://php.net/function.array_diff_assoc
 * @author      Aidan Lister <aidan@php.net>
 * @version     $Revision: 1.12 $
 * @since       PHP 4.3.0
 * @require     PHP 4.0.0 (user_error)
 */
if (!function_exists('array_diff_assoc')) {
    function array_diff_assoc()
    {
        // Check we have enough arguments
        $args = func_get_args();
        $count = count($args);
        if (count($args) < 2) {
            user_error('Wrong parameter count for array_diff_assoc()', E_USER_WARNING);
            return;
        }

        // Check arrays
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($args[$i])) {
                user_error('array_diff_assoc() Argument #' .
                    ($i + 1) . ' is not an array', E_USER_WARNING);
                return;
            }
        }

        // Get the comparison array
        $array_comp = array_shift($args);
        --$count;

        // Traverse values of the first array
        foreach ($array_comp as $key => $value) {
            // Loop through the other arrays
            for ($i = 0; $i < $count; $i++) {
                // Loop through this arrays key/value pairs and compare
                foreach ($args[$i] as $comp_key => $comp_value) {
                    if ((string)$key === (string)$comp_key &&
                        (string)$value === (string)$comp_value)
                    {

                        unset($array_comp[$key]);
                    }
                }
            }
        }

        return $array_comp;
    }
}

?>
<?php
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2004 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Aidan Lister <aidan@php.net>                                |
// +----------------------------------------------------------------------+
//
// $Id: file_get_contents.php,v 1.21 2005/01/26 04:55:13 aidan Exp $


/**
 * Replace file_get_contents()
 *
 * @category    PHP
 * @package     PHP_Compat
 * @link        http://php.net/function.file_get_contents
 * @author      Aidan Lister <aidan@php.net>
 * @version     $Revision: 1.21 $
 * @internal    resource_context is not supported
 * @since       PHP 5
 * @require     PHP 4.0.0 (user_error)
 */
if (!function_exists('file_get_contents')) {
    function file_get_contents($filename, $incpath = false, $resource_context = null)
    {
        if (false === $fh = fopen($filename, 'rb', $incpath)) {
            user_error('file_get_contents() failed to open stream: No such file or directory',
                E_USER_WARNING);
            return false;
        }

        clearstatcache();
        if ($fsize = @filesize($filename)) {
            $data = fread($fh, $fsize);
        } else {
            $data = '';
            while (!feof($fh)) {
                $data .= fread($fh, 8192);
            }
        }

        fclose($fh);
        return $data;
    }
}

?>
<?php

function debug_backtrace() {
	return array();
}

?>
