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
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
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
        private readonly CollectionFactory $collectionFactory,
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

            // Get every distinct store_id that has an active subscription for
            // this product. Cheaper than walking product-website mappings and
            // correct in all cases: if nobody subscribed on store X for this
            // product, we don't need to touch store X.
            //
            // Why this works: stock changes typically apply globally (one
            // qty + is_in_stock per stock_item row in legacy single-source
            // mode). A 0 → positive transition means the product is now
            // sellable everywhere it's published; we just need to notify
            // every subscription that's waiting on this product, regardless
            // of which store they subscribed on.
            foreach ($this->getStoreIdsWithActiveSubs($productId) as $storeId) {
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
     * Distinct store_ids that have at least one active (pending or confirmed)
     * subscription for this product.
     *
     * Sidesteps the "what stores is the product visible in?" question (which
     * is expensive to compute via catalog_product_website joins) by asking
     * the inverse question that's already indexed on our subscription table:
     * "which stores have someone waiting on this product?"
     *
     * Hot-path: indexed on (product_id, store_id, status).
     *
     * @return int[]
     */
    private function getStoreIdsWithActiveSubs(int $productId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns('store_id')
            ->distinct(true)
            ->where('product_id = ?', $productId)
            ->where('status IN (?)', [
                \ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface::STATUS_PENDING,
                \ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface::STATUS_CONFIRMED,
            ]);
        $storeIds = [];
        foreach ($collection->getConnection()->fetchCol($collection->getSelect()) as $id) {
            $storeIds[] = (int) $id;
        }
        return $storeIds;
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