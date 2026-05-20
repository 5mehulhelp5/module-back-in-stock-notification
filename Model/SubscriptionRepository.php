<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription as SubscriptionResource;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;

class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly SubscriptionFactory $subscriptionFactory,
        private readonly SubscriptionResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly Random $random
    ) {
    }

    public function save(SubscriptionInterface $subscription): SubscriptionInterface
    {
        if ($subscription->getUnsubscribeToken() === '') {
            // 48 bytes of base64 → 64 chars URL-safe; matches column length.
            $subscription->setUnsubscribeToken(
                rtrim(strtr(base64_encode($this->random->getRandomBytes(48)), '+/', '-_'), '=')
            );
        }
        try {
            $this->resource->save($subscription);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save subscription: %1', $e->getMessage()), $e);
        }
        return $subscription;
    }

    public function getById(int $id): SubscriptionInterface
    {
        $subscription = $this->subscriptionFactory->create();
        $this->resource->load($subscription, $id);
        if (!$subscription->getSubscriptionId()) {
            throw new NoSuchEntityException(__('Subscription %1 does not exist', $id));
        }
        return $subscription;
    }

    public function getByToken(string $token): SubscriptionInterface
    {
        $subscription = $this->subscriptionFactory->create();
        $this->resource->load($subscription, $token, SubscriptionInterface::UNSUBSCRIBE_TOKEN);
        if (!$subscription->getSubscriptionId()) {
            throw new NoSuchEntityException(__('Subscription token not found'));
        }
        return $subscription;
    }

    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        foreach ($criteria->getFilterGroups() as $group) {
            $this->applyFilterGroup($collection, $group);
        }
        $sortOrders = $criteria->getSortOrders();
        if ($sortOrders) {
            foreach ($sortOrders as $sortOrder) {
                $collection->addOrder(
                    $sortOrder->getField(),
                    $sortOrder->getDirection() === 'ASC' ? 'ASC' : 'DESC'
                );
            }
        }
        if ($criteria->getPageSize()) {
            $collection->setPageSize($criteria->getPageSize());
        }
        if ($criteria->getCurrentPage()) {
            $collection->setCurPage($criteria->getCurrentPage());
        }
        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($criteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());
        return $results;
    }

    public function getActiveForProductAndStore(int $productId, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter(SubscriptionInterface::PRODUCT_ID, $productId)
            ->addFieldToFilter(SubscriptionInterface::STORE_ID, $storeId)
            ->addFieldToFilter(
                SubscriptionInterface::STATUS,
                ['in' => [SubscriptionInterface::STATUS_PENDING, SubscriptionInterface::STATUS_CONFIRMED]]
            );
        return $collection->getItems();
    }

    public function delete(SubscriptionInterface $subscription): bool
    {
        try {
            $this->resource->delete($subscription);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete subscription: %1', $e->getMessage()), $e);
        }
        return true;
    }

    public function deleteById(int $id): bool
    {
        return $this->delete($this->getById($id));
    }

    private function applyFilterGroup($collection, FilterGroup $group): void
    {
        $fields = [];
        $conditions = [];
        foreach ($group->getFilters() as $filter) {
            $cond = $filter->getConditionType() ?: 'eq';
            $fields[] = $filter->getField();
            $conditions[] = [$cond => $filter->getValue()];
        }
        if ($fields) {
            $collection->addFieldToFilter($fields, $conditions);
        }
    }
}
