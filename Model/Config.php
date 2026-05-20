<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralised reader for the module's admin config + license-aware gate.
 *
 * isEnabled() returns false when EITHER the admin "Module Enabled" toggle
 * is No OR the license isn't valid for the current host. Calling code can
 * just check isEnabled() and not worry about the underlying mechanics.
 *
 * Sibling-module integration flags are soft-detected: if the sibling
 * isn't installed (class doesn't exist), the integration returns false
 * regardless of the admin toggle.
 */
class Config
{
    public const XML_PATH_ENABLED                = 'etechflow_bisn/general/enabled';
    public const XML_PATH_EMAIL_RATE_LIMIT       = 'etechflow_bisn/general/email_rate_limit';
    public const XML_PATH_SUBSCRIPTION_LIFETIME  = 'etechflow_bisn/general/subscription_lifetime_days';
    public const XML_PATH_DOUBLE_OPT_IN          = 'etechflow_bisn/general/double_opt_in';
    public const XML_PATH_EMAIL_SENDER           = 'etechflow_bisn/notifications/email_sender';
    public const XML_PATH_EMAIL_TEMPLATE         = 'etechflow_bisn/notifications/email_template';

    public const XML_PATH_INTEGRATION_NDE        = 'etechflow_bisn/integrations/use_nde_eligibility';
    public const XML_PATH_INTEGRATION_BED        = 'etechflow_bisn/integrations/use_bed_eta';
    public const XML_PATH_INTEGRATION_ISP        = 'etechflow_bisn/integrations/use_isp_per_store';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    /**
     * Master enable gate: license valid + admin toggle on.
     */
    public function isEnabled(): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }
        return $this->isAdminEnabled();
    }

    public function isAdminEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Emails sent per minute by the cron consumer. Default 60.
     */
    public function getEmailRateLimit(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RATE_LIMIT, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 60;
    }

    /**
     * Days after which an unfulfilled subscription auto-expires. 0 = never.
     */
    public function getSubscriptionLifetimeDays(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_SUBSCRIPTION_LIFETIME, ScopeInterface::SCOPE_STORE);
    }

    public function isDoubleOptInEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DOUBLE_OPT_IN, ScopeInterface::SCOPE_STORE);
    }

    public function getEmailSender(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_SENDER, ScopeInterface::SCOPE_STORE);
        return is_string($value) && $value !== '' ? $value : 'general';
    }

    public function getEmailTemplate(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE);
        return is_string($value) && $value !== '' ? $value : 'etechflow_bisn_back_in_stock';
    }

    /**
     * NDE eligibility integration: only true if NDE is installed AND admin opted in.
     */
    public function isNdeEligibilityEnabled(): bool
    {
        if (!class_exists(\ETechFlow\NextDayEligibility\Model\IneligibilityChecker::class)) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INTEGRATION_NDE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * BED ETA integration: only true if BED is installed AND admin opted in.
     */
    public function isBedEtaEnabled(): bool
    {
        if (!class_exists(\ETechFlow\BackorderEtaDisplay\Model\EtaResolver::class)) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INTEGRATION_BED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * ISP per-store integration: only true if ISP is installed AND admin opted in.
     */
    public function isIspPerStoreEnabled(): bool
    {
        if (!class_exists(\ETechFlow\InStorePickup\Api\StoreRepositoryInterface::class)) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INTEGRATION_ISP, ScopeInterface::SCOPE_STORE);
    }
}
