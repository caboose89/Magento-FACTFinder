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
 * This class provides db access to the export model
 *
 * @category Mage
 * @package FACTFinder_Core
 * @author Flagbit Magento Team <magento@flagbit.de>
 * @copyright Copyright (c) 2016 Flagbit GmbH & Co. KG (http://www.flagbit.de)
 * @license https://opensource.org/licenses/MIT  The MIT License (MIT)
 * @link http://www.flagbit.de
 */
class FACTFinder_Core_Model_Resource_Export extends Mage_CatalogSearch_Model_Resource_Fulltext
{


    /**
     * Retrieve searchable products per store
     *
     * @param int   $storeId
     * @param array $staticFields
     * @param int   $lastProductId
     * @param int   $limit
     *
     * @return array
     */
    public function getSearchableProducts($storeId, array $staticFields, $lastProductId = 0, $limit = 100)
    {
        $websiteId        = Mage::app()->getStore($storeId)->getWebsiteId();
        $readConnection   = $this->getReadConnection();

        $select = $readConnection->select()
            ->useStraightJoin(true)
            ->from(
                array('e' => $this->getTable('catalog/product')),
                array_merge(array('entity_id', 'type_id'), $staticFields)
            )
            ->join(
                array('website' => $this->getTable('catalog/product_website')),
                $readConnection->quoteInto(
                    'website.product_id=e.entity_id AND website.website_id=?',
                    $websiteId
                ),
                array()
            )
            ->join(
                array('stock_status' => $this->getTable('cataloginventory/stock_status')),
                $readConnection->quoteInto(
                    'stock_status.product_id=e.entity_id AND stock_status.website_id=?',
                    $websiteId
                ),
                array('in_stock' => 'stock_status')
            );

        $select->where('e.entity_id>?', $lastProductId)
            ->limit($limit)
            ->order('e.entity_id');

        if (!Mage::helper('factfinder/export')->shouldExportOutOfStock($storeId)) {
            Mage::getSingleton('cataloginventory/stock_status')
                ->prepareCatalogProductIndexSelect(
                    $select,
                    new Zend_Db_Expr('e.entity_id'),
                    new Zend_Db_Expr('website.website_id')
                );
        }

        $result = $readConnection->fetchAll($select);

        return $result;
    }


    /**
     * Retrieve Searchable attributes
     *
     * @param int    $storeId
     *
     * @return array
     */
    public function getSearchableAttributes($storeId = null)
    {
        if ($this->_searchableAttributes === null) {
            $this->_searchableAttributes = array();
            $entityType = $this->getEavConfig()->getEntityType('catalog_product');
            $entity = $entityType->getEntity();

            $userDefinedAttributes = Mage::getStoreConfig('factfinder/export/attributes', $storeId);
            $userDefinedAttributes = array_keys(
                Mage::helper('factfinder/backend')->unserializeFieldValue($userDefinedAttributes)
            );

            $whereCond = array(
                $this->_getWriteAdapter()->quoteInto('additional_table.is_searchable=?', 1),
                $this->_getWriteAdapter()->quoteInto('additional_table.is_filterable=?', 1),
                $this->_getWriteAdapter()->quoteInto('additional_table.used_for_sort_by=?', 1),
                $this->_getWriteAdapter()->quoteInto(
                    'main_table.attribute_code IN(?)',
                    array_merge(array('status', 'visibility'), $userDefinedAttributes)
                )
            );

            $select = $this->_getWriteAdapter()->select()
                ->from(array('main_table' => $this->getTable('eav/attribute')))
                ->join(
                    array('additional_table' => $this->getTable('catalog/eav_attribute')),
                    'additional_table.attribute_id = main_table.attribute_id'
                )
                ->where('main_table.entity_type_id=?', $entityType->getEntityTypeId())
                ->where(join(' OR ', $whereCond))
                ->order('main_table.attribute_id', 'asc');

            $attributesData = $this->_getWriteAdapter()->fetchAll($select);
            $this->getEavConfig()->importAttributesData($entityType, $attributesData);

            foreach ($attributesData as $attributeData) {
                $attributeCode = $attributeData['attribute_code'];
                $attribute = $this->getEavConfig()->getAttribute($entityType, $attributeCode);
                $attribute->setEntity($entity);
                $this->_searchableAttributes[$attribute->getId()] = $attribute;
            }
        }

        return $this->_searchableAttributes;
    }


    /**
     * Get products to category paths
     *
     * @param int $storeId
     *
     * @return array
     */
    public function getCategoryPaths($storeId)
    {
        $select = $this->_getReadAdapter()->select()
            ->from(
                array('main' => $this->getTable('catalog/category_product_index')),
                array('product_id')
            )
            ->join(
                array('e' => $this->getTable('catalog/category')),
                'main.category_id=e.entity_id',
                null
            )
            ->columns(array('e.path' => new Zend_Db_Expr('GROUP_CONCAT(e.path)')))
            ->where(
                'main.visibility IN(?)',
                array(
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH
                )
            )
            ->where('main.store_id = ?', $storeId)
            ->where('e.path LIKE \'1/' . Mage::app()->getStore($storeId)->getRootCategoryId() . '/%\'')
            ->group('main.product_id');

        return $this->_getReadAdapter()->fetchPairs($select);
    }


    /**
     * Get category names
     *
     * @param int $storeId
     *
     * @return $this
     */
    public function getCategoryNames($storeId)
    {
        $nameAttribute = $this->_getCategoryNameAttribute();
        $statusAttribute = $this->_getCategoryStatusAttribute();

        $select = $this->_getReadAdapter()->select()
            ->from(
                array('main' => $nameAttribute->getBackendTable()),
                array('entity_id', 'value')
            )
            ->join(
                array('e' => $statusAttribute->getBackendTable()),
                'main.entity_id=e.entity_id AND (e.store_id = 0 OR e.store_id = ' . $storeId
                . ') AND e.attribute_id=' . $statusAttribute->getAttributeId(),
                null
            )
            ->where('main.attribute_id=?', $nameAttribute->getAttributeId())
            ->where('e.value=?', '1')
            ->where('main.store_id = 0 OR main.store_id = ?', $storeId);

        return $this->_getReadAdapter()->fetchPairs($select);
    }


    /**
     * Get category name attribute model
     *
     * @return mixed
     */
    protected function _getCategoryNameAttribute()
    {
        $categoryAttributeCollection = Mage::getResourceModel('catalog/category_attribute_collection');
        $categoryAttributeCollection->addFieldToFilter('attribute_code', array('eq' => 'name'))
            ->getSelect()->limit(1);

        return $categoryAttributeCollection->getFirstItem();
    }


    /**
     * Get category status attribute model (is_active)
     *
     * @return mixed
     */
    protected function _getCategoryStatusAttribute()
    {
        $categoryAttributeCollection = Mage::getResourceModel('catalog/category_attribute_collection');
        $categoryAttributeCollection->addFieldToFilter('attribute_code', array('eq' => 'is_active'))
            ->getSelect()->limit(1);

        return $categoryAttributeCollection->getFirstItem();
    }


    /**
     * Return all product children ids
     *
     * @param int    $productId Product Entity Id
     * @param string $typeId    Super Product Link Type
     *
     * @return array
     */
    public function getProductChildIds($productId, $typeId)
    {
        $typeInstance = $this->_getProductTypeInstance($typeId);
        $relation = $typeInstance->isComposite()
            ? $typeInstance->getRelationInfo()
            : false;

        if ($relation && $relation->getTable() && $relation->getParentFieldName() && $relation->getChildFieldName()) {
            $select = $this->_getReadAdapter()->select()
                ->from(
                    array('main' => $this->getTable($relation->getTable())),
                    array($relation->getChildFieldName()))
                ->join(
                    array('e' => $this->getTable('catalog/product')),
                    'main.' . $relation->getChildFieldName() . '=e.entity_id',
                    array('entity_id', 'type_id', 'sku')
                )
                ->where("{$relation->getParentFieldName()}=?", $productId);
            if ($relation->getWhere() !== null) {
                $select->where($relation->getWhere());
            }

            return $this->_getReadAdapter()->fetchAll($select);
        }

        return array();
    }


    /**
     * Retrieve searchable attribute by Id or code
     *
     * @param int|string $attribute
     *
     * @return Mage_Eav_Model_Entity_Attribute
     */
    public function getSearchableAttribute($attribute)
    {
        return $this->_getSearchableAttribute($attribute);
    }


    /**
     * Load product(s) attributes
     *
     * @param int   $storeId
     * @param array $productIds
     * @param array $attributeTypes
     *
     * @return array
     */
    public function getProductAttributes($storeId, array $productIds, array $attributeTypes)
    {
        return $this->_getProductAttributes($storeId, $productIds, $attributeTypes);
    }


}