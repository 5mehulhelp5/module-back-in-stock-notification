<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * License validation for ETechFlow_BackInStockNotification.
 *
 * Two validation modes:
 *   SP-XXXX keys  - portal validation (domain + server IP must match)
 *   HMAC keys     - local HMAC-SHA256 check (legacy / bundle)
 *
 * IP-block auto-management:
 *   When portal returns ip_blocked:true -> clearLicenseKey() zeroes the key
 *   AND sets ip_blocked flag = 1.
 *   When IP restored -> portal returns valid -> writeLicenseKey() restores
 *   from issued_key AND resets ip_blocked flag to 0.
 *
 *   The issued_key fallback ONLY fires when ip_blocked = 1 (auto IP-block).
 *   When admin manually clears the key, ip_blocked stays 0 -> no restore
 *   -> gate page shows permanently until admin enters a key again.
 */
class LicenseValidator
{
    // per-module config paths
    public const XML_PATH_LICENSE_KEY            = 'etechflow_bisn/license/license_key';
    public const XML_PATH_ISSUED_KEY             = 'etechflow_bisn/license/issued_key';
    public const XML_PATH_ISSUED_AT              = 'etechflow_bisn/license/issued_at';
    public const XML_PATH_IP_BLOCKED             = 'etechflow_bisn/license/ip_blocked';
    public const XML_PATH_PORTAL_URL             = 'etechflow_bisn/license/portal_url';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_bisn/license/production_environment';

    // shared across every ETechFlow module
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    // portal
    private const DEFAULT_PORTAL_URL    = 'https://module.etechflow.com/license/validate';
    public  const PORTAL_CACHE_TTL      = 120;
    public  const PORTAL_CACHE_TTL_BAD  = 60;

    // cache
    private const CACHE_TAG    = 'ETECHFLOW_BISN';
    private const CACHE_PREFIX = 'etf_bisn_lic_';

    // HMAC per-module
    private const MODULE_ID = 'back-in-stock-notification';
    private const SECRET_FRAGMENTS = ['eTF-BISN-2026', 'r5K9-qV2y', 'P3jM-tU8c', 'A6fN-eX4w'];

    // HMAC shared bundle
    private const BUNDLE_ID = 'etechflow-bundle';
    private const BUNDLE_SECRET_FRAGMENTS = ['eTF-BUNDLE-2026', 'k2D9-mP4x', 'L8nR-vH2j', 'X7tY-zW5q'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter
    ) {}

    // public API

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') { return false; }
        return $this->checkKey($host);
    }

    public function computeKey(string $host): string
    {
        $raw = hash_hmac('sha256', $this->canonicalize($host) . ':' . self::MODULE_ID, implode('', self::SECRET_FRAGMENTS), true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $raw = hash_hmac('sha256', $this->canonicalize($host) . ':' . self::BUNDLE_ID, implode('', self::BUNDLE_SECRET_FRAGMENTS), true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function getConfiguredKey(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE));
    }

    public function getConfiguredBundleKey(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE));
    }

    public function isProductionEnvironment(): bool
    {
        // Sandbox toggle removed: production licensing is always enforced.
        return true;
        $v = $this->scopeConfig->getValue(self::XML_PATH_PRODUCTION_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        return ($v === null || $v === '') ? true : (bool) $v;
    }

    public function getPortalUrl(): string
    {
        $v = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        return $v !== '' ? $v : self::DEFAULT_PORTAL_URL;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) { return ''; }
    }

    public function isDevHost(?string $host = null): bool
    {
        $h = $host !== null ? $this->canonicalize($host) : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($h);
    }

    // private helpers

    private function checkKey(string $host): bool
    {
        $configuredKey = $this->getConfiguredKey();
        $isEmptyKey    = ($configuredKey === '');

        if ($isEmptyKey) {
            // Only fall back to issued_key when an IP-block event caused the clearing.
            // If admin manually cleared the key, ip_blocked = 0 and this returns false.
            $ipBlocked = (int) $this->scopeConfig->getValue(self::XML_PATH_IP_BLOCKED);
            if ($ipBlocked !== 1) {
                return false;  // manually cleared — stay locked
            }
            $configuredKey = trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY));
            if ($configuredKey === '') { return false; }
        }

        if (str_starts_with($configuredKey, 'SP-')) {
            if (!$isEmptyKey && $this->isLocallyIssuedKey($configuredKey, $host)) { return true; }
            $valid = $this->validateViaPortal($host, $configuredKey);
            if ($valid && $isEmptyKey) { $this->writeLicenseKey($configuredKey); }
            return $valid;
        }

        // HMAC / legacy path
        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) { return true; }
        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) { return true; }
        return false;
    }

    private function isLocallyIssuedKey(string $key, string $host): bool
    {
        $issuedAt = (int) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_AT);
        if ($issuedAt === 0) { return false; }
        if ((time() - $issuedAt) > 172800) { return false; }
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY)) === $key;
    }

    private function validateViaPortal(string $host, string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX . md5($host . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) { return $cached === '1'; }

        $url = $this->getPortalUrl()
            . '?domain=' . urlencode($host)
            . '&license_key=' . urlencode($key)
            . '&platform=magento&module=back-in-stock-notification';

        $valid = $ipBlocked = false; $status = 0; $body = '';
        try {
            $this->curl->setTimeout(10);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-BISN/1.1');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable) { return false; }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
        } elseif ($status === 403 && $body !== '') {
            $data      = json_decode($body, true);
            $ipBlocked = !empty($data['ip_blocked']);
        }

        $ttl = $valid ? self::PORTAL_CACHE_TTL : self::PORTAL_CACHE_TTL_BAD;
        $this->cache->save($valid ? '1' : '0', $cacheKey, [self::CACHE_TAG], $ttl);

        if ($valid) {
            // First successful validation - store issued_key for IP-block restore
            $existing = trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY));
            if ($existing === '') {
                try {
                    $this->configWriter->save(self::XML_PATH_ISSUED_KEY, $key);
                    $this->configWriter->save(self::XML_PATH_ISSUED_AT, (string) time());
                    $this->cache->clean([ConfigCacheType::CACHE_TAG]);
                } catch (\Throwable) {}
            }
        }

        if ($ipBlocked) { $this->clearLicenseKey(); }

        return $valid;
    }

    public function clearLicenseKey(): void
    {
        try {
            $current = trim((string) $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE));
            if ($current === '') { return; }
            $this->configWriter->save(self::XML_PATH_LICENSE_KEY, '');
            // Set ip_blocked = 1 so issued_key fallback knows this was an auto-clear
            $this->configWriter->save(self::XML_PATH_IP_BLOCKED, '1');
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        } catch (\Throwable) {}
    }

    private function writeLicenseKey(string $key): void
    {
        try {
            $this->configWriter->save(self::XML_PATH_LICENSE_KEY, $key);
            // Reset ip_blocked flag - key is restored
            $this->configWriter->save(self::XML_PATH_IP_BLOCKED, '0');
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        } catch (\Throwable) {}
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) { return true; }
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) { return true; }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) { return true; }
        foreach (['.test','.local','.localhost','.dev','.example','.invalid'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        foreach (['staging.','stage.','dev.','qa.','uat.','test.','preview.','sandbox.'] as $p) {
            if (str_starts_with($host, $p)) return true;
        }
        foreach (['.magento.cloud','.magentocloud.com','.cloud.magento'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        foreach (['.ngrok.io','.ngrok-free.app','.loca.lt','.serveo.net','.ngrok-free.dev'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        return false;
    }
}