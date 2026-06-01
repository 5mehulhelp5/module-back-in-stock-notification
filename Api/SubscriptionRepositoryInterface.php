<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Api;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Service contract for subscription persistence.
 *
 * The Repository handles every persistence concern (token generation on
 * insert, email canonicalisation, dupe detection). Callers just hand it
 * a Subscription and get one back.
 */
interface SubscriptionRepositoryInterface
{
    /**
     * Save or update. New rows auto-generate unsubscribe_token if empty.
     *
     * @throws CouldNotSaveException
     */
    public function save(SubscriptionInterface $subscription): SubscriptionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): SubscriptionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByToken(string $token): SubscriptionInterface;

    /**
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface;

    /**
     * Find all active (pending or confirmed) subscriptions for a product in a store.
     * Used by the StockSaveObserver to enqueue notifications.
     *
     * @return SubscriptionInterface[]
     */
    public function getActiveForProductAndStore(int $productId, int $storeId): array;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(SubscriptionInterface $subscription): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $id): bool;
}