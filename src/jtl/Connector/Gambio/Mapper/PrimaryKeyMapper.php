<?php
namespace jtl\Connector\Gambio\Mapper;

use jtl\Connector\Mapper\IPrimaryKeyMapper;
use jtl\Connector\Core\Database\Mysql;
use jtl\Connector\Core\Logger\Logger;

class PrimaryKeyMapper implements IPrimaryKeyMapper
{
    protected $db;

    protected static $types = array(
        1 => 'category',
        2 => 'customer',
        4 => 'customer_order',
        8 => 'delivery_note',
        16 => 'image',
        32 => 'manufacturer',
        64 => 'product',
        512 => 'payment',
        1024 => 'crossselling',
        2048 => 'crossselling_group'
    );

    public function __construct()
    {
        $this->db = Mysql::getInstance();
    }

    public function getHostId($endpointId, $type)
    {
        $dbResult = $this->db->query("SELECT host_id FROM jtl_connector_link_".static::$types[$type]." WHERE endpoint_id = '".$endpointId."'");

        $host_id = (count($dbResult) > 0) ? $dbResult[0]['host_id'] : null;

        Logger::write(sprintf('Trying to get host_id with endpoint_id (%s) and type (%s) ... host_id: (%s)', $endpointId, $type, $host_id), Logger::DEBUG, 'linker');

        return $host_id;
    }

    public function getEndpointId($hostId, $type, $relationType = null)
    {
        $dbResult = $this->db->query("SELECT endpoint_id FROM jtl_connector_link_".static::$types[$type]." WHERE host_id = ".$hostId);

        $endpoint_id = (count($dbResult) > 0) ? $dbResult[0]['endpoint_id'] : null;

        Logger::write(sprintf('Trying to get endpoint_id with host_id (%s) and type (%s) ... endpoint_id: (%s)', $hostId, $type, $endpoint_id), Logger::DEBUG, 'linker');

        return (string) $endpoint_id;
    }

    public function save($endpointId, $hostId, $type)
    {
        Logger::write(sprintf('Save link with endpoint_id (%s), host_id (%s) and type (%s)', $endpointId, $hostId, $type), Logger::DEBUG, 'linker');

        $this->db->query("INSERT IGNORE INTO jtl_connector_link_".static::$types[$type]." (endpoint_id, host_id) VALUES ('".$endpointId."',".$hostId.")");
    }

    public function delete($endpointId = null, $hostId = null, $type)
    {
        Logger::write(sprintf('Delete link with endpoint_id (%s), host_id (%s) and type (%s)', $endpointId, $hostId, $type), Logger::DEBUG, 'linker');

        $where = '';

        if ($endpointId && $endpointId != '') {
            $where = 'endpoint_id = "'.$endpointId.'"';
        }

        if ($hostId) {
            $where = 'host_id = '.$hostId;
        }

        $this->db->query('DELETE FROM jtl_connector_link_'.static::$types[$type].' WHERE '.$where);
    }

    public function clear()
    {
        Logger::write('Clearing linking tables', Logger::DEBUG, 'linker');

        foreach(static::$types as $id => $name) {
            $this->db->query('TRUNCATE TABLE jtl_connector_link_'.$name);
        }

        return true;
    }

    public function gc()
    {
        return true;
    }
}
