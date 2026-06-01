<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Block\Customer;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\Collection;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the customer's active subscriptions on the account dashboard page.
 */
class SubscriptionList extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return SubscriptionInterface[]
     */
    public function getSubscriptions(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return [];
        }
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter(SubscriptionInterface::CUSTOMER_ID, $customerId)
            ->addFieldToFilter(SubscriptionInterface::STATUS, [
                'in' => [
                    SubscriptionInterface::STATUS_PENDING,
                    SubscriptionInterface::STATUS_CONFIRMED,
                ]
            ])
            ->setOrder(SubscriptionInterface::SUBSCRIBED_AT, Collection::SORT_ORDER_DESC);

        return $collection->getItems();
    }

    public function getProductName(int $productId): string
    {
        try {
            return (string) $this->productRepository->getById($productId)->getName();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getProductUrl(int $productId): string
    {
        try {
            return (string) $this->productRepository->getById($productId)->getProductUrl();
        } catch (\Exception $e) {
            return '#';
        }
    }

    public function getUnsubscribeUrl(string $token): string
    {
        return $this->getUrl(
            'etechflow_bisn/subscription/unsubscribe',
            ['token' => $token, '_secure' => true]
        );
    }
}