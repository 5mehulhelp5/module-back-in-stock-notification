<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription as SubscriptionResource;
use Magento\Framework\Model\AbstractModel;

class Subscription extends AbstractModel implements SubscriptionInterface
{
    protected function _construct(): void
    {
        $this->_init(SubscriptionResource::class);
    }

    public function getSubscriptionId(): ?int
    {
        $value = $this->getData(self::SUBSCRIPTION_ID);
        return $value === null ? null : (int) $value;
    }

    public function setSubscriptionId(?int $id): self
    {
        return $this->setData(self::SUBSCRIPTION_ID, $id);
    }

    public function getCustomerId(): ?int
    {
        $value = $this->getData(self::CUSTOMER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setCustomerId(?int $customerId): self
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    public function getEmail(): string
    {
        return (string) $this->getData(self::EMAIL);
    }

    public function setEmail(string $email): self
    {
        return $this->setData(self::EMAIL, strtolower(trim($email)));
    }

    public function getFirstName(): ?string
    {
        $value = $this->getData(self::FIRST_NAME);
        return $value === null ? null : (string) $value;
    }

    public function setFirstName(?string $name): self
    {
        return $this->setData(self::FIRST_NAME, $name !== null ? trim($name) : null);
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): self
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getStoreFilterId(): ?int
    {
        $value = $this->getData(self::STORE_FILTER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setStoreFilterId(?int $storeFilterId): self
    {
        return $this->setData(self::STORE_FILTER_ID, $storeFilterId);
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::STATUS) ?: self::STATUS_PENDING);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getUnsubscribeToken(): string
    {
        return (string) $this->getData(self::UNSUBSCRIBE_TOKEN);
    }

    public function setUnsubscribeToken(string $token): self
    {
        return $this->setData(self::UNSUBSCRIBE_TOKEN, $token);
    }

    public function getSubscribedAt(): ?string
    {
        $value = $this->getData(self::SUBSCRIBED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setSubscribedAt(?string $datetime): self
    {
        return $this->setData(self::SUBSCRIBED_AT, $datetime);
    }

    public function getConfirmedAt(): ?string
    {
        $value = $this->getData(self::CONFIRMED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setConfirmedAt(?string $datetime): self
    {
        return $this->setData(self::CONFIRMED_AT, $datetime);
    }

    public function getNotifiedAt(): ?string
    {
        $value = $this->getData(self::NOTIFIED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setNotifiedAt(?string $datetime): self
    {
        return $this->setData(self::NOTIFIED_AT, $datetime);
    }
}