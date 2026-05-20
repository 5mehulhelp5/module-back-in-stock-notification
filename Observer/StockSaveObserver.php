<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Observer;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Adapter\NdeEligibilityAdapter;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\NotificationQueueRepository;
use ETechFlow\BackInStockNotification\Model\NotificationQueueFactory;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects qty going from 0 → positive and enqueues notifications.
 *
 * Fired by Magento's `cataloginventory_stock_item_save_after` event,
 * which runs on every stock save (product save, inventory import,
 * REST API, admin grid update, partial-shipment update, etc.).
 *
 * Performance contract: this observer fires CONSTANTLY on busy stores.
 * It MUST be cheap on the non-restock path:
 *   1. Bail immediately if BISN is licence-disabled.
 *   2. Bail if the stock item's qty didn't transition 0 → positive.
 *   3. Bail if no subscriptions exist for this (product, store).
 * Only on the last-and-rare case do we touch the queue table.
 */
class StockSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly NotificationQueueRepository $queueRepository,
        private readonly NotificationQueueFactory $queueFactory,
        private readonly NdeEligibilityAdapter $ndeAdapter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $span = Profiler::start('ETechFlow_BISN_StockSaveObserver');
        try {
            /** @var StockItemInterface|null $stockItem */
            $stockItem = $observer->getEvent()->getData('item');
            if (!$stockItem instanceof StockItemInterface) {
                return;
            }

            $productId = (int) $stockItem->getProductId();
            if ($productId <= 0) {
                return;
            }

            // Detect the 0 → positive transition. Magento sets origData() on
            // load from DB; if origData('qty') was 0 or null and current
            // is_in_stock + qty > 0, that's the restock signal.
            if (!$this->isRestockTransition($stockItem)) {
                return;
            }

            // For each store the product is visible in, check for subs and enqueue.
            // For simplicity (and because most stock changes apply globally), we
            // iterate over stores the product belongs to. A more granular
            // implementation would only touch stores whose actual sellable state
            // changed; that's a v1.1 optimisation.
            foreach ($this->getStoreIdsForProduct($productId) as $storeId) {
                $subs = $this->subscriptionRepository->getActiveForProductAndStore($productId, $storeId);
                if (!$subs) {
                    continue;
                }

                // NDE integration: when on, filter subs to only those for whom
                // NDE's eligibility check ALSO returns "eligible". Avoids
                // sending "back in stock" when the product is technically in
                // stock but still NDE-ineligible for shipping.
                if ($this->config->isNdeEligibilityEnabled()
                    && !$this->ndeAdapter->isProductEligible($productId, $storeId)) {
                    continue;
                }

                $this->enqueueBatch($subs, $productId, $storeId);
            }
        } catch (\Throwable $e) {
            // Never let our observer break the stock save itself. Log + swallow.
            $this->logger->warning(
                'ETechFlow_BISN StockSaveObserver suppressed exception',
                ['exception' => $e->getMessage()]
            );
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Returns true only when the row's qty crossed from "not sellable" to
     * "sellable". Compares current sellable state vs the row's prior state.
     */
    private function isRestockTransition(StockItemInterface $stockItem): bool
    {
        $currentlySellable = ((float) $stockItem->getQty() > 0.0) && (bool) $stockItem->getIsInStock();
        if (!$currentlySellable) {
            return false;
        }

        // origData() is available on AbstractModel descendants; defend against
        // 3rd-party StockItem replacements that don't implement it.
        if (!method_exists($stockItem, 'getOrigData')) {
            // Best effort: treat any sellable save as a transition. False
            // positives are tolerable — the queue dedupes by subscription_id
            // and only sends each sub once.
            return true;
        }

        $origQty = (float) ($stockItem->getOrigData('qty') ?? 0);
        $origInStock = (bool) ($stockItem->getOrigData('is_in_stock') ?? 0);
        $wasSellable = $origQty > 0.0 && $origInStock;

        return !$wasSellable;
    }

    /**
     * Stores this product is visible in. Stub for now — most installs are
     * single-store so we return [0] (admin/all-stores).
     *
     * v1.1: walk catalog_product_website + store_website to find the actual
     * set of store_ids the product is sellable in.
     *
     * @return int[]
     */
    private function getStoreIdsForProduct(int $productId): array
    {
        return [0];
    }

    /**
     * @param SubscriptionInterface[] $subs
     */
    private function enqueueBatch(array $subs, int $productId, int $storeId): void
    {
        foreach ($subs as $sub) {
            try {
                $row = $this->queueFactory->create();
                $row->setSubscriptionId((int) $sub->getSubscriptionId())
                    ->setProductId($productId)
                    ->setStoreId($storeId)
                    ->setStatus('queued')
                    ->setAttempts(0)
                    ->setScheduledAt(date('Y-m-d H:i:s'));
                $this->queueRepository->save($row);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ETechFlow_BISN failed to enqueue notification',
                    ['subscription_id' => $sub->getSubscriptionId(), 'exception' => $e->getMessage()]
                );
            }
        }
    }
}
