<?php

/**
 * ovengloves.inc.php
 * error & exception handling library
 * so named because exceptions are like hot potatoes that u throw
 * and they must be handled accordingly  ;-)
 */

// TODO: some help with MySQL warnings
// http://stackoverflow.com/questions/47589/can-i-detect-and-handle-mysql-warnings-with-php


// this file should define MAIL_DEVS and DEV_MODE constants
require_once 'secrets.inc.php';

// full path to directory in which to store our log files (__DIR__ refers to location of this script, ovengloves.inc.php)
define('LOGFILES_DIR', realpath(__DIR__.'/../logs'));

// full path to directory where the rate limiter temp files are to be created/deleted (web server user needs r,w,x perms on this directory)
define('RATELIMIT_DIR', LOGFILES_DIR.'/.ratelimiter');

// max number of report emails we can send to the devs in a 24 hour period
define('RATELIMIT_MAX_FILES', 25);

// location of the script that is responsible for displaying an error page to the user
define('OOPS_SCRIPT_LOCATION', 'oops.inc.php');


// choose value for USE_OVENGLOVES
// if set to false, we do not register the functions as handlers
if (php_sapi_name() == 'cli')
{
    define('USE_OVENGLOVES', false);
}
elseif (defined('DEV_MODE'))
{
    if (DEV_MODE)
        define('USE_OVENGLOVES', false); // set to false when finished testing ovengloves.inc.php
    else
        define('USE_OVENGLOVES', true);
}
else
{
    // use by default
    define('USE_OVENGLOVES', true);
}


// exception codes (choice of explanation to give the visitor) - used by showOopsPage() and the script with location defined by OOPS_SCRIPT_LOCATION
define('EXCEPTION_CODE_USER', 1);
define('EXCEPTION_CODE_TEMP', 2);
define('EXCEPTION_CODE_PERM', 3);
define('EXCEPTION_CODE_THIRDPARTY', 4);


// uncomment these lines if you want to override the settings in php.ini or Apache config
/*
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 'off');
*/


/**
 * show an error page to the user (with an HTTP 500 error)
 * @param string $code Exception code / type of problem
 * @param string $details Additional details about error to display to the user (optional)
 */
function showOopsPage($type = EXCEPTION_CODE_PERM, $details = '')
{
    // discard anything in output buffers (NB: doesn't affect HTTP headers already sent with header())
    while (@ob_end_clean()) { }

    // these vars are used within the included file
    // TODO: this is an ugly way of passing data - fix this pls!
    $errorType = $type;
    $errorDetails = $details;

    @include_once (OOPS_SCRIPT_LOCATION);

}


/**
 * email a report to the developers
 * all strings should be UTF-8
 *
 * @param string $report Info to send
 * @param bool $rateLimitApplies Should we rate limit this email? ( false = always send email )
 * @param bool $reportIsHTML Set true if $report contains HTML
 * @param string $reason Reason for sending the email
 * @param string $replyTo Value for Reply-To: email header (assumed valid but before any encoding)
 * @return bool true if we sent an email, false if we didn't
 */
