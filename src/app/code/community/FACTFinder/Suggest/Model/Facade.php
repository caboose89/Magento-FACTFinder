<?php
/**
 * FACTFinder_Suggest
 *
 * @category Mage
 * @package FACTFinder_Suggest
 * @author Flagbit Magento Team <magento@flagbit.de>
 * @copyright Copyright (c) 2016 Flagbit GmbH & Co. KG
 * @license https://opensource.org/licenses/MIT  The MIT License (MIT)
 * @link http://www.flagbit.de
 *
 */

/**
 * Model class
 *
 * @category Mage
 * @package FACTFinder_Suggest
 * @author Flagbit Magento Team <magento@flagbit.de>
 * @copyright Copyright (c) 2016 Flagbit GmbH & Co. KG
 * @license https://opensource.org/licenses/MIT  The MIT License (MIT)
 * @link http://www.flagbit.de
 */
class FACTFinder_Suggest_Model_Facade extends FACTFinder_Core_Model_Facade
{


    /**
     * Set config data to suggest adapter
     *
     * @param array  $params
     * @param string $channel
     * @param int    $id
     */
    public function configureSuggestAdapter($params, $channel = null, $id = null)
    {
        $this->_configureAdapter($params, "suggest", $channel, $id);
    }


    /**
     * Get suggestions object from adapter
     *
     * @param string $channel
     * @param int    $id
     *
     * @return Object
     */
    public function getSuggestions($channel = null, $id = null)
    {
        return $this->_getFactFinderObject("suggest", "getSuggestions", $channel, $id);
    }


    /**
     * Get url to access ff api
     *
     * @return string
     */
    public function getSuggestUrl()
    {
        return $this->_getUrlBuilder()
            ->getNonAuthenticationUrl('Suggest.ff', $this->_dic['requestParser']->getRequestParameters());
    }


    /**
     * Trigger suggest import on FF side
     *
     * @param null|string $channel
     * @param bool        $download
     *
     * @return \SimpleXMLElement
     */
    public function triggerSuggestImport($channel = null, $download = true)
    {
        $this->configureImportAdapter(array('channel' => $channel));

        return $this->getImportAdapter($channel)->triggerSuggestImport($download);
    }


}
