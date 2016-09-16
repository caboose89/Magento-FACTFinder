<?php
/**
 * FACTFinder_Core
 *
 * @category Mage
 * @package FACTFinder_Core
 * @author Flagbit Magento Team <magento@flagbit.de>
 * @copyright Copyright (c) 2016 Flagbit GmbH & Co. KG
 * @license https://opensource.org/licenses/MIT  The MIT License (MIT)
 * @link http://www.flagbit.de
 *
 */

/**
 * Model class
 *
 * This class provides the Product export
 *
 * @method getResource() FACTFinder_Core_Model_Resource_Export
 *
 * @category Mage
 * @package FACTFinder_Core
 * @author Flagbit Magento Team <magento@flagbit.de>
 * @copyright Copyright (c) 2016 Flagbit GmbH & Co. KG (http://www.flagbit.de)
 * @license https://opensource.org/licenses/MIT  The MIT License (MIT)
 * @link http://www.flagbit.de
 */
class FACTFinder_Core_Model_Export_Product extends Mage_Core_Model_Abstract
{

    const FILENAME_PATTERN = 'store_%s_product.csv';
    const CSV_DELIMITER = ';';
    const FILE_VALIDATOR = 'factfinder/file_validator_product';

    /**
     * Option ID to Value Mapping Array
     * @var mixed
     */
    protected $_optionIdToValue = null;

    /**
     * Products to Category Path Mapping
     *
     * @var mixed
     */
    protected $_productsToCategoryPath = null;

    /**
     * Category Names by ID
     * @var mixed
     */
    protected $_categoryNames = null;

    /**
     * export attribute codes
     * @var mixed
     */
    protected $_exportAttributeCodes = null;

    /**
     * export attribute objects
     * @var mixed
     */
    protected $_exportAttributes = null;

    /**
     * helper to generate the image urls
     * @var Mage_Catalog_Helper_Image
     */
    protected $_imageHelper = null;

    /**
     * add CSV Row
     *
     * @param array $data
     */
    protected $_lines = array();

    /**
     * @var FACTFinder_Core_Model_File
     */
    protected $_file = null;

    /**
     * @var array
     */
    protected $_defaultHeader = array(
        'id',
        'parent_id',
        'sku',
        'category',
        'filterable_attributes',
        'searchable_attributes',
        'numerical_attributes',
    );

    /**
     * @var
     */
    protected $_engine;


    /**
     * Init resource model
     *
     */
    protected function _construct()
    {
        $this->_init('factfinder/export');
        $this->_engine = Mage::helper('catalogsearch')->getEngine();
    }


    /**
     * Add row to csv
     *
     * @param array $data    Array of data
     * @param int   $storeId
     *
     * @return FACTFinder_Core_Model_Export_Product
     */
    protected function _writeCsvRow($data, $storeId)
    {
        foreach ($data as &$item) {
            $item = str_replace(array("\r", "\n", "\""), array(" ", " ", "\\\""), $item);
        }

        $this->_getFile($storeId)->writeCsv($data, self::CSV_DELIMITER);
        return $this;
    }


    /**
     * Get export filename for store
     *
     * @param int $storeId
     *
     * @return string
     */
    public function getFilenameForStore($storeId)
    {
        return sprintf(self::FILENAME_PATTERN, $storeId);
    }


    /**
     * @return string
     */
    public function getExportDirectory()
    {
        return $this->getHelper()->getExportDirectory();
    }


    /**
     * get Option Text by Option ID
     *
     * @param int $optionId Option ID
     * @param int $storeId  Store ID
     *
     * @return string
     */
    protected function _getAttributeOptionText($optionId, $storeId)
    {
        $value = '';
        if (intval($optionId)) {
            if ($this->_optionIdToValue === null) {
                /** @var Mage_Eav_Model_Resource_Entity_Attribute_Option_Collection $optionCollection */
                $optionCollection = Mage::getResourceModel('eav/entity_attribute_option_collection');
                $optionCollection->setStoreFilter($storeId);
                $this->_optionIdToValue = array();
                foreach ($optionCollection as $option) {
                    $this->_optionIdToValue[$option->getId()] = $option->getValue();
                }
            }

            $value = isset($this->_optionIdToValue[$optionId]) ? $this->_optionIdToValue[$optionId] : '';
        }

        return $value;
    }