function emailReportToDevs($report, $rateLimitApplies = true, $reportIsHTML = false, $reason = 'There was a problem', $replyTo = '')
{

    if (!defined('MAIL_DEVS') || !MAIL_DEVS)
    {
        logMessage('ERROR: emailReportToDevs() called but MAIL_DEVS was not defined or was blank');
        return false;
    }

    if (empty($report))
    {
        logMessage('ERROR: emailReportToDevs() called with empty $report');
        return false;
    }

    if ($rateLimitApplies)
    {
        // some rate-limiting code to prevent a problem with the site causing a barrage of emails
        // janet is a reference to Rocky Horror Show and the use of touch() below  ;-)
        if ((!is_dir(RATELIMIT_DIR) && !@mkdir(RATELIMIT_DIR, 0755)) || (!is_writable(RATELIMIT_DIR) && @!chmod(RATELIMIT_DIR, 0755)))
        {
            logMessage('ERROR: RATELIMIT_DIR ('.RATELIMIT_DIR.') is not a directory or is not writable so we cannot emailReportToDevs()!');
            return false;
        }

        // delete .janet files in RATELIMIT_DIR that are more than 24 hours old
        // TODO: we probably shouldn't use an exec() call at all as then we are relying on Linux-like OS!
        @exec('/bin/find '.RATELIMIT_DIR." -name '*.janet' -mtime +0 -execdir /bin/rm -f '{}' ';'", $output, $retval);

        if ($retval)
        {
            logMessage("ERROR: exec() to remove old .janet files returned $retval.  Output follows:");
            logMessage(print_r($output, true));
            return false;
        }

        // count how many files remain within the RATELIMIT_DIR - return if too many
        if (is_array($ls = glob(RATELIMIT_DIR.'/*.janet')))
        {
            if (count($ls) < RATELIMIT_MAX_FILES)
            {

                list($msecs, $timestamp) = explode(' ', microtime());
                $filename = date('Y-m-d_H:i:s', $timestamp).'.'.substr($msecs, 2).'.janet';  // the substr() just cuts off the '0.' at start of $msecs

                if (!touch(RATELIMIT_DIR.'/'.$filename))
                {
                    logMessage('ERROR: Unable to touch '.RATELIMIT_DIR.'/'.$filename);
                    return false;
                }
            }
            else
            {
                // too many emails sent in last 24 hrs.. return
                logMessage('Not emailing developers as at least '.RATELIMIT_MAX_FILES.' have been sent in last 24 hours');
                return false;
            }
        }
        else
        {
            logMessage('ERROR: Couldn\'t glob() for files within '.RATELIMIT_DIR);
            return false;
        }
    }

    if (!$reportIsHTML)
    {
        // 'convert' plain text report to HTML
        $report = nl2br(htmlspecialchars($report, ENT_COMPAT, 'UTF-8'));
    }

    // get visitor's FQDN - use Apache's var if available
    if (empty($_SERVER['REMOTE_HOST']))
        $remoteHost = gethostbyaddr($_SERVER['REMOTE_ADDR']);
    else
        $remoteHost = $_SERVER['REMOTE_HOST'];


    $body =
'<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8" />
</head>
<body>
<p>Hi there,<p>

<p>Someone ('.$_SERVER['REMOTE_ADDR'].' ('.$remoteHost.')) just visited '.$_SERVER['SERVER_NAME'].'.</p>

<hr />
<div style="font-family: sans-serif">
'.$report.'
</div>
<hr />

<p>
Self: '.htmlspecialchars($_SERVER['PHP_SELF'], ENT_COMPAT, 'UTF-8').'<br />
'.$_SERVER['REQUEST_METHOD'].' request to: '.htmlspecialchars($_SERVER['REQUEST_URI'], ENT_COMPAT, 'UTF-8').'
</p>

<p>Cheers,<br />
PHP '.phpversion().' ('.php_sapi_name().')<br />
on '.htmlspecialchars($_SERVER['SERVER_SOFTWARE'], ENT_COMPAT, 'UTF-8').'</p>
</body>
</html>';

    //TODO: dump $_POST on POST request method?

    // convert newlines to \r\n (so it looks nicer when we view email source!)
    switch (PHP_EOL)
    {
        // this will only work if the newline sequence used in this source code file matches PHP_EOL
        case "\n":
        case "\r":
        case "\n\r":
            $body = str_replace(PHP_EOL, "\r\n", $body);
    }

    $body = quoted_printable_encode($body);
    // OR (for the default 7bit encoding)
    //$body = wordwrap($body, 70, "\r\n", true);

    $subject = $reason;

    $headers  = "MIME-Version: 1.0";
    $headers .= "\r\nContent-Type: text/html; charset=UTF-8";
    $headers .= "\r\nContent-Transfer-Encoding: quoted-printable";

    $whoami = posix_getpwuid(posix_geteuid());
    $headers .= "\r\nFrom: ".$_SERVER['SERVER_NAME'].' <'.$whoami['name'].'@'.php_uname('n').'>';

    // if we need to set the reply-to header
    if (!empty($replyTo))
    {
        if (preg_match('/^(.*) *<(.*)>$/', $replyTo, $matches))
        {
            $name = $matches[1];
            $email = $matches[2];

            if (!mb_internal_encoding('UTF-8'))
            {
                logMessage('WARNING: Failed to set internal encoding to UTF-8');
            }
            $name = mb_encode_mimeheader($name, mb_internal_encoding(), 'Q');

            $headers .= "\r\nReply-To: $name <$email>";
        }
        else
        {
            $headers .= "\r\nReply-To: $replyTo";
        }
    }

    // we use @ to squelch errors because we could end up triggering error handling whilst potentially already dealing with an error
    if (!@mail(MAIL_DEVS, $subject, $body, $headers))
    {
        logMessage("WARNING: mail() call failed in emailReportToDevs()");
        return false;
    }
    return true;
}


/**
 * return name given to an error code
 * @param int $code Error code
 * @return string Name given to that error code
 */
