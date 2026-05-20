<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\ResourceModel\NotificationQueue;

use ETechFlow\BackInStockNotification\Model\NotificationQueue;
use ETechFlow\BackInStockNotification\Model\ResourceModel\NotificationQueue as NotificationQueueResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'queue_id';

    protected function _construct(): void
    {
        $this->_init(NotificationQueue::class, NotificationQueueResource::class);
    }
}
