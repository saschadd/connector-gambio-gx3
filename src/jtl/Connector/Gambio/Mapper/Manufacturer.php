<?php
namespace jtl\Connector\Gambio\Mapper;

use jtl\Connector\Gambio\Controller\BaseController;
use jtl\Connector\Gambio\Mapper\BaseMapper;
use jtl\Connector\Model\ManufacturerI18n;

class Manufacturer extends BaseMapper
{
    protected $mapperConfig = array(
        "table" => "manufacturers",
        "query" => "SELECT m.* FROM manufacturers m
            LEFT JOIN jtl_connector_link_manufacturer l ON m.manufacturers_id = l.endpoint_id
            WHERE l.host_id IS NULL",
        "where" => "manufacturers_id",
        "identity" => "getId",
        "mapPull" => array(
            "id" => "manufacturers_id",
            "name" => "manufacturers_name",
            "websiteUrl" => null
        ),
        "mapPush" => array(
            "manufacturers_id" => "id",
            "manufacturers_name" => "name"
        )
    );

    protected function websiteUrl($data)
    {
        $result = $this->db->query('SELECT m.manufacturers_url, l.languages_id FROM languages l LEFT JOIN manufacturers_info m ON m.languages_id=l.languages_id WHERE m.manufacturers_id='.$data['manufacturers_id'].' && l.code="'.$this->shopConfig['settings']['DEFAULT_LANGUAGE'].'"');
        
        if (count($result) > 0) {
            return $result[0]['manufacturers_url'];
        }
    }

    public function delete($data)
    {
        $id = $data->getId()->getEndpoint();

        if (!empty($id) && $id != '') {
            try {
                $this->db->query('DELETE FROM manufacturers WHERE manufacturers_id='.$id);
                $this->db->query('DELETE FROM manufacturers_info WHERE manufacturers_id='.$id);

                //$this->db->query('DELETE FROM jtl_connector_link WHERE type=32 && endpointId="'.$id.'"');
            }
            catch (\Exception $e) {                
            }
        }

        return $data;
    }

    public function push($data, $dbObj = null)
    {
        /** @var \jtl\Connector\Model\Manufacturer $data */
        $return = parent::push($data, $dbObj);

        $props = ['manufacturers_url', 'manufacturers_meta_title', 'manufacturers_meta_description', 'manufacturers_meta_keywords'];

        $manufacturersInfoObj = new \stdClass();
        $manufacturersInfoObj->manufacturers_id = $data->getId()->getEndpoint();
        $manufacturersInfoObj->manufacturers_url = $data->getWebsiteUrl();

        $this->db->query('DELETE FROM manufacturers_info WHERE manufacturers_id = '. $manufacturersInfoObj->manufacturers_id);
        $languages = $this->db->query('SELECT languages_id, code FROM languages');
        foreach ($languages as $language) {
            /** @var ManufacturerI18n $i18n */
            $i18n = BaseController::findI18n($data->getI18ns(), $language['code']);
            $manufacturersInfoObj->languages_id = $language['languages_id'];
            $manufacturersInfoObj->manufacturers_meta_title = '';
            $manufacturersInfoObj->manufacturers_meta_keywords = '';
            $manufacturersInfoObj->manufacturers_meta_description = '';
            if($i18n !== false) {
                $manufacturersInfoObj->manufacturers_meta_title = $i18n->getTitleTag();
                $manufacturersInfoObj->manufacturers_meta_keywords = $i18n->getMetaKeywords();
                $manufacturersInfoObj->manufacturers_meta_description = $i18n->getMetaDescription();
            }

            $empty = true;
            foreach($props as $prop) {
                if(isset($manufacturersInfoObj->{$prop}) && !empty($manufacturersInfoObj->{$prop})) {
                    $empty = false;
                    break;
                }
            }

            if(!$empty) {
                $this->db->insertRow($manufacturersInfoObj, 'manufacturers_info');
            }
        }

        return $return;
    }
}
