<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Adminhtml\Subscription;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Cancel (don't delete) — keeps the row for audit + prevents the same
 * customer re-subscribing in the lifetime window. Use Delete to remove
 * the audit trail too.
 */
class MassCancel extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_BackInStockNotification::subscriptions_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $cancelled = 0;
        foreach ($collection as $sub) {
            if (in_array($sub->getStatus(), [
                SubscriptionInterface::STATUS_NOTIFIED,
                SubscriptionInterface::STATUS_CANCELLED,
                SubscriptionInterface::STATUS_EXPIRED,
            ], true)) {
                continue;
            }
            try {
                $sub->setStatus(SubscriptionInterface::STATUS_CANCELLED);
                $this->subscriptionRepository->save($sub);
                $cancelled++;
            } catch (\Throwable $e) {
                // swallow — counted by absence in the success count
            }
        }

        $this->messageManager->addSuccessMessage(
            __('Cancelled %1 active subscription(s).', $cancelled)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index');
    }
}