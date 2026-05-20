<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Test\Unit\Model;

use ETechFlow\BackInStockNotification\Model\LicenseValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LicenseValidator.
 *
 * Tests pure-PHP logic — no Magento bootstrap required. Covers:
 *   - HMAC computation determinism (same host + same secret = same key)
 *   - www-normalization (www.foo.com === foo.com)
 *   - Dev-host detection patterns (TLD, subdomain prefix, RFC 1918,
 *     Adobe Cloud, tunnels)
 *   - Production vs non-production gating
 *   - Per-module key vs bundle key precedence
 */
class LicenseValidatorTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    private LicenseValidator $validator;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->validator = new LicenseValidator($this->scopeConfig, $this->storeManager);
    }

    /**
     * Two computeKey() calls with the same host must yield the same key.
     * This is the bedrock invariant — if it breaks, all licences fail.
     */
    public function testComputeKeyIsDeterministic(): void
    {
        $key1 = $this->validator->computeKey('coolstore.com');
        $key2 = $this->validator->computeKey('coolstore.com');
        $this->assertSame($key1, $key2);
        $this->assertNotSame('', $key1, 'computeKey() should return a non-empty token');
    }

    /**
     * www.foo.com and foo.com must canonicalise to the same key. Otherwise
     * a merchant who issued a key for "coolstore.com" but typed their site
     * URL as "www.coolstore.com" gets gated.
     */
    public function testWwwIsStrippedDuringCanonicalisation(): void
    {
        $keyWithWww = $this->validator->computeKey('www.coolstore.com');
        $keyWithoutWww = $this->validator->computeKey('coolstore.com');
        $this->assertSame($keyWithWww, $keyWithoutWww);
    }

    /**
     * Case-insensitive canonicalisation. Magento sometimes uppercases hosts
     * during URL handling; the key must still match.
     */
    public function testHostIsLowercasedDuringCanonicalisation(): void
    {
        $keyUpper = $this->validator->computeKey('CoolStore.COM');
        $keyLower = $this->validator->computeKey('coolstore.com');
        $this->assertSame($keyUpper, $keyLower);
    }

    /**
     * Different hosts must yield different keys. Otherwise one key would
     * activate every install — total revenue collapse.
     */
    public function testDifferentHostsYieldDifferentKeys(): void
    {
        $keyA = $this->validator->computeKey('coolstore.com');
        $keyB = $this->validator->computeKey('badstore.com');
        $this->assertNotSame($keyA, $keyB);
    }

    /**
     * computeKey() (per-module) must differ from computeBundleKey() (suite)
     * because they use different secrets + module identifiers.
     */
    public function testPerModuleKeyDiffersFromBundleKey(): void
    {
        $perModule = $this->validator->computeKey('coolstore.com');
        $bundle = $this->validator->computeBundleKey('coolstore.com');
        $this->assertNotSame($perModule, $bundle);
    }

    /**
     * Whitespace in the host config must be trimmed — guards against a
     * trailing newline or space in the env config breaking the match.
     */
    public function testCanonicalisationTrimsWhitespace(): void
    {
        // The canonicalize() method is private but we can test via computeKey()
        $key1 = $this->validator->computeKey('  coolstore.com  ');
        $key2 = $this->validator->computeKey('coolstore.com');
        $this->assertSame($key1, $key2);
    }

    /**
     * Dev-host bypass MUST trigger for the well-known patterns, even on
     * "production environment = yes". The merchant should not need to
     * configure anything for staging/local dev to work.
     */
    public function testDevHostsAreRecognised(): void
    {
        $devHosts = [
            'localhost',
            '127.0.0.1',
            '10.0.0.50',
            '172.16.5.10',
            '192.168.1.100',
            'shop.test',
            'shop.local',
            'shop.localhost',
            'shop.dev',
            'staging.coolstore.com',
            'dev.coolstore.com',
            'qa.coolstore.com',
            'sandbox.coolstore.com',
            'coolstore-staging.com',
            'coolstore.magento.cloud',
            'project.ngrok.io',
            'project.ngrok-free.app',
            'project.loca.lt',
        ];
        foreach ($devHosts as $host) {
            $this->assertTrue(
                $this->validator->isDevHost($host),
                "Host '$host' should be recognised as a dev host"
            );
        }
    }

    /**
     * Production hosts must NOT match the dev-host bypass. Critical — a
     * merchant on coolstore.com must require a key.
     */
    public function testProductionHostsAreNotMistakenForDev(): void
    {
        $prodHosts = [
            'coolstore.com',
            'www.coolstore.com',
            'shop.coolstore.com',
            'eu.shop.coolstore.com',
            'verylongproductiondomain-with-hyphens.co.uk',
        ];
        foreach ($prodHosts as $host) {
            $this->assertFalse(
                $this->validator->isDevHost($host),
                "Host '$host' should NOT be recognised as a dev host"
            );
        }
    }

    /**
     * On a dev host, isValid() must return TRUE even with no licence key
     * set and no production_environment override.
     */
    public function testIsValidOnDevHostBypassesEverything(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Empty config values — no licence key, no bundle key
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertTrue($this->validator->isValid());
    }

    /**
     * On a production host with no licence configured, isValid() must
     * return FALSE — that's the whole point of the gate.
     */
    public function testIsValidOnProdHostWithNoKeyIsFalse(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://coolstore.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Production environment + no keys
        $this->scopeConfig->method('getValue')->willReturnMap([
            [LicenseValidator::XML_PATH_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT, 'store', null, '1'],
        ]);

        $this->assertFalse($this->validator->isValid());
    }

    /**
     * On a production host with the right per-module key set, isValid()
     * must return TRUE.
     */
    public function testIsValidWithCorrectPerModuleKey(): void
    {
        $host = 'coolstore.com';
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn("https://$host/");
        $this->storeManager->method('getStore')->willReturn($store);

        $correctKey = $this->validator->computeKey($host);

        $this->scopeConfig->method('getValue')->willReturnMap([
            [LicenseValidator::XML_PATH_LICENSE_KEY, 'store', null, $correctKey],
            [LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT, 'store', null, '1'],
        ]);

        $this->assertTrue($this->validator->isValid());
    }

    /**
     * On a production host with a tampered key, isValid() must return FALSE.
     * Defends against trivial "I'll just paste a random base64 string"
     * piracy attempts.
     */
    public function testIsValidWithTamperedKeyIsFalse(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://coolstore.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->scopeConfig->method('getValue')->willReturnMap([
            [LicenseValidator::XML_PATH_LICENSE_KEY, 'store', null, 'NOT-A-REAL-KEY-1234567890'],
            [LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT, 'store', null, '1'],
        ]);

        $this->assertFalse($this->validator->isValid());
    }

    /**
     * The bundle key activates the module even when no per-module key is set.
     * Tests the "one key, all modules" suite flow.
     */
    public function testBundleKeyActivatesModule(): void
    {
        $host = 'coolstore.com';
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn("https://$host/");
        $this->storeManager->method('getStore')->willReturn($store);

        $bundleKey = $this->validator->computeBundleKey($host);

        $this->scopeConfig->method('getValue')->willReturnMap([
            [LicenseValidator::XML_PATH_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY, 'store', null, $bundleKey],
            [LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT, 'store', null, '1'],
        ]);

        $this->assertTrue($this->validator->isValid());
    }

    /**
     * production_environment = No on a production host bypasses licensing.
     * Useful for merchants doing local DB dumps where the host LOOKS like
     * prod but the env is staging.
     */
    public function testProductionEnvironmentFlagOffBypassesLicensing(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://coolstore.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->scopeConfig->method('getValue')->willReturnMap([
            [LicenseValidator::XML_PATH_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY, 'store', null, ''],
            [LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT, 'store', null, '0'],
        ]);

        $this->assertTrue($this->validator->isValid());
    }

    /**
     * Empty host (e.g. CLI context where storeManager throws) must return
     * isValid() = false rather than crashing.
     */
    public function testEmptyHostReturnsFalse(): void
    {
        $this->storeManager->method('getStore')->willThrowException(new \Exception('CLI context'));
        $this->assertFalse($this->validator->isValid());
    }
}
