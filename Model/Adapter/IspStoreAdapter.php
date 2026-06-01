<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Adapter;

use Psr\Log\LoggerInterface;

/**
 * Optional ISP (InStorePickup) integration.
 *
 * When ISP is installed AND the admin toggle is on, customers can
 * subscribe to a specific pickup store's stock — "notify me when back
 * at the Test London store" — not just global stock.
 *
 * v1.0 ships the contract; the actual per-store stock detection wires
 * up in v1.1 once Magento's MSI-source-to-ISP-store mapping is fully
 * wired. This adapter currently returns the list of active pickup
 * stores so the PDP form can offer the dropdown.
 */
class IspStoreAdapter
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns the list of active ISP stores as [['id' => int, 'name' => string], ...].
     * Empty array if ISP isn't installed.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function getActiveStores(): array
    {
        if (!class_exists(\ETechFlow\InStorePickup\Api\StoreRepositoryInterface::class)) {
            return [];
        }

        try {
            $repo = $this->getIspStoreRepository();
            if (!$repo) {
                return [];
            }
            if (!method_exists($repo, 'getActiveList')) {
                return [];
            }
            $rows = [];
            foreach ($repo->getActiveList() as $store) {
                $id = method_exists($store, 'getStoreId') ? (int) $store->getStoreId() : 0;
                $name = method_exists($store, 'getName') ? (string) $store->getName() : '';
                if ($id > 0 && $name !== '') {
                    $rows[] = ['id' => $id, 'name' => $name];
                }
            }
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_BISN ISP adapter suppressed exception',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    private function getIspStoreRepository()
    {
        if (!class_exists(\Magento\Framework\App\ObjectManager::class)) {
            return null;
        }
        try {
            return \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\ETechFlow\InStorePickup\Api\StoreRepositoryInterface::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}