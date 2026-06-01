<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Api\Data;

/**
 * Service contract for a single back-in-stock subscription.
 *
 * One row per (email, product, store, store_filter). Anonymous or
 * customer-linked: customer_id is nullable, email is the canonical
 * identity.
 *
 * Status lifecycle:
 *   pending → (double-opt-in confirmed) → confirmed → notified → DONE
 *   pending → cancelled (by customer unsubscribe or admin action)
 *   pending → expired (by lifetime auto-cleanup cron)
 */
interface SubscriptionInterface
{
    public const SUBSCRIPTION_ID    = 'subscription_id';
    public const CUSTOMER_ID        = 'customer_id';
    public const EMAIL              = 'email';
    public const FIRST_NAME         = 'first_name';
    public const PRODUCT_ID         = 'product_id';
    public const STORE_ID           = 'store_id';
    public const STORE_FILTER_ID    = 'store_filter_id';
    public const STATUS             = 'status';
    public const UNSUBSCRIBE_TOKEN  = 'unsubscribe_token';
    public const SUBSCRIBED_AT      = 'subscribed_at';
    public const CONFIRMED_AT       = 'confirmed_at';
    public const NOTIFIED_AT        = 'notified_at';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_NOTIFIED  = 'notified';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    public function getSubscriptionId(): ?int;
    public function setSubscriptionId(?int $id): self;

    public function getCustomerId(): ?int;
    public function setCustomerId(?int $customerId): self;

    public function getEmail(): string;
    public function setEmail(string $email): self;

    public function getFirstName(): ?string;
    public function setFirstName(?string $name): self;

    public function getProductId(): int;
    public function setProductId(int $productId): self;

    public function getStoreId(): int;
    public function setStoreId(int $storeId): self;

    public function getStoreFilterId(): ?int;
    public function setStoreFilterId(?int $storeFilterId): self;

    public function getStatus(): string;
    public function setStatus(string $status): self;

    public function getUnsubscribeToken(): string;
    public function setUnsubscribeToken(string $token): self;

    public function getSubscribedAt(): ?string;
    public function setSubscribedAt(?string $datetime): self;

    public function getConfirmedAt(): ?string;
    public function setConfirmedAt(?string $datetime): self;

    public function getNotifiedAt(): ?string;
    public function setNotifiedAt(?string $datetime): self;
}