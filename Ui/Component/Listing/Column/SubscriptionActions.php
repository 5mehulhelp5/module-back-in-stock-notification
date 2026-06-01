<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Per-row action column for the subscriptions grid.
 *
 * Three actions:
 *   - Delete  → mass-delete-route with a single row preselected
 *   - Cancel  → mass-cancel-route with a single row preselected
 *
 * (We deliberately route through the mass-action controllers so there's
 * exactly one code path for delete + cancel logic. Saves us writing two
 * extra single-row controllers.)
 */
class SubscriptionActions extends Column
{
    private const URL_PATH_DELETE = 'etechflow_bisn/subscription/massDelete';
    private const URL_PATH_CANCEL = 'etechflow_bisn/subscription/massCancel';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$row) {
            if (!isset($row['subscription_id'])) {
                continue;
            }
            $id = (int) $row['subscription_id'];
            $row[$name]['delete'] = [
                'href'    => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['id' => $id]),
                'label'   => __('Delete'),
                'confirm' => [
                    'title'   => __('Delete subscription'),
                    'message' => __('Delete this subscription? This removes the audit row.'),
                ],
            ];
            $row[$name]['cancel'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_CANCEL, ['id' => $id]),
                'label' => __('Cancel'),
            ];
        }
        return $dataSource;
    }
}