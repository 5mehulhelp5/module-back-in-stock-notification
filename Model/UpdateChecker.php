<?php
declare(strict_types=1);
namespace ETechFlow\BackInStockNotification\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

class UpdateChecker
{
    public const PACKAGE      = 'etechflow/module-back-in-stock-notification';
    private const LATEST_URL  = 'https://license-service.etechflow.com/composer/latest/etechflow/module-back-in-stock-notification.json';
    private const CACHE_KEY   = 'etechflow_bisn_update_check';
    private const CACHE_TTL   = 21600;
    private const MODULE_NAME = 'ETechFlow_BackInStockNotification';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ResourceConnection $resource
    ) {}

    public function getAvailableUpdate(): ?array
    {
        try {
            $latest = $this->fetchLatest();
            if ($latest['version'] === '') return null;
            $installed = $this->installedVersion();
            if ($installed === '' || version_compare($installed, $latest['version'], '>=')) return null;
            return ['installed' => $installed, 'latest' => $latest['version'], 'notes' => $latest['notes'], 'package' => self::PACKAGE];
        } catch (\Throwable $e) { return null; }
    }

    public function getUpdateCommand(): string { return 'composer update ' . self::PACKAGE; }

    private function fetchLatest(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);
        if (!$raw) {
            $raw = '{}';
            try {
                $curl = $this->curlFactory->create(); $curl->setTimeout(5);
                $curl->get(self::LATEST_URL);
                if ((int) $curl->getStatus() === 200) $raw = (string) $curl->getBody();
            } catch (\Throwable $e) {}
            $this->cache->save($raw, self::CACHE_KEY, [], self::CACHE_TTL);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || empty($data['latest_version'])) return ['version' => '', 'notes' => ''];
        return ['version' => (string)$data['latest_version'], 'notes' => (string)($data['release_notes'] ?? '')];
    }

    private function installedVersion(): string
    {
        if (class_exists('\\Composer\\InstalledVersions')) {
            try { $v = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE); if ($v) return ltrim((string)$v, 'v'); } catch (\Throwable $e) {}
        }
        try {
            $v = $this->resource->getConnection()->fetchOne(
                'SELECT schema_version FROM ' . $this->resource->getTableName('setup_module') . ' WHERE module = ?',
                [self::MODULE_NAME]
            );
            if ($v) return ltrim((string)$v, 'v');
        } catch (\Throwable $e) {}
        try {
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
            if ($path && is_file($path . '/composer.json')) {
                $json = json_decode((string) file_get_contents($path . '/composer.json'), true);
                if (!empty($json['version'])) return ltrim((string)$json['version'], 'v');
            }
        } catch (\Throwable $e) {}
        return '';
    }
}
