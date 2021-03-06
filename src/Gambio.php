<?php
namespace jtl\Connector\Gambio;

use \jtl\Connector\Core\Rpc\RequestPacket;
use \jtl\Connector\Core\Utilities\RpcMethod;
use \jtl\Connector\Core\Database\Mysql;
use \jtl\Connector\Core\Rpc\ResponsePacket;
use jtl\Connector\Model\Product;
use \jtl\Connector\Session\SessionHelper;
use \jtl\Connector\Base\Connector as BaseConnector;
use \jtl\Connector\Core\Rpc\Error as Error;
use \jtl\Connector\Core\Http\Response;
use \jtl\Connector\Core\Rpc\Method;
use \jtl\Connector\Gambio\Mapper\PrimaryKeyMapper;
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
        $session = new SessionHelper("gambio");

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

        if(!isset($session->connectorConfig->utf8) || $session->connectorConfig->utf8 !== '1') {
            $db->setNames();
            $db->setCharset();
        }

        if (!isset($session->shopConfig['settings'])) {
            $session->shopConfig += $this->readConfigDb($db);
        }

        $this->update($db);

        $this->setPrimaryKeyMapper(new PrimaryKeyMapper());
        $this->setTokenLoader(new TokenLoader());
        $this->setChecksumLoader(new ChecksumLoader());
    }

    private function readConfigFile()
    {
        $gx_version = "";
        require_once(CONNECTOR_DIR.'/../includes/configure.php');
        require_once(CONNECTOR_DIR.'/../release_info.php');
        
        return array(
            'shop' => array(
                'url' => HTTP_SERVER,
                'folder' => DIR_WS_CATALOG,
                'path' => DIR_FS_DOCUMENT_ROOT,
                'fullUrl' => HTTP_SERVER.DIR_WS_CATALOG,
                'version' => ltrim($gx_version,'v')
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
                'popup' => DIR_WS_POPUP_IMAGES,
                'gallery' => 'images/product_images/gallery_images/'
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

    private function update($db)
    {
        if(version_compare(file_get_contents(CONNECTOR_DIR.'/db/version'), CONNECTOR_VERSION) == -1) {
            $versions = [];
            foreach (new \DirectoryIterator(CONNECTOR_DIR.'/db/updates') as $item) {
                if($item->isFile()) {
                    $versions[] = $item->getBasename('.php');
                }
            }

            sort($versions);

            foreach ($versions as $version) {
                if(version_compare(file_get_contents(CONNECTOR_DIR.'/db/version'), $version) == -1) {
                    include(CONNECTOR_DIR.'/db/updates/' . $version . '.php');
                }
            }
        }
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

        if ($this->action === Method::ACTION_PUSH || $this->action === Method::ACTION_DELETE) {
            if (!is_array($requestpacket->getParams())) {
                throw new \Exception('data is not an array');
            }

            $action = new Action();
            $results = array();
            $errors = array();
    
            $link = Mysql::getInstance();
            $link->DB()->begin_transaction();
            
            foreach ($requestpacket->getParams() as $param) {
                $result = $this->controller->{$this->action}($param);
    
                if ($result->getError()) {
                    $link->rollback();
                    $message = sprintf('Type: %s %s', get_class($param), $result->getError()->getMessage());
                    if (method_exists($param, 'getId')) {
                        if ($param instanceof Product) {
                            $message = sprintf('Type: Product Host-Id: %s SKU: %s %s', $param->getId()->getHost(), $param->getSku(), $result->getError()->getMessage());
                        } else {
                            $message = sprintf('Type: %s Host-Id: %s %s', get_class($param), $param->getId()->getHost(), $result->getError()->getMessage());
                        }
                    }
        
                    throw new \Exception($message);
                }
                
                $results[] = $result->getResult();
            }

            $link->commit();
            
            $action->setHandled(true)
                ->setResult($results)
                ->setError($result->getError());

            return $action;
        } else {
            return $this->controller->{$this->action}($requestpacket->getParams());
        }
    }
}
