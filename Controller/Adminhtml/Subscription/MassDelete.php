<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Adminhtml\Subscription;

use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

class MassDelete extends Action
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

        $deleted = 0;
        $failed  = 0;
        foreach ($collection as $sub) {
            try {
                $this->subscriptionRepository->delete($sub);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $this->messageManager->addSuccessMessage(
                __('Deleted %1 subscription(s).', $deleted)
            );
        }
        if ($failed > 0) {
            $this->messageManager->addErrorMessage(
                __('Failed to delete %1 subscription(s).', $failed)
            );
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index');
    }
}