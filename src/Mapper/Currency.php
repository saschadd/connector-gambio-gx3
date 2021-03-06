<?php
namespace jtl\Connector\Gambio\Mapper;

use jtl\Connector\Gambio\Mapper\BaseMapper;

class Currency extends BaseMapper
{
    protected $mapperConfig = array(
        "table" => "currencies",
        "where" => "currencies_id",
        "identity" => "getId",
        "getMethod" => "getCurrencies",
        "mapPull" => array(
            "id" => "currencies_id",
            "name" => "title",
            "factor" => "value",
            "delimiterCent" => "decimal_point",
            "delimiterThousand" => "thousands_point",
            "isDefault" => null,
            "iso" => "code"
        ),
        "mapPush" => array(
            "currencies_id" => "id",
            "title" => "name",
            "value" => "factor",
            "decimal_point" => "delimiterCent",
            "thousands_point" => "delimiterThousand",
            "code" => null,
            "decimal_places" => null,
            "symbol_right" => "name"
        )
    );

    public function push($data, $dbObj = null)
    {
        $currencies = $data->getCurrencies();

        if (!empty($currencies)) {
            foreach ($data->getCurrencies() as $currency) {
                $check = $this->db->query('SELECT currencies_id FROM currencies WHERE code="' . $currency->getIso() . '"');
                if (count($check) > 0) {
                    $currency->getId()->setEndpoint($check[0]['currencies_id']);
                }
            }

            return parent::push($data, $dbObj);
        }
    }

    protected function isDefault($data)
    {
        return $data['code'] == $this->shopConfig['settings']['DEFAULT_CURRENCY'] ? true : false;
    }

    protected function code($data)
    {
        if ($data->getIsDefault() === true) {
            $this->db->query('UPDATE configuration SET configuration_value="'.$data->getIso().'" WHERE configuration_key="DEFAULT_CURRENCY"');
        }

        return $data->getIso();
    }
    
    protected function decimal_places($data)
    {
        return 2;
    }
}
