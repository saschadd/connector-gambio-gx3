<?php
namespace jtl\Connector\Gambio;

use \jtl\Connector\Core\Rpc\RequestPacket;
use \jtl\Connector\Core\Utilities\RpcMethod;
use \jtl\Connector\Core\Database\Mysql;
use \jtl\Connector\Core\Rpc\ResponsePacket;
use \jtl\Connector\Session\SessionHelper;
use \jtl\Connector\Base\Connector as BaseConnector;
use \jtl\Connector\Core\Rpc\Error as Error;
use \jtl\Connector\Core\Http\Response;
use \jtl\Connector\Core\Rpc\Method;
use \jtl\Connector\Gambio\Mapper\PrimaryKeyMapper;
use \jtl\Connector\Core\Config\Config;
use \jtl\Connector\Core\Config\Loader\Json as ConfigJson;
use \jtl\Connector\Core\Config\Loader\System as ConfigSystem;
use \jtl\Connector\Result\Action;
use \jtl\Connector\Gambio\Auth\TokenLoader;
use \jtl\Connector\Gambio\Checksum\ChecksumLoader;
use \jtl\Connector\Core\Logger\Logger;

class Gambio extends BaseConnector
{
    protected $controller;
    protected $action;

    public function initialize()
    {
        $this->initConnectorConfig();

        $session = new SessionHelper("gambio");

        set_error_handler(array($this,'errorHandler'), E_ALL);
        set_exception_handler(array($this,'exceptionHandler'));
        register_shutdown_function(array($this,'shutdownHandler'));

        if (!isset($session->shopConfig)) {
            $session->shopConfig = $this->readConfigFile();
        }
        if (!isset($session->connectorConfig)) {
            $session->connectorConfig = json_decode(@file_get_contents(CONNECTOR_DIR.'/config/config.json'));
        }

        $db = Mysql::getInstance();

        if (!$db->isConnected()) {
            $db->connect(array(
                "host" => $session->shopConfig['db']["host"],
                "user" => $session->shopConfig['db']["user"],
                "password" => $session->shopConfig['db']["pass"],
                "name" => $session->shopConfig['db']["name"]
            ));
        }

        $db->setNames();

        if (!isset($session->shopConfig['settings'])) {
            $session->shopConfig += $this->readConfigDb($db);
        }

        $this->setPrimaryKeyMapper(new PrimaryKeyMapper());
        $this->setTokenLoader(new TokenLoader());
        $this->setChecksumLoader(new ChecksumLoader());
    }

    protected function initConnectorConfig()
    {
        $config = null;

        if (isset($_SESSION['config'])) {
            $config = $_SESSION['config'];
        }

        if (empty($config)) {
            if (!is_null($this->config)) {
                $config = $this->getConfig();
            }

            if (empty($config)) {
                $json = new ConfigJson(CONNECTOR_DIR . '/config/config.json');
                $config = new Config(array(
                    $json,
                    new ConfigSystem()
                ));
            }
        }

        if (!isset($_SESSION['config'])) {
            $_SESSION['config'] = $config;
        }

        $this->setConfig($config);
    }

    private function readConfigFile()
    {
        $connectorConfig = $this->getConfig();
        require_once($connectorConfig->read('connector_root') . '/includes/configure.php');

        return array(
            'shop' => array(
                'url' => HTTP_SERVER,
                'folder' => DIR_WS_CATALOG,
                'fullUrl' => HTTP_SERVER.DIR_WS_CATALOG
            ),
            'db' => array(
                'host' => DB_SERVER,
                'name' => DB_DATABASE,
                'user' => DB_SERVER_USERNAME,
                'pass' => DB_SERVER_PASSWORD
            ),
            'img' => array(
                'original' => DIR_WS_ORIGINAL_IMAGES,
                'thumbnails' => DIR_WS_THUMBNAIL_IMAGES,
                'info' => DIR_WS_INFO_IMAGES,
                'popup' => DIR_WS_POPUP_IMAGES
            )
        );
    }

    private function readConfigDb($db)
    {
        $configDb = $db->query("SElECT configuration_key,configuration_value FROM configuration");

        $return = array();

        foreach ($configDb as $entry) {
            $return[$entry['configuration_key']] = $entry['configuration_value'] == 'true' ? 1 : ($entry['configuration_value'] == 'false' ? 0 : $entry['configuration_value']);
        }

        return array(
            'settings' => $return
        );
    }

