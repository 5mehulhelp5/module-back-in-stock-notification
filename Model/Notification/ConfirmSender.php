<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Notification;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface as InlineTranslation;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Sends the double-opt-in confirmation email.
 *
 * Only invoked when the admin "Require Double Opt-In" toggle is on.
 * Email contains a confirm-link (etechflow_bisn/subscription/confirm
 * ?token=...) and an unsubscribe-link.
 */
class ConfirmSender
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly InlineTranslation $inlineTranslation,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @throws \Magento\Framework\Exception\MailException
     */
    public function send(SubscriptionInterface $subscription): void
    {
        try {
            $product = $this->productRepository->getById((int) $subscription->getProductId());
        } catch (NoSuchEntityException $e) {
            return;
        }

        $storeId = (int) $subscription->getStoreId();
        $store = $this->storeManager->getStore($storeId ?: null);
        $token = $subscription->getUnsubscribeToken();

        $confirmUrl = $this->urlBuilder->getUrl(
            'etechflow_bisn/subscription/confirm',
            ['token' => $token, '_secure' => true, '_scope' => $storeId]
        );
        $unsubscribeUrl = $this->urlBuilder->getUrl(
            'etechflow_bisn/subscription/unsubscribe',
            ['token' => $token, '_secure' => true, '_scope' => $storeId]
        );

        $vars = [
            'product_name'    => (string) $product->getName(),
            'confirm_url'     => $confirmUrl,
            'unsubscribe_url' => $unsubscribeUrl,
            'first_name'      => $subscription->getFirstName() ?? '',
            'store'           => $store,
        ];

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('etechflow_bisn_confirm')
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($vars)
                ->setFromByScope($this->config->getEmailSender(), $storeId)
                ->addTo($subscription->getEmail(), $subscription->getFirstName() ?? '')
                ->getTransport();
            $transport->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}