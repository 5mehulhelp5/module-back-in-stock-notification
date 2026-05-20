<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Adapter;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Optional BED (BackorderEtaDisplay) integration.
 *
 * When BED is installed AND the admin toggle is on, the back-in-stock
 * email body can include BED's per-product ETA — useful when the restock
 * is itself a future backorder ("Back on Jun 12!" instead of just
 * "Back in stock!").
 *
 * When BED is absent, returns null and the email body falls back to
 * the generic "Back in stock now" wording.
 */
class BedEtaAdapter
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns a future ETA date for the product if BED has one, else null.
     */
    public function getEtaForProduct(int $productId, int $storeId): ?string
    {
        if (!class_exists(\ETechFlow\BackorderEtaDisplay\Model\EtaResolver::class)) {
            return null;
        }

        try {
            $resolver = $this->getBedResolver();
            if (!$resolver) {
                return null;
            }
            $product = $this->productRepository->getById($productId, false, $storeId ?: null);
            if (method_exists($resolver, 'getEtaDate')) {
                $eta = $resolver->getEtaDate($product);
                return is_string($eta) && $eta !== '' ? $eta : null;
            }
            return null;
        } catch (NoSuchEntityException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_BISN BED adapter suppressed exception',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }

    private function getBedResolver()
    {
        if (!class_exists(\Magento\Framework\App\ObjectManager::class)) {
            return null;
        }
        try {
            return \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\ETechFlow\BackorderEtaDisplay\Model\EtaResolver::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
