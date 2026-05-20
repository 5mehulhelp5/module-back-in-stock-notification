<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model;

use ETechFlow\BackInStockNotification\Model\ResourceModel\NotificationQueue as NotificationQueueResource;
use ETechFlow\BackInStockNotification\Model\ResourceModel\NotificationQueue\CollectionFactory;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Light-weight repository for the queue table. NOT exposed as a service
 * contract — the queue is an internal implementation detail.
 *
 * The producer (StockSaveObserver) only calls save(). The consumer
 * (QueueConsumer cron) calls getDue() + save(). That's the whole API.
 */
class NotificationQueueRepository
{
    public function __construct(
        private readonly NotificationQueueResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(NotificationQueue $row): NotificationQueue
    {
        try {
            $this->resource->save($row);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save notification queue row: %1', $e->getMessage()),
                $e
            );
        }
        return $row;
    }

    /**
     * Fetch rows ready to send, ordered oldest first.
     *
     * @return NotificationQueue[]
     */
    public function getDue(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter('status', NotificationQueue::STATUS_QUEUED)
            ->addFieldToFilter('scheduled_at', ['lteq' => date('Y-m-d H:i:s')])
            ->setOrder('scheduled_at', 'ASC')
            ->setOrder('queue_id', 'ASC');
        if ($limit > 0) {
            $collection->setPageSize($limit);
        }
        return $collection->getItems();
    }
}
