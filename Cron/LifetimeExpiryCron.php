<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Cron;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily cron that auto-expires stale subscriptions.
 *
 * Why: a subscription created 18 months ago that was never fulfilled is
 * effectively dead — the customer has forgotten, the product may have
 * been discontinued, the email may now bounce. Sending after that long
 * is a CAN-SPAM risk + spam-folder-reputation risk.
 *
 * Lifetime is admin-configurable. 0 = never expire.
 *
 * Updates status pending|confirmed → expired. Doesn't delete rows
 * (audit / re-subscribe-after-expiry handling). The actual deletion
 * is a separate concern, typically done manually by admin.
 */
class LifetimeExpiryCron
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $lifetimeDays = $this->config->getSubscriptionLifetimeDays();
        if ($lifetimeDays <= 0) {
            return; // 0 = never expire
        }

        $span = Profiler::start('ETechFlow_BISN_LifetimeExpiryCron');
        try {
            // One UPDATE statement — much cheaper than collection iteration on
            // stores with millions of rows. Indexed by (status, subscribed_at).
            $conn = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('etechflow_bisn_subscription');
            $cutoff = date('Y-m-d H:i:s', time() - ($lifetimeDays * 86400));

            $rowsAffected = $conn->update(
                $table,
                ['status' => SubscriptionInterface::STATUS_EXPIRED],
                [
                    'status IN (?)' => [
                        SubscriptionInterface::STATUS_PENDING,
                        SubscriptionInterface::STATUS_CONFIRMED,
                    ],
                    'subscribed_at < ?' => $cutoff,
                ]
            );

            if ($rowsAffected > 0) {
                $this->logger->info(
                    'ETechFlow_BISN expired stale subscriptions',
                    ['count' => $rowsAffected, 'cutoff' => $cutoff, 'lifetime_days' => $lifetimeDays]
                );
            }
        } finally {
            Profiler::stop($span);
        }
    }
}
