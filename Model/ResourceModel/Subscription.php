<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Subscription extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('etechflow_bisn_subscription', 'subscription_id');
    }
}