    /**
     * Get CSV Header Array
     *
     * @param int $storeId
     *
     * @return array
     */
    protected function _getExportAttributes($storeId = 0)
    {
        if (!isset($this->_exportAttributeCodes[$storeId])) {
            $headerDynamic = array();

            $additionalColumns = array();
            if (Mage::getStoreConfigFlag('factfinder/export/urls', $storeId)) {
                $additionalColumns[] = 'image';
                $additionalColumns[] = 'deeplink';
                $this->_imageHelper = Mage::helper('catalog/image');
            }

            // get dynamic Attributes
            foreach ($this->_getSearchableAttributes(null, 'system', $storeId) as $attribute) {
                if (in_array($attribute->getAttributeCode(), array('sku', 'status', 'visibility'))) {
                    continue;
                }

                $headerDynamic[] = $attribute->getAttributeCode();
            }

            // compare dynamic with setup attributes
            $headerSetup = Mage::helper('factfinder/backend')
                ->unserializeFieldValue(Mage::getStoreConfig('factfinder/export/attributes', $storeId));
            foreach ($headerDynamic as $code) {
                if (in_array($code, $headerSetup)) {
                    continue;
                }

                $headerSetup[$code]['attribute'] = $code;
            }

            // remove default attributes from setup
            foreach ($this->_defaultHeader as $code) {
                if (array_key_exists($code, $headerSetup)) {
                    unset($headerSetup[$code]);
                }
            }

            $this->_exportAttributeCodes[$storeId] = array_merge(
                $this->_defaultHeader,
                $additionalColumns,
                array_keys($headerSetup)
            );

            // apply field limit as required by ff
            if(count($this->_exportAttributeCodes[$storeId]) > 128) {
                array_splice($this->_exportAttributeCodes[$storeId], 128);
            }
        }

        return $this->_exportAttributeCodes[$storeId];
    }


