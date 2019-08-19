<?php

namespace jtl\Connector\Gambio\Mapper;

use jtl\Connector\Drawing\ImageRelationType;

class Image extends BaseMapper
{
    protected $mapperConfig = [
        "table"    => "products_images",
        "identity" => "getId",
        "mapPull"  => [
            "id"           => "image_id",
            "relationType" => "type",
            "foreignKey"   => "foreignKey",
            "remoteUrl"    => null,
            "sort"         => "image_nr",
            "name"         => "image_name",
            "i18ns"        => "ImageI18n|addI18n|true",
        ],
    ];
    
    private $thumbConfig;
    
    const THUMBNAIL = "thumbnail";
    
    public function __construct()
    {
        parent::__construct();
        
        $this->thumbConfig = [
            'info'       => [
                $this->shopConfig['settings']['PRODUCT_IMAGE_INFO_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_INFO_HEIGHT'],
            ],
            'popup'      => [
                $this->shopConfig['settings']['PRODUCT_IMAGE_POPUP_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_POPUP_HEIGHT'],
            ],
            'thumbnails' => [
                $this->shopConfig['settings']['PRODUCT_IMAGE_THUMBNAIL_WIDTH'],
                $this->shopConfig['settings']['PRODUCT_IMAGE_THUMBNAIL_HEIGHT'],
            ],
            'gallery'    => [
                86,
                86,
            ],
        ];
    }
    
    public function pull($data = null, $limit = null)
    {
        $result = [];
        
        $query = 'SELECT p.image_id, p.image_name, p.products_id foreignKey, "product" type, (p.image_nr + 1) image_nr
            FROM products_images p
            LEFT JOIN jtl_connector_link_image l ON p.image_id = l.endpoint_id
            WHERE l.host_id IS NULL';
        $defaultQuery = 'SELECT CONCAT("pID_",p.products_id) image_id, p.products_image image_name, p.products_id foreignKey, 1 image_nr, "product" type
            FROM products p
            LEFT JOIN jtl_connector_link_image l ON CONCAT("pID_",p.products_id) = l.endpoint_id
            WHERE l.host_id IS NULL && p.products_image IS NOT NULL && p.products_image != ""';
        $combisQuery = 'SELECT CONCAT("vID_",p.products_properties_combis_id) image_id, p.combi_image as image_name, CONCAT(p.products_id, "_", p.products_properties_combis_id) foreignKey, 1 image_nr, "product" type
            FROM products_properties_combis p
            LEFT JOIN jtl_connector_link_image l ON CONCAT("vID_",p.products_properties_combis_id) = l.endpoint_id
            WHERE l.host_id IS NULL && p.combi_image IS NOT NULL && p.combi_image != ""';
        $categoriesQuery = 'SELECT CONCAT("cID_",p.categories_id) image_id, p.categories_image as image_name, p.categories_id foreignKey, "category" type, 1 image_nr
            FROM categories p
            LEFT JOIN jtl_connector_link_image l ON CONCAT("cID_",p.categories_id) = l.endpoint_id
            WHERE l.host_id IS NULL && p.categories_image IS NOT NULL && p.categories_image != ""';
        $manufacturersQuery = 'SELECT CONCAT("mID_",m.manufacturers_id) image_id, m.manufacturers_image as image_name, m.manufacturers_id foreignKey, "manufacturer" type, 1 image_nr
            FROM manufacturers m
            LEFT JOIN jtl_connector_link_image l ON CONCAT("mID_",m.manufacturers_id) = l.endpoint_id
            WHERE l.host_id IS NULL && m.manufacturers_image IS NOT NULL && m.manufacturers_image != ""';
        
        $dbResult = $this->db->query($query);
        $dbResultDefault = $this->db->query($defaultQuery);
        $dbResultCombis = $this->db->query($combisQuery);
        $dbResultCategories = $this->db->query($categoriesQuery);
        $dbResultManufacturers = $this->db->query($manufacturersQuery);
        
        $dbResult = array_merge($dbResult, $dbResultDefault, $dbResultCombis, $dbResultCategories,
            $dbResultManufacturers);
        
        $current = array_slice($dbResult, 0, $limit);
        
        foreach ($current as $modelData) {
            $model = $this->generateModel($modelData);
            
            $result[] = $model;
        }
        
        return $result;
    }
    
