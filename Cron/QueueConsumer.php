<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Cron;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\NotificationQueue;
use ETechFlow\BackInStockNotification\Model\NotificationQueueRepository;
use ETechFlow\BackInStockNotification\Model\Notification\BackInStockSender;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Drains the notification queue at the admin-configured rate.
 *
 * Runs every 5 min by default (see etc/crontab.xml). Pulls up to
 * `email_rate_limit` rows per run, sends each one, marks the result.
 *
 * Failure handling:
 *   - SMTP transient → status back to "queued", attempts++, scheduled_at
 *     pushed back by exponential back-off (5 min, 30 min, 2 hr)
 *   - SMTP definitive (bounce, malformed addr) → status="failed"
 *   - Subscription deleted between enqueue and send → status="cancelled"
 *   - Already-notified subscription (race) → status="cancelled"
 */
class QueueConsumer
{
    public function __construct(
        private readonly Config $config,
        private readonly NotificationQueueRepository $queueRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly BackInStockSender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $span = Profiler::start('ETechFlow_BISN_QueueConsumer');
        try {
            $batchSize = $this->computeBatchSize();
            $rows = $this->queueRepository->getDue($batchSize);
            foreach ($rows as $row) {
                $this->processOne($row);
            }
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Cron fires every 5 min by default. Rate limit is per minute, so each
     * run gets up to (rate × 5) rows. Capped at 1000 to be safe.
     */
    private function computeBatchSize(): int
    {
        $perMinute = max(1, $this->config->getEmailRateLimit());
        return min(1000, $perMinute * 5);
    }

    private function processOne(NotificationQueue $row): void
    {
        try {
            $subscription = $this->subscriptionRepository->getById($row->getSubscriptionId());
        } catch (NoSuchEntityException $e) {
            $row->setStatus(NotificationQueue::STATUS_CANCELLED)
                ->setLastError('Subscription deleted before send');
            $this->queueRepository->save($row);
            return;
        }

        // Subscription was already notified by a previous queue row (dupe enqueue).
        if ($subscription->getStatus() === SubscriptionInterface::STATUS_NOTIFIED) {
            $row->setStatus(NotificationQueue::STATUS_CANCELLED)
                ->setLastError('Subscription already notified');
            $this->queueRepository->save($row);
            return;
        }

        // Customer cancelled / unsubscribed.
        if ($subscription->getStatus() === SubscriptionInterface::STATUS_CANCELLED) {
            $row->setStatus(NotificationQueue::STATUS_CANCELLED)
                ->setLastError('Subscription was cancelled');
            $this->queueRepository->save($row);
            return;
        }

        $row->setStatus(NotificationQueue::STATUS_SENDING);
        $this->queueRepository->save($row);

        try {
            $this->sender->send($subscription);

            // Mark sub as notified, queue row as sent.
            $now = date('Y-m-d H:i:s');
            $subscription->setStatus(SubscriptionInterface::STATUS_NOTIFIED)
                ->setNotifiedAt($now);
            $this->subscriptionRepository->save($subscription);
            $row->setStatus(NotificationQueue::STATUS_SENT)
                ->setSentAt($now)
                ->setLastError(null);
            $this->queueRepository->save($row);
        } catch (\Throwable $e) {
            $attempts = $row->getAttempts() + 1;
            $row->setAttempts($attempts)
                ->setLastError($e->getMessage());

            if ($attempts >= NotificationQueue::MAX_ATTEMPTS) {
                $row->setStatus(NotificationQueue::STATUS_FAILED);
                $this->logger->warning(
                    'ETechFlow_BISN gave up after MAX_ATTEMPTS',
                    ['queue_id' => $row->getQueueId(), 'subscription_id' => $subscription->getSubscriptionId()]
                );
            } else {
                // Back-off: 5 min, 30 min, 2 hr
                $backoffSeconds = [300, 1800, 7200][$attempts - 1] ?? 7200;
                $row->setStatus(NotificationQueue::STATUS_QUEUED)
                    ->setScheduledAt(date('Y-m-d H:i:s', time() + $backoffSeconds));
            }
            $this->queueRepository->save($row);
        }
    }
}