    /**
     * Pre-Generate all product exports for all stores
     *
     * @return array
     */
    public function saveAll()
    {
        $paths = array();
        $stores = Mage::app()->getStores();
        foreach ($stores as $id => $store) {
            try {
                $filePath = $this->saveExport($id);
                if ($filePath) {
                    $paths[] = $filePath;
                }
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        return $paths;
    }


    /**
     * Generate product export for specific store
     *
     * @param int $storeId
     *
     * @return string
     */
    public function saveExport($storeId = 0)
    {
        return $this->doExport($storeId);
    }


    /**
     * Get file handler instance for store
     *
     * @param int $storeId
     *
     * @return \FACTFinder_Core_Model_File
     *
     * @throws \Exception
     */
    protected function _getFile($storeId)
    {
        if (!isset($this->_file[$storeId])) {
            $dir = $this->getExportDirectory();
            $fileName = $this->getFilenameForStore($storeId);

            $file = Mage::getModel('factfinder/file');
            $file->setValidator(Mage::getModel(self::FILE_VALIDATOR))
                ->open($dir, $fileName);

            $this->_file[$storeId] = $file;
        }

        return $this->_file[$storeId];
    }


    /**
     * Export Product Data with Attributes
     * direct Output as CSV
     *
     * @param int $storeId Store View Id
     *
     * @return string|bool
     */
    public function doExport($storeId = null)
    {
        $this->_resetInternalState();

        $idFieldName = Mage::helper('factfinder/search')->getIdFieldName();

        $header = $this->_getExportAttributes($storeId);
        $this->_writeCsvRow($header, $storeId);

        // preparesearchable attributes
        $staticFields = $this->_getStaticFields($storeId);

        $dynamicFields = $this->_getDynamicFields();

        // status and visibility filter
        $visibility = $this->getResource()->getSearchableAttribute('visibility');
        $status = $this->getResource()->getSearchableAttribute('status');
        $visibilityVals = Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds();
        $statusVals = Mage::getSingleton('catalog/product_status')->getVisibleStatusIds();

        $lastProductId = 0;
        while (true) {
            $products = $this->getResource()->getSearchableProducts($storeId, $staticFields, $lastProductId);
            if (!$products) {
                break;
            }

            $productAttributes = array();
            $productRelations = array();
            foreach ($products as $productData) {
                $lastProductId = $productData['entity_id'];
                $productAttributes[$productData['entity_id']] = $productData['entity_id'];
                $productChildren = $this->getResource()
                    ->getProductChildIds($productData['entity_id'], $productData['type_id']);
                $productRelations[$productData['entity_id']] = $productChildren;
                foreach ($productChildren as $productChild) {
                    $productAttributes[$productChild['entity_id']] = $productChild;
                }
            }

            $productAttributes = $this->getResource()
                ->getProductAttributes($storeId, array_keys($productAttributes), $dynamicFields);
            foreach ($products as $productData) {
                if (!isset($productAttributes[$productData['entity_id']])) {
                    continue;
                }

                $productAttr = $productAttributes[$productData['entity_id']];

                if (!isset($productAttr[$visibility->getId()])
                    || !in_array($productAttr[$visibility->getId()], $visibilityVals)
                ) {
                    continue;
                }

                if (!isset($productAttr[$status->getId()]) || !in_array($productAttr[$status->getId()], $statusVals)) {
                    continue;
                }

                $categoryPath = $this->_getCategoryPath($productData['entity_id'], $storeId);

                if ($categoryPath == '' && !$this->_isExportProductsWithoutCategories($storeId)) {
                    continue;
                }

                $productIndex = array(
                    $productData['entity_id'],
                    $productData[$idFieldName],
                    $productData['sku'],
                    $categoryPath,
                    $this->_formatAttributes('filterable', $productAttr, $storeId),
                    $this->_formatAttributes('searchable', $productAttr, $storeId),
                    $this->_formatAttributes('numerical', $productAttr, $storeId),
                );

                $productIndex = $this->_exportImageAndDeepLink($productIndex, $productData, $storeId);

                $productIndex = $this->_getAttributesRowArray($productIndex, $productAttr, $storeId);

                $this->_writeCsvRow($productIndex, $storeId);

                $productChildren = $productRelations[$productData['entity_id']];
                foreach ($productChildren as $productChild) {
                    if (isset($productAttributes[$productChild['entity_id']])) {

                        $productAttr = $productAttributes[$productChild['entity_id']];

                        $subProductIndex = array(
                            $productChild['entity_id'],
                            $productData[$idFieldName],
                            $productChild['sku'],
                            $this->_getCategoryPath($productData['entity_id'], $storeId),
                            $this->_formatAttributes('filterable', $productAttr, $storeId),
                            $this->_formatAttributes('searchable', $productAttr, $storeId),
                            $this->_formatAttributes('numerical', $productAttr, $storeId),
                        );
                        if ($this->getHelper()->shouldExportImagesAndDeeplinks($storeId)) {
                            //dont need to add image and deeplink to child product, just add empty values
                            $subProductIndex[] = '';
                            $subProductIndex[] = '';
                        }

                        $subProductIndex = $this->_getAttributesRowArray(
                            $subProductIndex,
                            $productAttributes[$productChild['entity_id']],
                            $storeId
                        );

                        $this->_writeCsvRow($subProductIndex, $storeId);
                    }
                }
            }
        }

        Mage::dispatchEvent('factfinder_export_after', array(
            'store_id' => $storeId,
            'file'     => $this->_getFile($storeId),
        ));

        if (!$this->_getFile($storeId)->isValid()) {
            return false;
        }

        return $this->_getFile($storeId)->getPath();
    }


    /**
     * Resets the internal state of this export.
     */
    protected function _resetInternalState()
    {
        $this->_lines = array();
        $this->_categoryNames = null;
        $this->_productsToCategoryPath = null;
        $this->_exportAttributes = null;
    }

    /**
     * Get attributes by type
     *
     * @param string $type    Possible values: filterable|searchable|numerical
     * @param int    $storeId
     *
     * @return array
     */
    protected function _getAttributesByType($type, $storeId)
    {
        switch ($type) {
            case 'numerical':
                $attributes = $this->_getSearchableAttributes('decimal', $type, $storeId);
                break;
            default:
                $attributes = $this->_getSearchableAttributes(null, $type, $storeId);
        }

        return $attributes;
    }


    /**
     * Format attributes for csv
     *
     * @param string   $type
     * @param array    $values
     * @param null|int $storeId
     *
     * @return string
     */
    protected function _formatAttributes($type, $values, $storeId = null)
    {
        $attributes = $this->_getAttributesByType($type, $storeId);

        $returnArray = array();
        $counter = 0;

        foreach ($attributes as $attribute) {
            $attributeValue = isset($values[$attribute->getId()]) ? $values[$attribute->getId()] : null;
            if (!$attributeValue
                || in_array($attribute->getAttributeCode(), array('sku', 'status', 'visibility', 'price'))
            ) {
                continue;
            }

            $attributeValues = $this->_getAttributeValue($attribute->getId(), $attributeValue, $storeId);

            if (!is_array($attributeValues)) {
                $attributeValues = array($attributeValues);
            }

            $attributeValues = $this->_filterAttributeValues($attributeValues);
            foreach ($attributeValues as $attributeValue) {
                $attributeValue = $this->_removeTags($attributeValue, $storeId);
                if ($type == 'searchable') {
                    $returnArray[] = $attributeValue;
                } else {
                    $attributeCode = $this->_removeTags($attribute->getAttributeCode(), $storeId);
                    $attributeValue = str_replace(array('|', '=', '#'), '', array($attributeCode, $attributeValue));
                    $returnArray[] = implode('=', $attributeValue);
                }
            }

            // apply field limit as required by ff
            $counter++;
            if ($counter >= 1000) {
                break;
            }
        }

        $delimiter = ($type == 'searchable' ? ',' : '|');

        return implode($delimiter, $returnArray);
    }


    /**
     * Remove all empty values from array
     *
     * @param array $values
     *
     * @return array
     */
    protected function _filterAttributeValues($values)
    {
        // filter all empty values out
        return array_filter($values, function ($value) {
            return !empty($value);
        });
    }


    /**
     * Get Category Path by Product ID
     *
     * @param int $productId
     * @param int $storeId
     *
     * @return string
     */
    protected function _getCategoryPath($productId, $storeId = null)
    {

        if ($this->_categoryNames === null) {
            $this->_categoryNames = $this->getResource()->getCategoryNames($storeId);
        }

        if ($this->_productsToCategoryPath === null) {
            $this->_productsToCategoryPath = $this->getResource()->getCategoryPaths($storeId);
        }

        $value = '';
        if (isset($this->_productsToCategoryPath[$productId])) {
            $paths = explode(',', $this->_productsToCategoryPath[$productId]);
            foreach ($paths as $path) {
                $categoryIds = explode('/', $path);
                $categoryIdsCount = count($categoryIds);
                $categoryPath = '';
                for ($i = 2; $i < $categoryIdsCount; $i++) {
                    if (!isset($this->_categoryNames[$categoryIds[$i]])) {
                        continue 2;
                    }

                    $categoryPath .= urlencode(trim($this->_categoryNames[$categoryIds[$i]])) . '/';
                }

                if ($categoryIdsCount > 2) {
                    $value .= rtrim($categoryPath, '/') . '|';
                }
            }

            $value = trim($value, '|');
        }

        return $value;
    }


    /**
     * Retrieve attribute source value for search
     * This method is mostly copied from Mage_CatalogSearch_Model_Resource_Fulltext,
     * but it also retrieves attribute values from non-searchable/non-filterable attributes
     *
     * @param int   $attributeId
     * @param mixed $value
     * @param int   $storeId
     *
     * @return mixed
     */
    protected function _getAttributeValue($attributeId, $value, $storeId)
    {
        $attribute = $this->getResource()->getSearchableAttribute($attributeId);
        if (!$attribute->getIsSearchable() && $attribute->getAttributeCode() == 'visibility') {
            return $value;
        }

        if ($attribute->usesSource()) {
            if (method_exists($this->_engine, 'allowAdvancedIndex') && $this->_engine->allowAdvancedIndex()) {
                return $value;
            }

            $attribute->setStoreId($storeId);
            $value = $attribute->getSource()->getOptionText($value);

            if (empty($value)) {
                $inputType = $attribute->getFrontend()->getInputType();
                if ($inputType == 'select' || $inputType == 'multiselect') {
                    return null;
                }
            }
        } elseif ($attribute->getBackendType() == 'datetime') {
            $value = strtotime($value) * 1000; // Java.lang.System.currentTimeMillis()
        } else {
            $inputType = $attribute->getFrontend()->getInputType();
            if ($inputType == 'price') {
                $value = Mage::app()->getStore($storeId)->roundPrice($value);
            }
        }

        return $value;
    }


    /**
     * get Attribute Row Array
     *
     * @param array $dataArray Export row Array
     * @param array $values    Attributes Array
     * @param int   $storeId   Store ID
     *
     * @return array
     */
    protected function _getAttributesRowArray($dataArray, $values, $storeId = null)
    {
        // get attributes objects assigned to their position at the export
        if ($this->_exportAttributes == null) {
            $this->_exportAttributes = array_fill(0, count($this->_getExportAttributes($storeId)), null);

            $attributeCodes = array_flip($this->_getExportAttributes($storeId));
            foreach ($this->_getSearchableAttributes() as $attribute) {
                if (isset($attributeCodes[$attribute->getAttributeCode()])
                    && !in_array($attribute->getAttributeCode(), array('sku', 'status', 'visibility'))
                ) {
                    $this->_exportAttributes[$attributeCodes[$attribute->getAttributeCode()]] = $attribute;
                }
            }
        }

        // fill dataArray with the values of the attributes that should be exported
        foreach ($this->_exportAttributes as $pos => $attribute) {
            if ($attribute != null) {
                $value = isset($values[$attribute->getId()]) ? $values[$attribute->getId()] : null;
                $value = $this->_getAttributeValue($attribute->getId(), $value, $storeId);
                $value = $this->_removeTags($value, $storeId);
                $dataArray[$pos] = $value;
            } else if (!array_key_exists($pos, $dataArray)) {
                // it's unlikely that an attribute exists in header but is not delivered by "getSearchableAttributes",
                // but actually it might be a result of a broken database or something like that..
                $dataArray[$pos] = null;
            }
        }

        return $dataArray;
    }


    /**
     * Check whether the attribute should be skipped
     *
     * @param Mage_Catalog_Model_Resource_EAV_Attribute $attribute
     * @param string                                    $type
     *
     * @return bool
     */
    protected function _checkIfSkipAttribute($attribute, $type)
    {
        $shouldSkip = false;
        switch ($type) {
            case "system":
                if ($attribute->getIsUserDefined() && !$attribute->getUsedForSortBy()) {
                    $shouldSkip = true;
                }
                break;
            case "sortable":
                if (!$attribute->getUsedForSortBy()) {
                    $shouldSkip = true;
                }
                break;
            case "filterable":
                $shouldSkip = $this->_shouldSkipFilterableAttribute($attribute);
                break;
            case 'numerical':
                $shouldSkip = $this->_shouldSkipNumericalAttribute($attribute);
                break;
            case "searchable":
                $shouldSkip = $this->_shouldSkipSearchableAttribute($attribute);
                break;
            default:;
        }

        return $shouldSkip;
    }


    /**
     * Check if we should skip searchable attribute
     *
     * @param Mage_Catalog_Model_Resource_EAV_Attribute $attribute
     *
     * @return bool
     */
    protected function _shouldSkipSearchableAttribute($attribute)
    {
        if (!$attribute->getIsUserDefined()
            || !$attribute->getIsSearchable()
            || in_array($attribute->getAttributeCode(), $this->_getExportAttributes())
            || $attribute->getBackendType() === 'decimal'
        ) {
            return true;
        }

        return false;
    }


    /**
     * Check if we should skip filterable attribute
     *
     * @param Mage_Catalog_Model_Resource_EAV_Attribute $attribute
     *
     * @return bool
     */
    protected function _shouldSkipFilterableAttribute($attribute)
    {
        if (!$attribute->getIsFilterableInSearch()
            || in_array($attribute->getAttributeCode(), $this->_getExportAttributes())
            || $attribute->getBackendType() === 'decimal'
        ) {
            return true;
        }

        return false;
    }


    /**
     * Check if we should skip numerical attribute
     *
     * @param Mage_Catalog_Model_Resource_EAV_Attribute $attribute
     *
     * @return bool
     */
    protected function _shouldSkipNumericalAttribute($attribute)
    {
        if (!$attribute->getIsFilterableInSearch()
            || in_array($attribute->getAttributeCode(), $this->_getExportAttributes())
            || $attribute->getBackendType() != 'decimal'
        ) {
            return true;
        }

        return false;
    }


    /**
     * Add image and deep link information to product row
     *
     * @param array $productIndex
     * @param array $productData
     * @param int   $storeId
     *
     * @return array
     */
    protected function _exportImageAndDeepLink($productIndex, $productData, $storeId)
    {
        $helper = $this->getHelper();

        if (!$helper->shouldExportImagesAndDeeplinks($storeId)) {
            return $productIndex;
        }

        // emulate store
        $oldStore = Mage::app()->getStore()->getId();
        Mage::app()->setCurrentStore($storeId);

        $productIndex[] = $this->getProductImageUrl($productData['entity_id'], $storeId);
        $productIndex[] = $this->getProductUrl($productData['entity_id'], $storeId);

        // finish emulation
        Mage::app()->setCurrentStore($oldStore);

        return $productIndex;
    }


    /**
     * Get array of dynamic fields to use in csv
     *
     * @return array
     */
    protected function _getDynamicFields()
    {
        $dynamicFields = array();
        foreach (array('int', 'varchar', 'text', 'decimal', 'datetime') as $type) {
            $dynamicFields[$type] = array_keys($this->_getSearchableAttributes($type));
        }

        return $dynamicFields;
    }


    /**
     * Get array of static fields to use in csv
     *
     * @param int $storeId
     *
     * @return array
     */
    protected function _getStaticFields($storeId)
    {
        $staticFields = array();
        foreach ($this->_getSearchableAttributes('static', 'system', $storeId) as $attribute) {
            $staticFields[] = $attribute->getAttributeCode();
        }
        return $staticFields;
    }


    /**
     * Check if html tags and entities should be removed on export
     *
     * @param string $value
     * @param int    $storeId
     *
     * @return bool
     */
    protected function _removeTags($value, $storeId)
    {
        if (Mage::getStoreConfig('factfinder/export/remove_tags', $storeId)) {
            $attributeValues = $value;
            if (!is_array($attributeValues)) {
                $attributeValues = array($value);
            }
            foreach ($attributeValues as &$attributeValue) {
                // decode html entities
                $attributeValue = html_entity_decode($attributeValue, null, 'UTF-8');
                // Add spaces before HTML Tags, so that strip_tags() does not join word which were in different block elements
                // Additional spaces are not an issue, because they will be removed in the next step anyway
                $attributeValue = preg_replace('/</u', ' <', $attributeValue);
                $attributeValue = preg_replace("#\s+#siu", ' ', trim(strip_tags($attributeValue)));
                // remove rest html entities
                $attributeValue = preg_replace("/&(?:[a-z\d]|#\d|#x[a-f\d]){2,8};/i", '', $attributeValue);
            }
            $value = implode("|", $attributeValues);
        }

        return $value;
    }


    /**
     * Check if products without categories should be exported
     *
     * @param int $storeId
     *
     * @return bool
     */
    protected function _isExportProductsWithoutCategories($storeId)
    {
        return Mage::getStoreConfig('factfinder/export/products_without_categories', $storeId);
    }


    /**
     * Get export helper
     *
     * @return FACTFinder_Core_Helper_Export
     */
    protected function getHelper()
    {
        return Mage::helper('factfinder/export');
    }


    /**
     * Gte product URL for store
     *
     * @param int $productId
     * @param int $storeId
     *
     * @return string
     */
    protected function getProductUrl($productId, $storeId)
    {
        $productUrl = Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToFilter('entity_id', $productId)
            ->setStoreId($storeId)
            ->addUrlRewrite()
            ->getFirstItem()
            ->getProductUrl();

        return $productUrl;
    }


    /**
     * Get image URL for product
     *
     * @param int $productId
     * @param int $storeId
     *
     * @return string
     */
    protected function getProductImageUrl($productId, $storeId)
    {
        $helper = $this->getHelper();

        $imageType = $helper->getExportImageType();
        $imageBaseFile = Mage::getResourceSingleton('catalog/product')
            ->getAttributeRawValue($productId, $imageType, $storeId);

        /** @var Mage_Catalog_Model_Product_Image $imageModel */
        $imageModel = Mage::getModel('catalog/product_image');

        // if size was set
        if ($helper->getExportImageWidth($storeId)) {
            $imageModel
                ->setWidth($helper->getExportImageWidth($storeId))
                ->setHeight($helper->getExportImageHeight($storeId));
        }

        $imageModel
            ->setDestinationSubdir($imageType)
            ->setBaseFile($imageBaseFile);

        // if no cache image was generated we should create one
        if (!$imageModel->isCached()) {
            $imageModel
                ->resize()
                ->saveFile();
        }

        return $imageModel->getUrl();
    }


    /**
     * Get searchable attributes by type
     *
     * @param null   $backendType Backend type of the attributes
     * @param string $type        Possible Types: system, sortable, filterable, searchable
     * @param int    $storeId
     *
     * @return array
     */
    protected function _getSearchableAttributes($backendType = null, $type = null, $storeId = 0)
    {
        $attributes = array();

        if ($type !== null || $backendType !== null) {
            foreach ($this->getResource()->getSearchableAttributes($storeId) as $attribute) {
                if ($backendType !== null
                    && $attribute->getBackendType() != $backendType
                ) {
                    continue;
                }

                if ($this->_checkIfSkipAttribute($attribute, $type)) {
                    continue;
                }

                $attributes[$attribute->getId()] = $attribute;
            }
        } else {
            $attributes = $this->getResource()->getSearchableAttributes($storeId);
        }

        return $attributes;
    }


    /**
     * Get number of products that should be exported
     *
     * @param int $storeId
     *
     * @return int
     */
    public function getSize($storeId)
    {
        $staticFields = $this->_getStaticFields($storeId);
        $dynamicFields = $this->_getDynamicFields();

        $count = 0;

        $lastId = 0;
        while (true) {
            $products = $this->getResource()->getSearchableProducts($storeId, $staticFields, $lastId);
            if (!$products) {
                break;
            }

            $attributes = array();
            $relations = array();
            foreach ($products as $productData) {
                $attributes[$productData['entity_id']] = $productData['entity_id'];
                $children = $this->getResource()->getProductChildIds($productData['entity_id'], $productData['type_id']);
                $relations[$productData['entity_id']] = $children;
                if ($children) {
                    foreach ($children as $child) {
                        $attributes[$child['entity_id']] = $child;
                    }
                }
            }

            $lastId = $productData['entity_id'];

            $attributes = $this->getResource()->getProductAttributes($storeId, array_keys($attributes), $dynamicFields);
            foreach ($products as $productData) {

                if ($this->shouldSkipProduct($attributes, $productData)) {
                    continue;
                }

                $categoryPath = $this->_getCategoryPath($productData['entity_id'], $storeId);

                if ($categoryPath == '' && !$this->_isExportProductsWithoutCategories($storeId)) {
                    continue;
                }

                $count++;


                $children = $relations[$productData['entity_id']];
                foreach ($children as $child) {
                    if (isset($attributes[$child['entity_id']])) {
                        $count++;
                    }
                }
            }
        }


        return $count;
    }


    /**
     * Check if product should be skipped in export
     *
     * @param $attributes
     * @param $productData
     *
     * @return bool
     */
    protected function shouldSkipProduct($attributes, $productData)
    {

        if (!isset($attributes[$productData['entity_id']])) {
            return true;
        }

        // status and visibility filter
        $visibilityId = $this->getResource()->getSearchableAttribute('visibility')->getId();
        $statusId = $this->getResource()->getSearchableAttribute('status')->getId();

        $visibilities = Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds();
        $statuses = Mage::getSingleton('catalog/product_status')->getVisibleStatusIds();

        $productAttributes = $attributes[$productData['entity_id']];

        if (!isset($productAttributes[$visibilityId])
            || !isset($productAttributes[$statusId])
            || !in_array($productAttributes[$visibilityId], $visibilities)
            || !in_array($productAttributes[$statusId], $statuses)
        ) {
            return true;
        }

        return false;
    }


}
