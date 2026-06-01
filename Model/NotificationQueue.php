<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use ETechFlow\BackInStockNotification\Model\ResourceModel\NotificationQueue as NotificationQueueResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Light-weight queue row. Not exposed via service contract because the
 * queue is implementation detail — only the StockSaveObserver enqueues
 * and only the QueueConsumer cron dequeues.
 *
 * Lifecycle:
 *   queued  → consumer picks it up    → sending
 *   sending → SMTP success            → sent
 *   sending → SMTP transient failure  → queued (attempts++ + back-off)
 *   sending → SMTP after 3 attempts   → failed
 *   * → admin cancel                  → cancelled
 */
class NotificationQueue extends AbstractModel
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const MAX_ATTEMPTS = 3;

    protected function _construct(): void
    {
        $this->_init(NotificationQueueResource::class);
    }

    public function getQueueId(): ?int { $v = $this->getData('queue_id'); return $v === null ? null : (int) $v; }
    public function setQueueId(?int $id): self { return $this->setData('queue_id', $id); }

    public function getSubscriptionId(): int { return (int) $this->getData('subscription_id'); }
    public function setSubscriptionId(int $id): self { return $this->setData('subscription_id', $id); }

    public function getProductId(): int { return (int) $this->getData('product_id'); }
    public function setProductId(int $id): self { return $this->setData('product_id', $id); }

    public function getStoreId(): int { return (int) $this->getData('store_id'); }
    public function setStoreId(int $id): self { return $this->setData('store_id', $id); }

    public function getStatus(): string { return (string) ($this->getData('status') ?: self::STATUS_QUEUED); }
    public function setStatus(string $s): self { return $this->setData('status', $s); }

    public function getAttempts(): int { return (int) $this->getData('attempts'); }
    public function setAttempts(int $n): self { return $this->setData('attempts', $n); }

    public function getLastError(): ?string { $v = $this->getData('last_error'); return $v === null ? null : (string) $v; }
    public function setLastError(?string $err): self { return $this->setData('last_error', $err); }

    public function getScheduledAt(): ?string { $v = $this->getData('scheduled_at'); return $v === null ? null : (string) $v; }
    public function setScheduledAt(?string $dt): self { return $this->setData('scheduled_at', $dt); }

    public function getSentAt(): ?string { $v = $this->getData('sent_at'); return $v === null ? null : (string) $v; }
    public function setSentAt(?string $dt): self { return $this->setData('sent_at', $dt); }

    public function getCreatedAt(): ?string { $v = $this->getData('created_at'); return $v === null ? null : (string) $v; }
    public function setCreatedAt(?string $dt): self { return $this->setData('created_at', $dt); }
}