<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Source;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model backing the admin grid's "Status" select filter.
 */
class StatusOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => SubscriptionInterface::STATUS_PENDING,   'label' => __('Pending')],
            ['value' => SubscriptionInterface::STATUS_CONFIRMED, 'label' => __('Confirmed')],
            ['value' => SubscriptionInterface::STATUS_NOTIFIED,  'label' => __('Notified')],
            ['value' => SubscriptionInterface::STATUS_CANCELLED, 'label' => __('Cancelled')],
            ['value' => SubscriptionInterface::STATUS_EXPIRED,   'label' => __('Expired')],
        ];
    }
}