<?php

// Log to STDOUT
function log_out($type, $source, $message, $workflowId = 0)
{
    global $argv;

    $log = [
        "time"    => time(),
        "source"  => $source,
        "type"    => $type,
        "message" => $message
    ];
    
    if ($workflowId)
        $log["workflowId"] = $workflowId;
    
    if (!openlog ($argv[0], LOG_CONS|LOG_PID, LOG_LOCAL1))
        throw new CTException("Unable to connect to Syslog!", 
            "OPENLOG_ERROR");
    
    switch ($type)
    {
    case "INFO":
        $priority = LOG_INFO;
        break;
    case "ERROR":
        $priority = LOG_ERR;
        break;
    case "FATAL":
        $priority = LOG_ALERT;
        break;
    case "WARNING":
        $priority = LOG_WARNING;
        break;
    case "DEBUG":
        $priority = LOG_DEBUG;
        break;
    default:
        throw new CTException("Unable to connect to Syslog!", 
            "LOG_TYPE_ERROR");
    }
    
    $out = json_encode($log);
    
    if (!is_string($log['message']))
        $log['message'] = json_encode($log['message']);
    $toPrint = $log['time'] . " [" . $log['type'] . "] [" . $log['source'] . "] ";
    if ($workflowId)
        $toPrint .= "[$workflowId] ";
    $toPrint .= $log['message'] . "\n";
            
    echo($toPrint);
    
    syslog($priority, $out);
}

// Check if directory is empty
function is_dir_empty($dir) {
    if (!is_readable($dir)) 
        return false; 
    $handle = opendir($dir);
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
            return false;
        }
    }
    return true;
}

// Initialize the domain. Create it if needed
function init_domain($domainName)
{
    global $swf;
    
    // Get existing domain list
    try
    {
        $swf->describeDomain(array("name" => $domainName));
        return true;
    } catch (\Aws\Swf\Exception\UnknownResourceException $e) {
        log_out(
            "INFO", 
            basename(__FILE__), 
            "Domain doesn't exists. Creating it ..."
        );
    } catch (Exception $e) {
        log_out(
            "ERROR", 
            basename(__FILE__), 
            "Unable to get domain list ! " . $e->getMessage()
        );
        return false;
    }

    // Create domain if not existing
    try
    {
        $swf->registerDomain(array(
                "name"        => $domainName,
                "description" => "Cloud Transcode Domain",
                "workflowExecutionRetentionPeriodInDays" => 1
            ));
        return true;
    } catch (Exception $e) {
        log_out(
            "ERROR", 
            basename(__FILE__), 
            "Unable to create the domain !" . $e->getMessage()
        );
        return false;
    }
}

// Init the workflow. Create one if needed
function init_workflow($params)
{
    global $swf;

    // Save WF info
    $workflowType = array(
        "name"    => $params->{"name"},
        "version" => $params->{"version"});

    // Check if a workflow of this type already exists
    try {
        $swf->describeWorkflowType([
                "domain"       => $params->{"domain"},
                "workflowType" => $workflowType
            ]);
        return true;
    } catch (\Aws\Swf\Exception\UnknownResourceException $e) {
        log_out(
            "INFO", 
            basename(__FILE__), 
            "Workflow doesn't exists. Creating it ..."
        );
    } catch (Exception $e) {
        log_out(
            "ERROR", 
            basename(__FILE__), 
            "Unable to describe the workflow ! " . $e->getMessage()
        );
        return false;
    }

    // If not registered, we register this type of workflow
    try {
        $swf->registerWorkflowType((array) $params);
        return true;
    } catch (Exception $e) {
        log_out(
            "ERROR", 
            basename(__FILE__), 
            "Unable to register new workflow ! " . $e->getMessage()
        );
        return false;
    }
}

# Validate main configuration file against JSONM schemas
function validate_json($decoded, $schemas)
{
    $retriever = new JsonSchema\Uri\UriRetriever;
    $schema = $retriever->retrieve('file://' . __DIR__ . "/../../json_schemas/$schemas");

    $refResolver = new JsonSchema\RefResolver($retriever);
    $refResolver->resolve($schema, 'file://' . __DIR__ . "/../../json_schemas/");

    $validator = new JsonSchema\Validator();
    $validator->check($decoded, $schema);

    if ($validator->isValid())
        return false;
    
    $details = "";
    foreach ($validator->getErrors() as $error) {
        $details .= sprintf("[%s] %s\n", $error['property'], $error['message']);
    }
    
    return $details;
}

// Custom exception class for Cloud Transcode
class CTException extends Exception
{
    public $ref;
    
    // Redefine the exception so message isn't optional
    public function __construct($message, $ref = "", $code = 0, Exception $previous = null) {
        $this->ref = $ref;
    
        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }
  
    // custom string representation of object
    public function __toString() {
        return __CLASS__ . ": [{$this->ref}]: {$this->message}\n";
    }
}


// Composer for loading dependices: http://getcomposer.org/
require __DIR__ . "/../../vendor/autoload.php";

// Amazon library
use Aws\Common\Aws;
use Aws\Swf\Exception;

# Check if preper env vars are setup
if (!($key    = getenv("AWS_ACCESS_KEY_ID")))
    throw new Exception("Set 'AWS_ACCESS_KEY_ID' environment variable!");
if (!($secret = getenv("AWS_SECRET_KEY")))
    throw new Exception("Set 'AWS_SECRET_KEY' environment variable!");
if (!($region = getenv("AWS_REGION")))
    throw new Exception("Set 'AWS_REGION' environment variable!");

// Create AWS SDK instance
$aws = Aws::factory(array(
        'key'    => $key,
        'secret' => $secret,
        'region' => $region,
    ));

// SWF client
$swf = $aws->get('Swf');

// File types 
define('VIDEO', 'VIDEO');
define('AUDIO', 'AUDIO');
define('IMAGE', 'IMAGE');
define('DOC'  , 'DOC');
define('THUMB', 'THUMB');