    protected function getImgFilename($data)
    {
        if (empty($data->getName())) {
            return substr($data->getFilename(), strrpos($data->getFilename(), '/') + 1);
        }
        
        return $data->getName();
    }
    
    protected function imageController($data, $type = self::THUMBNAIL)
    {
        $path = $this->shopConfig['img']['original'];
        $isVarCombi = strpos($data->getForeignKey()->getEndpoint(), '_') !== false;
        
        if (!empty($data->getId()->getEndpoint())) {
            $this->delete($data);
        }
        
        if ($isVarCombi && $data->getSort() == 1) {
            $path = 'images/product_images/properties_combis_images/';
        } elseif ($type == ImageRelationType::TYPE_CATEGORY) {
            $path = 'images/categories/';
        } elseif ($type == ImageRelationType::TYPE_MANUFACTURER) {
            $path = 'images/manufacturers/';
        } elseif (!$isVarCombi && $data->getSort() == 1) {
            $type = self::THUMBNAIL;
        }
        
        $imgFilename = $this->getImgFilename($data);
        
        if (!rename($data->getFilename(), $this->shopConfig['shop']['path'] . $path . $imgFilename)) {
            throw new \Exception('Cannot move uploaded image file');
        }
        
        if ($isVarCombi) {
            return $this->handleCombiChildThumbnail($data, $imgFilename);
        }
        
        switch ($type) {
            case self::THUMBNAIL:
                $this->db->query('DELETE FROM products_images WHERE image_id="' . $data->getId()->getEndpoint() . '"');
                $this->handleProductThumbnail($data, $imgFilename);
                
                break;
            case ImageRelationType::TYPE_PRODUCT:
                $this->generateThumbs($imgFilename);
                $this->db->query('DELETE FROM products_images WHERE image_id="' . $data->getId()->getEndpoint() . '"');
                $this->db->query('DELETE FROM gm_prd_img_alt WHERE image_id="' . $data->getId()->getEndpoint() . '"');
                $this->handleProductImage($data, $imgFilename);
                
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $manufacturersObj = new \stdClass();
                $manufacturersObj->manufacturers_image = 'manufacturers/' . $imgFilename;
                $this->db->updateRow($manufacturersObj, 'manufacturers', 'manufacturers_id',
                    $data->getForeignKey()->getEndpoint());
                $data->getId()->setEndpoint('mID_' . $data->getForeignKey()->getEndpoint());
                
                break;
            case ImageRelationType::TYPE_CATEGORY:
                $categoryObj = new \stdClass();
                $categoryObj->categories_image = $imgFilename;
                $this->db->updateRow($categoryObj, 'categories', 'categories_id',
                    $data->getForeignKey()->getEndpoint());
                $data->getId()->setEndpoint('cID_' . $data->getForeignKey()->getEndpoint());
                
                break;
        }
        
        return $data;
    }
    
    protected function handleProductImage($data, $imgFilename)
    {
        $imgObj = new \stdClass();
        $imgObj->products_id = $data->getForeignKey()->getEndpoint();
        $imgObj->image_name = $imgFilename;
        $imgObj->image_nr = ($data->getSort() - 1);
        
        $newIdQuery = $this->db->deleteInsertRow($imgObj, 'products_images', ['image_nr', 'products_id'],
            [$imgObj->image_nr, $imgObj->products_id]);
        
        $newId = $newIdQuery->getKey();
        
        foreach ($data->getI18ns() as $i18n) {
            $this->db->query('INSERT INTO gm_prd_img_alt SET gm_alt_text="' . $i18n->getAltText() . '", products_id="' . $imgObj->products_id . '", image_id="' . $newId . '", language_id=' . $this->locale2id($i18n->getLanguageISO()));
        }
        
        $this->db->query('DELETE FROM jtl_connector_link_image WHERE host_id=' . $data->getId()->getHost());
        $this->db->query('INSERT INTO jtl_connector_link_image SET host_id="' . $data->getId()->getHost() . '", endpoint_id="' . $newId . '"');
        
        $data->getId()->setEndpoint($newId);
    }
    