    public function canHandle()
    {
        $controller = RpcMethod::buildController($this->getMethod()->getController());
        $class = "\\jtl\\Connector\\Gambio\\Controller\\{$controller}";

        if (class_exists($class)) {
            $this->controller = $class::getInstance();
            $this->action = RpcMethod::buildAction($this->getMethod()->getAction());

            return is_callable(array($this->controller, $this->action));
        }

        return false;
    }

    public function handle(RequestPacket $requestpacket)
    {
        $this->controller->setMethod($this->getMethod());

        $result = array();

        if ($requestpacket->getMethod() == 'image.push') {
            $action = new Action();

            $result = $this->controller->{$this->action}($requestpacket->getParams());

            $action->setHandled(true)
                ->setResult($result->getResult())
                ->setError($result->getError());

            return $action;

        } elseif ($this->action === Method::ACTION_PUSH || $this->action === Method::ACTION_DELETE) {
            if (!is_array($requestpacket->getParams())) {
                throw new \Exception('data is not an array');
            }

            $action = new Action();
            $results = array();
            $errors = array();

            foreach ($requestpacket->getParams() as $param) {
                $result = $this->controller->{$this->action}($param);
                $results[] = $result->getResult();
            }

            $action->setHandled(true)
                ->setResult($results)
                ->setError($result->getError());

            return $action;
        } else {
            return $this->controller->{$this->action}($requestpacket->getParams());
        }
    }

    public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
    {
        $types = array(
            E_ERROR => array(Logger::ERROR, 'E_ERROR'),
            E_PARSE => array(Logger::WARNING, 'E_PARSE'),
            E_CORE_ERROR => array(Logger::ERROR, 'E_CORE_ERROR'),
            E_CORE_WARNING => array(Logger::WARNING, 'E_CORE_WARNING'),
            E_CORE_ERROR => array(Logger::ERROR, 'E_COMPILE_ERROR'),
            E_CORE_WARNING => array(Logger::WARNING, 'E_COMPILE_WARNING'),
            E_USER_ERROR => array(Logger::ERROR, 'E_USER_ERROR'),
            E_RECOVERABLE_ERROR => array(Logger::ERROR, 'E_RECOVERABLE_ERROR'),
            E_DEPRECATED => array(Logger::INFO, 'E_DEPRECATED'),
            E_USER_DEPRECATED => array(Logger::INFO, 'E_USER_DEPRECATED')
        );

        if (isset($types[$errno])) {
            $err = "(" . $types[$errno][1] . ") File ({$errfile}, {$errline}): {$errstr}";
            Logger::write($err, $types[$errno][0], 'global');
        }
    }

    public function exceptionHandler(\Exception $exception)
    {
        $trace = $exception->getTrace();
        if (isset($trace[0]['args'][0])) {
            $requestpacket = $trace[0]['args'][0];
        }

        $error = new Error();
        $error->setCode($exception->getCode())
            ->setData("Exception: " . substr(strrchr(get_class($exception), "\\"), 1) . " - File: {$exception->getFile()} - Line: {$exception->getLine()}")
            ->setMessage($exception->getMessage());

        $responsepacket = new ResponsePacket();
        $responsepacket->setError($error)
            ->setJtlrpc("2.0");

        if (isset($requestpacket) && $requestpacket !== null && is_object($requestpacket) && get_class($requestpacket) == "jtl\\Connector\\Core\\Rpc\\RequestPacket") {
            $responsepacket->setId($requestpacket->getId());
        }

        Response::send($responsepacket);
    }

    public function shutdownHandler()
    {
        if (($err = error_get_last())) {
            if ($err['type'] != 2 && $err['type'] != 8) {
                ob_clean();

                $error = new Error();
                $error->setCode($err['type'])
                    ->setData('Shutdown! File: ' . $err['file'] . ' - Line: ' . $err['line'])
                    ->setMessage($err['message']);

                $responsepacket = new ResponsePacket();
                $responsepacket->setError($error)
                    ->setId('unknown')
                    ->setJtlrpc("2.0");

                Response::send($responsepacket);
            }
        }
    }
}
