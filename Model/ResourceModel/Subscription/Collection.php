<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription;

use ETechFlow\BackInStockNotification\Model\Subscription as SubscriptionModel;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription as SubscriptionResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'subscription_id';

    protected function _construct(): void
    {
        $this->_init(SubscriptionModel::class, SubscriptionResource::class);
    }
}