    protected function handleCombiChildThumbnail($data, $imgFilename)
    {
        $combisObj = new \stdClass();
        $combisObj->combi_image = $imgFilename;
        $combisId = explode('_', $data->getForeignKey()->getEndpoint())[1];
        
        $this->db->updateRow($combisObj, 'products_properties_combis', 'products_properties_combis_id', $combisId);
        $this->db->query(sprintf('INSERT INTO jtl_connector_link_image SET host_id="%s", endpoint_id="vID_%s"'),
            $data->getId()->getHost(),
            $combisId
        );
    }
    
    protected function handleProductThumbnail($data, $imgFilename)
    {
        $productsObj = new \stdClass();
        $productsObj->products_image = $imgFilename;
        
        $this->db->updateRow($productsObj, 'products', 'products_id', $data->getForeignKey()->getEndpoint());
        
        $data->getId()->setEndpoint('pID_' . $data->getForeignKey()->getEndpoint());
        
        foreach ($data->getI18ns() as $i18n) {
            $this->db->query('UPDATE products_description SET gm_alt_text="' . $i18n->getAltText() . '" WHERE products_id="' . $data->getForeignKey()->getEndpoint() . '" && language_id=' . $this->locale2id($i18n->getLanguageISO()));
        }
        
        $this->db->query('DELETE FROM jtl_connector_link_image WHERE endpoint_id="' . $data->getId()->getEndpoint() . '"');
        $this->db->query('DELETE FROM jtl_connector_link_image WHERE host_id=' . $data->getId()->getHost());
        $this->db->query('INSERT INTO jtl_connector_link_image SET host_id="' . $data->getId()->getHost() . '", endpoint_id="' . $data->getId()->getEndpoint() . '"');
    }
    
    public function push($data, $dbObj = null)
    {
        if (get_class($data) === 'jtl\Connector\Model\Image') {
            $this->imageController($data, $data->getRelationType());
        } else {
            throw new \Exception('Pushed data is not an image object');
        }
    }
    
