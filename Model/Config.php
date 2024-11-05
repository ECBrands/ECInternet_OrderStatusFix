<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\OrderStatusFix\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const CONFIG_PATH_ALLOW_ANY_ORDER_STATUS_CHANGE = 'order_status_fix/general/allow_any_order_status_change';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Config constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function isAllowAnyOrderStatusChange()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ALLOW_ANY_ORDER_STATUS_CHANGE);
    }
}