function getErrorNameFromCode($code)
{
    switch($code)
    {
        case E_ERROR: // 1 //
            return 'E_ERROR';
        case E_WARNING: // 2 //
            return 'E_WARNING';
        case E_PARSE: // 4 //
            return 'E_PARSE';
        case E_NOTICE: // 8 //
            return 'E_NOTICE';
        case E_CORE_ERROR: // 16 //
            return 'E_CORE_ERROR';
        case E_CORE_WARNING: // 32 //
            return 'E_CORE_WARNING';
        case E_COMPILE_ERROR: // 64 //
            return 'E_COMPILE_ERROR';
        case E_COMPILE_WARNING: // 128 //
            return 'E_COMPILE_WARNING';
        case E_USER_ERROR: // 256 //
            return 'E_USER_ERROR';
        case E_USER_WARNING: // 512 //
            return 'E_USER_WARNING';
        case E_USER_NOTICE: // 1024 //
            return 'E_USER_NOTICE';
        case E_STRICT: // 2048 //
            return 'E_STRICT';
        case E_RECOVERABLE_ERROR: // 4096 //
            return 'E_RECOVERABLE_ERROR';
        case E_DEPRECATED: // 8192 //
            return 'E_DEPRECATED';
        case E_USER_DEPRECATED: // 16384 //
            return 'E_USER_DEPRECATED';
        default:
            return 'E_UNKNOWN_CODE_'.$code;
    }
}


/**
 * return name given to an exception code
 * @param int $code Exception code
 * @return string Name given to that exception code
 */
function getExceptionCodeNameFromNumber($code)
{
    switch($code)
    {
        case EXCEPTION_CODE_USER:
            return 'EXCEPTION_CODE_USER';
        case EXCEPTION_CODE_TEMP:
            return 'EXCEPTION_CODE_TEMP';
        case EXCEPTION_CODE_PERM:
            return 'EXCEPTION_CODE_PERM';
        case EXCEPTION_CODE_THIRDPARTY:
            return 'EXCEPTION_CODE_THIRDPARTY';
        default:
            return 'EXCEPTION_CODE_UNKNOWN_'.$code;
    }
}


/**
 * Return a string list of error types that are set to be reported
 * @return string Description of what errors are being reported
 */
function describeErrorReporting()
{
    $error_reporting = error_reporting();
    $errTypesToReport = array();

    for ($i = 0; $i < 15;  $i++)
    {
        // & = bitwise AND, with 2^0, 2^1, 2^2, 2^3, etc
        $errTypesToReport[] = getErrorNameFromCode($error_reporting & pow(2, $i));
    }
    return join(', ', $errTypesToReport);
}



/**
 * stuff that gets executed on shutdown
 */
function shutDownFunction()
{
    try
    {
        // error_get_last will return error info
        $lastError = error_get_last();
        /*
        Returns an associative array describing the last error with keys "type", "message", "file"
        and "line". If the error has been caused by a PHP internal function then the "message" begins
        with its name. Returns NULL if there hasn't been an error yet.
        */

        if (!empty($lastError))
        {
            if (!isset($lastError['type']))
            {
                logMessage("ERROR: error_get_last() within shutDownFunction returned an error without a type!");
            }
            else
            {
                //unclean shutdown - here we can do stuff with some error types that can't be caught by custom error handler
                switch($lastError['type'])
                {
                    case E_ERROR:
                    case E_CORE_ERROR:
                    case E_COMPILE_ERROR:
                    case E_COMPILE_WARNING:
                    case E_CORE_WARNING:
                    case E_STRICT:
                    case E_PARSE:

                        // call the registered exception handler with a self-made ErrorException


                        // This code will work no matter what the exception handler is currently set to
                        /*
                        function fakeHandler() { }
                        $handler = set_exception_handler('fakeHandler');
                        restore_exception_handler();

                        if ($handler !== null)
                        {
                            // use our exception handler
                            call_user_func($handler, new ErrorException($lastError['message'], $lastError['type'], 0, $lastError['file'], $lastError['line']));
                        }
                        else
                        {
                            showOopsPage();

                            logMessage('Terminating due to uncatchable error of type '.getErrorNameFromCode($lastError['type']).':');
                            logMessage(print_r($lastError, true));

                            emailReportToDevs('Terminating due to uncatchable error of type '.getErrorNameFromCode($lastError['type']).":\n".print_r($lastError, true));
                        }
                        */

                        // this line just uses our own exceptionHandler regardless
                        exceptionHandler(new ErrorException($lastError['message'], EXCEPTION_CODE_PERM, $lastError['type'], $lastError['file'], $lastError['line']));

                        break;
                    default:
                        // any other errors will have been already converted into exceptions and dealt with by the exception handler
                        break;
                }
            }

            if (defined('LOGFILE'))  logMessage("Shutting down");  //only write this to log file if we've already written to one
            exit(1);
        }
    }
    catch (Exception $e)
    {
        // squelch it but report to web server log
        @error_log(get_class($e)." thrown within shutDownFunction(). Message: ".$e->getMessage(). "  in " . $e->getFile() . " on line ".$e->getLine());
    }
}


/**
 * return text description of an exception (for a developer, not an end user)
 * @param Exception $exception An exception
 * @return string Textual description of this exception
 */
