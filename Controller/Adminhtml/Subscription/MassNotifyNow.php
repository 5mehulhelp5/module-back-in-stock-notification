<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Adminhtml\Subscription;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Model\NotificationQueue;
use ETechFlow\BackInStockNotification\Model\NotificationQueueFactory;
use ETechFlow\BackInStockNotification\Model\NotificationQueueRepository;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Admin force-action: enqueue notifications for the selected rows
 * immediately, bypassing the "qty went 0 → positive" trigger.
 *
 * Useful when:
 *   - Admin restocked outside Magento and wants to manually notify
 *   - Testing the email template against real data
 *   - The observer didn't fire for some reason (3rd-party stock module)
 *
 * The cron QueueConsumer still drains the queue at the rate limit, so
 * "notify now" doesn't mean "send 5000 emails immediately" — it means
 * "queue them for the next cron tick".
 */
class MassNotifyNow extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_BackInStockNotification::subscriptions_notify_now';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly NotificationQueueFactory $queueFactory,
        private readonly NotificationQueueRepository $queueRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $queued = 0;
        foreach ($collection as $sub) {
            if (in_array($sub->getStatus(), [
                SubscriptionInterface::STATUS_NOTIFIED,
                SubscriptionInterface::STATUS_CANCELLED,
                SubscriptionInterface::STATUS_EXPIRED,
            ], true)) {
                continue;
            }
            try {
                $row = $this->queueFactory->create();
                $row->setSubscriptionId((int) $sub->getSubscriptionId())
                    ->setProductId((int) $sub->getProductId())
                    ->setStoreId((int) $sub->getStoreId())
                    ->setStatus(NotificationQueue::STATUS_QUEUED)
                    ->setAttempts(0)
                    ->setScheduledAt(date('Y-m-d H:i:s'));
                $this->queueRepository->save($row);
                $queued++;
            } catch (\Throwable $e) {
                // swallow — counted by absence
            }
        }

        $this->messageManager->addSuccessMessage(
            __('Queued %1 notification(s) — will send on next cron tick.', $queued)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index');
    }
}