    public function delete($data)
    {
        if (get_class($data) === 'jtl\Connector\Model\Image') {
            $path = $this->shopConfig['img']['original'];
            
            switch ($data->getRelationType()) {
                case ImageRelationType::TYPE_CATEGORY:
                    $path = 'images/categories/';
                    $oldImage = $this->db->query('SELECT categories_image FROM categories WHERE categories_id = "' . $data->getForeignKey()->getEndpoint() . '"');
                    $oldImage = $oldImage[0]['categories_image'];
                    
                    $categoryObj = new \stdClass();
                    $categoryObj->categories_image = null;
                    
                    $this->db->updateRow($categoryObj, 'categories', 'categories_id',
                        $data->getForeignKey()->getEndpoint());
                    
                    break;
                
                case ImageRelationType::TYPE_MANUFACTURER:
                    $path = 'images/manufacturers/';
                    $oldImage = $this->db->query('SELECT manufacturers_image FROM manufacturers WHERE manufacturers_id = "' . $data->getForeignKey()->getEndpoint() . '"');
                    $oldImage = $oldImage[0]['manufacturers_image'];
                    
                    $manufacturersObj = new \stdClass();
                    $manufacturersObj->categories_image = null;
                    
                    $this->db->updateRow($manufacturersObj, 'manufacturers', 'manufacturers_id',
                        $data->getForeignKey()->getEndpoint());
                    
                    break;
                
                case ImageRelationType::TYPE_PRODUCT:
                    /* If is Thumbnail */
                    if ($data->getSort() == 0) {
                        $oldImage = $this->db->query('SELECT products_image FROM products WHERE products_id = "' . $data->getForeignKey()->getEndpoint() . '"');
                        $oldImage = !empty($oldImage[0]['products_image']) ? $oldImage[0]['products_image'] : null;
                        
                        if (isset($oldImage)) {
                            $this->db->query('UPDATE products SET products_image="" WHERE products_id="' . $data->getForeignKey()->getEndpoint() . '"');
                        }
                        
                        $additionalImages = $this->db->query('SELECT image_name FROM products_images WHERE products_id="' . $data->getForeignKey()->getEndpoint() . '"');
                        
                        foreach ($additionalImages as $image) {
                            if (!empty($image['image_name'])) {
                                @unlink($this->shopConfig['shop']['path'] . $this->shopConfig['img']['original'] . $image['image_name']);
                                
                                foreach ($this->thumbConfig as $folder => $sizes) {
                                    @unlink($this->shopConfig['shop']['path'] . $this->shopConfig['img'][$folder] . $image['image_name']);
                                }
                            }
                        }
                        
                        $this->db->query('DELETE FROM products_images WHERE products_id="' . $data->getForeignKey()->getEndpoint() . '"');
                    } elseif ($data->getSort() == 1) {
                        if (strpos($data->getForeignKey()->getEndpoint(), '_') !== false) {
                            $path = 'images/product_images/properties_combis_images/';
                            $combisId = explode('_', $data->getForeignKey()->getEndpoint());
                            $combisId = $combisId[1];
                            
                            if (!empty($combisId)) {
                                $oldImage = $this->db->query('SELECT combi_image FROM products_properties_combis WHERE products_properties_combis_id = "' . $combisId . '"');
                                $oldImage = !empty($oldImage[0]['combi_image']) ? $oldImage[0]['combi_image'] : null;
                                
                                $combisObj = new \stdClass();
                                $combisObj->combi_image = null;
                                
                                $this->db->updateRow($combisObj, 'products_properties_combis',
                                    'products_properties_combis_id', $combisId);
                            }
                        } else {
                            $oldImage = $this->db->query('SELECT products_image FROM products WHERE products_id = "' . $data->getForeignKey()->getEndpoint() . '"');
                            $oldImage = !empty($oldImage[0]['products_image']) ? $oldImage[0]['products_image'] : null;
                            
                            $productsObj = new \stdClass();
                            $productsObj->products_image = null;
                            
                            $this->db->updateRow($productsObj, 'products', 'products_id',
                                $data->getForeignKey()->getEndpoint());
                        }
                    } else {
                        if ($data->getId()->getEndpoint() != '') {
                            $oldImage = $this->db->query('SELECT image_name FROM products_images WHERE image_id = "' . $data->getId()->getEndpoint() . '"');
                            $oldImage = !empty($oldImage[0]['image_name']) ? $oldImage[0]['image_name'] : null;
                            
                            $this->db->query('DELETE FROM products_images WHERE image_id="' . $data->getId()->getEndpoint() . '"');
                        }
                    }
                    
                    break;
            }
            
            if (isset($oldImage)) {
                @unlink($this->shopConfig['shop']['path'] . $path . $oldImage);
            }
            
            foreach ($this->thumbConfig as $folder => $sizes) {
                if (isset($oldImage) && file_exists($this->shopConfig['shop']['path'] . $this->shopConfig['img'][$folder] . $oldImage)) {
                    unlink($this->shopConfig['shop']['path'] . $this->shopConfig['img'][$folder] . $oldImage);
                }
            }
            
            $this->db->query('DELETE FROM jtl_connector_link_image WHERE endpoint_id="' . $data->getId()->getEndpoint() . '"');
            
            return $data;
        } else {
            throw new \Exception('Pushed data is not an image object');
        }
    }
    
