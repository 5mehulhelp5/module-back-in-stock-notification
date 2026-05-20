<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Adapter;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Optional NDE (NextDayEligibility) integration.
 *
 * Standalone-first: this class is ALWAYS instantiated, but only calls
 * into NDE when:
 *   1. The NDE classes exist (`class_exists`), AND
 *   2. The admin toggle "Use NDE Eligibility Rules" is on.
 *
 * When NDE is absent or the toggle is off, all methods return the
 * default "in stock = qty > 0" answer.
 *
 * Why this design: the BISN module shouldn't `require` NDE — that would
 * force customers who only want BISN to install NDE too. Composer
 * `suggest` lists NDE; this adapter is what allows BISN to use NDE if
 * it happens to be present.
 */
class NdeEligibilityAdapter
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns true if the product is "really" eligible to be considered
     * back-in-stock — respecting NDE's rules when installed.
     *
     * Without NDE: any product with positive stock is eligible.
     * With NDE: NDE's IneligibilityChecker has the final say (covers
     * drop-ship + supplier mode + force-standard overrides).
     */
    public function isProductEligible(int $productId, int $storeId): bool
    {
        // Without NDE installed, defer to caller's existing qty-based check.
        if (!class_exists(\ETechFlow\NextDayEligibility\Model\IneligibilityChecker::class)) {
            return true;
        }

        try {
            $product = $this->productRepository->getById($productId, false, $storeId ?: null);
            $checker = $this->getNdeChecker();
            if (!$checker) {
                return true;
            }
            // NDE's IneligibilityChecker exposes isProductIneligible(); invert.
            if (method_exists($checker, 'isProductIneligible')) {
                return !$checker->isProductIneligible($product);
            }
            // API drift defence — assume eligible if NDE's API changed.
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_BISN NDE adapter suppressed exception',
                ['exception' => $e->getMessage()]
            );
            return true;
        }
    }

    /**
     * Resolve NDE's IneligibilityChecker via Magento's ObjectManager.
     * We don't inject it directly because the class may not exist; DI
     * would fail at compile time.
     */
    private function getNdeChecker()
    {
        if (!class_exists(\Magento\Framework\App\ObjectManager::class)) {
            return null;
        }
        try {
            return \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\ETechFlow\NextDayEligibility\Model\IneligibilityChecker::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
