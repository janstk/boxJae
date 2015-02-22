<?php
/**
 * BoxBilling
 *
 * @copyright BoxBilling, Inc (http://www.boxbilling.com)
 * @license   Apache-2.0
 *
 * Copyright BoxBilling, Inc
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */


namespace Box\Mod\Servicedomain\Api;

/**
 * Domain service management
 */
class Guest extends \Api_Abstract
{
    /**
     * Get configured TLDs which can be ordered. Shows only enabled TLDS
     *
     * @optional bool $allow_register - shows only these tlds which can be registered
     * @optional bool $allow_transfer - shows only these tlds which can be transferred
     *
     * @return array - list of tlds
     */
    public function tlds($data)
    {
        $data['hide_inactive'] = true;


        $hide_inactive  = isset($data['hide_inactive']) ? (bool)$data['hide_inactive'] : FALSE;
        $allow_register = isset($data['allow_register']) ? $data['allow_register'] : NULL;
        $allow_transfer = isset($data['allow_transfer']) ? $data['allow_transfer'] : NULL;

        $where = array();

        if ($hide_inactive) {
            $where[] = "active = 1";
        }

        if (NULL !== $allow_register) {
            $where[] = "allow_register = 1";
        }

        if (NULL !== $allow_transfer) {
            $where[] = "allow_transfer = 1";
        }

        if (!empty($where)) {
            $query = implode(' AND ', $where);
        }

        $tlds   = $this->di['db']->find('Tld', $query, array());
        $result = array();
        foreach ($tlds as $model) {
            $result[] = $this->getService()->tldToApiArray($model);
        }

        return $result;
    }

    /**
     * Get TLD pricing information
     *
     * @param string $tld - Top level domain, ie: .com
     *
     * @return array
     */
    public function pricing($data)
    {
        if (!isset($data['tld'])) {
            throw new \Box_Exception('Tld is required');
        }

        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof \Model_Tld) {
            throw new \Box_Exception('TLD not found');
        }

        return $this->getService()->tldToApiArray($model);
    }

    /**
     * Check if domain is available for registration. Domain registrar must be
     * configured in order to get correct results.
     *
     * @param string $sld - second level domain, ie: mydomain
     * @param string $tld - top level domain, ie: .com
     *
     * @return true
     */
    public function check($data)
    {
        if (!isset($data['tld'])) {
            throw new \Box_Exception('Tld is required');
        }

        if (!isset($data['sld'])) {
            throw new \Box_Exception('Sld is required');
        }

        $sld       = $data['sld'];
        $validator = $this->di['validator'];
        if (!$validator->isSldValid($sld)) {
            throw new \Box_Exception('Domain :domain is not valid', array(':domain' => $sld));
        }

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \Box_Exception('Domain availability could not be determined. TLD is not active.');
        }

        if (!$this->getService()->isDomainAvailable($tld, $sld)) {
            throw new \Box_Exception('Domain is not available.');
        }

        return TRUE;
    }

    /**
     * Check if domain can be transferred. Domain registrar must be
     * configured in order to get correct results.
     *
     * @param string $sld - second level domain, ie: mydomain
     * @param string $tld - top level domain, ie: .com
     *
     * @return true
     */
    public function can_be_transferred($data)
    {
        if (!isset($data['tld'])) {
            throw new \Box_Exception('Tld is required');
        }

        if (!isset($data['sld'])) {
            throw new \Box_Exception('Sld is required');
        }

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \Box_Exception('TLD is not active.');
        }
        if (!$this->getService()->canBeTransfered($tld, $data['sld'])) {
            throw new \Box_Exception('Domain can not be transferred.');
        }

        return TRUE;
    }
}