    public function statistic()
    {
        $totalImages = 0;
        
        $productQuery = $this->db->query("
            SELECT p.*
            FROM (
                SELECT CONCAT('pID_',p.products_id) as imgId
                FROM products p
                WHERE p.products_image IS NOT NULL && p.products_image != ''
            ) p
            LEFT JOIN jtl_connector_link_image l ON p.imgId = l.endpoint_id
            WHERE l.host_id IS NULL
        ");
        
        $combiQuery = $this->db->query("
            SELECT p.*
            FROM (
                SELECT CONCAT('vID_',p.products_properties_combis_id) as imgId
                FROM products_properties_combis p
                WHERE p.combi_image IS NOT NULL && p.combi_image != ''
            ) p
            LEFT JOIN jtl_connector_link_image l ON p.imgId = l.endpoint_id
            WHERE l.host_id IS NULL
        ");
        
        $categoryQuery = $this->db->query("
            SELECT c.*
            FROM (
                SELECT CONCAT('cID_',c.categories_id) as imgId
                FROM categories c
                WHERE c.categories_image IS NOT NULL && c.categories_image != ''
            ) c
            LEFT JOIN jtl_connector_link_image l ON c.imgId = l.endpoint_id
            WHERE l.host_id IS NULL
        ");
        
        $manufacturersQuery = $this->db->query("
            SELECT m.*
            FROM (
                SELECT CONCAT('mID_',m.manufacturers_id) as imgId
                FROM manufacturers m
                WHERE m.manufacturers_image IS NOT NULL && m.manufacturers_image != ''
            ) m
            LEFT JOIN jtl_connector_link_image l ON m.imgId = l.endpoint_id
            WHERE l.host_id IS NULL
        ");
        
        $imageQuery = $this->db->query("
            SELECT i.* FROM products_images i
            LEFT JOIN jtl_connector_link_image l ON i.image_id = l.endpoint_id
            WHERE l.host_id IS NULL
        ");
        
        $totalImages += count($productQuery);
        $totalImages += count($combiQuery);
        $totalImages += count($categoryQuery);
        $totalImages += count($manufacturersQuery);
        $totalImages += count($imageQuery);
        
        return $totalImages;
    }
    
    protected function remoteUrl($data)
    {
        if ($data['type'] == ImageRelationType::TYPE_CATEGORY) {
            return $this->shopConfig['shop']['fullUrl'] . 'images/categories/' . $data['image_name'];
        } elseif ($data['type'] == ImageRelationType::TYPE_MANUFACTURER) {
            return $this->shopConfig['shop']['fullUrl'] . 'images/' . $data['image_name'];
        } else {
            if (strpos($data['image_id'], 'vID_') !== false) {
                return $this->shopConfig['shop']['fullUrl'] . 'images/product_images/properties_combis_images/' . $data['image_name'];
            } else {
                return $this->shopConfig['shop']['fullUrl'] . $this->shopConfig['img']['original'] . $data['image_name'];
            }
        }
    }
    
    private function generateThumbs($fileName)
    {
        $imgPath = $this->shopConfig['shop']['path'] . $this->shopConfig['img']['original'] . $fileName;
        $imgInfo = getimagesize($imgPath);
        
        switch ($imgInfo[2]) {
            case 1:
                $image = imagecreatefromgif($imgPath);
                break;
            case 2:
                $image = imagecreatefromjpeg($imgPath);
                break;
            case 3:
                $image = imagecreatefrompng($imgPath);
                break;
        }
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        foreach ($this->thumbConfig as $folder => $sizes) {
            $thumb_width = $sizes[0];
            $thumb_height = $sizes[1];
            
            $new_width = $thumb_width;
            $new_height = round($new_width * ($height / $width));
            $new_x = 0;
            $new_y = round(($thumb_height - $new_height) / 2);
            
            if ($this->connectorConfig->thumbs === 'fill') {
                $next = $new_height < $thumb_height;
            } else {
                $next = $new_height > $thumb_height;
            }
            
            if ($next) {
                $new_height = $thumb_height;
                $new_width = round($new_height * ($width / $height));
                $new_x = round(($thumb_width - $new_width) / 2);
                $new_y = 0;
            }
            
            $thumb = imagecreatetruecolor($thumb_width, $thumb_height);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            
            if ($imgInfo[2] == 1 || $imgInfo[2] == 3) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                imagefilledrectangle($thumb, 0, 0, $thumb_width, $thumb_height, $transparent);
            }
            
            imagecopyresampled(
                $thumb,
                $image,
                $new_x,
                $new_y,
                0,
                0,
                $new_width,
                $new_height,
                $width,
                $height
            );
            
            $imgPath = $this->shopConfig['shop']['path'] . $this->shopConfig['img'][$folder] . $fileName;
            
            switch ($imgInfo[2]) {
                case 1:
                    imagegif($thumb, $imgPath);
                    break;
                case 2:
                    imagejpeg($thumb, $imgPath);
                    break;
                case 3:
                    imagepng($thumb, $imgPath);
                    break;
            }
        }
    }
}