function describeException(Exception $exception)
{
    $className = get_class($exception);

    $ret  = '';
    $ret .= 'Class: '.$className.PHP_EOL;

    // handle different classes of Exception here
    switch($className)
    {
        case 'ErrorException':
            $ret .= 'Error Type: '.getErrorNameFromCode($exception->getSeverity()).PHP_EOL;
            break;
    }

    $code = $exception->getCode();
    if ($code)
        $ret .= 'Code: '.getExceptionCodeNameFromNumber($code).PHP_EOL;

    // stuff that is common to all Exception objects
    $ret .= 'Message: '.$exception->getMessage().PHP_EOL;
    $ret .= 'File: '.$exception->getFile().PHP_EOL;
    $ret .= 'Line: '.$exception->getLine().PHP_EOL;
    $ret .= 'Trace: '.$exception->getTraceAsString().PHP_EOL;

    return $ret;
}


/**
 * an exceptionally good (baddum-tssh) exception handler
 * @param Exception $exception Exception that has been thrown and not caught
 */
function exceptionHandler(Exception $exception)
{
    // we MUST catch all exceptions here because an exception handler throwing exceptions is BAD NEWS
    // alternative: restore default exception and error handler at start and reinstate them at the end?
    try
    {
        showOopsPage($exception->getCode());

        logMessage('Uncaught exception: ');
        logMessage(describeException($exception));

        emailReportToDevs("I took exception to something and it wasn't caught:\n".describeException($exception));

    }
    catch (Exception $e)
    {
        // squelch it but report to web server log
        @error_log(get_class($e)." thrown within exceptionHandler()!  Message: ".$e->getMessage()."  in ".$e->getFile()." on line ".$e->getLine());
    }

    // "Execution will stop after the exception_handler is called."
    // so after reaching the end of this function, the PHP process will run
    // shutDownFunction() and then terminate
}



/**
 * our error handler to convert old skool errors (often used by internal PHP functions) into nice shiny exceptions
 * @param int $errNum Error type code
 * @param string $errStr Textual description of error
 * @param string $errFile File that the error occured in
 * @param int $errLine Line number of the error
 * @param array $errContext Every variable that existed in the scope the error was triggered in
 */
function errorHandler($errNum, $errStr, $errFile, $errLine, $errContext)
{
    /*
     * The following error types cannot be handled with a user defined function:
     * E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING,
     * E_COMPILE_ERROR, E_COMPILE_WARNING, and most of E_STRICT raised in the file where set_error_handler() is called.
     */

    if (!(error_reporting() & $errNum))
    {
        // This error code should not be reported according to error_reporting
        // By returning true, we ensure that PHP error handler doesn't take over
        // NB: the error will NOT be registered by functions like error_get_last()
        return true;

        // alternatively we can do this hack to ensure that error_get_last() etc will
        // give info on this error (as an E_USER_NOTICE) but I think line number and file name will be this file and line:
        //@trigger_error($errStr)
        //return false;
    }

    // throw an exception - will either be caught by the underlying code or otherwise handled by exceptionHandler()
    throw new ErrorException($errStr, EXCEPTION_CODE_PERM, $errNum, $errFile, $errLine);

    // do not allow the normal PHP error handler to deal with this error
    //  things like error_get_last() will not work
    return true;
}



/**
 * write a message to a per-visit log file
 *
 * @param string $msg Message to write to log file (newline will be appended automatically)
 */
function logMessage($msg)
{

    if (!defined('LOGFILE'))
    {
        if(is_writable(LOGFILES_DIR))
        {
            list($msecs, $timestamp) = explode(' ', microtime());
            $filename = date('Y-m-d_H:i:s', $timestamp).'.'.substr($msecs, 2);
            define('LOGFILE', LOGFILES_DIR.'/'.$filename.'.log');

            logMessage("Logging started");
            logMessage("PHP_SELF: $_SERVER[PHP_SELF]");

            if (!empty($_SERVER['REQUEST_METHOD']) && !empty($_SERVER['REQUEST_URI']))
                logMessage("REQUEST: $_SERVER[REQUEST_METHOD] $_SERVER[REQUEST_URI]");
        }
        else
        {
            define('LOGFILE', false);

            emailReportToDevs('LOGFILES_DIR ('.LOGFILES_DIR.') isn\'t writable so log files are disabled');
            return false;
        }
    }

    if (LOGFILE)
        return @error_log(date('Y-m-d H:i:s')." - $msg\n", 3, LOGFILE);
    else
        // fallback: write to PHP's system logger
        return @error_log($msg);
}



// register everything
if (USE_OVENGLOVES)
{
    register_shutdown_function('shutDownFunction');
    set_error_handler('errorHandler');
    set_exception_handler('exceptionHandler');
}


// end ovengloves.inc.php
