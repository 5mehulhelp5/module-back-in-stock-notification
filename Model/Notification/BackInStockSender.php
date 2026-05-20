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
 * Composes + sends the "back in stock" email.
 *
 * Pure dispatch — the queue consumer drives WHEN to send. This class only
 * handles HOW to build + send a single message.
 *
 * Failure modes propagate as exceptions so the consumer can decide
 * whether to retry / mark failed.
 */
class BackInStockSender
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
            // Product was deleted while subscription was queued. Drop silently —
            // can't notify about a product that doesn't exist.
            return;
        }

        $storeId = (int) $subscription->getStoreId();
        $store = $this->storeManager->getStore($storeId ?: null);

        $unsubscribeUrl = $this->urlBuilder->getUrl(
            'etechflow_bisn/subscription/unsubscribe',
            ['token' => $subscription->getUnsubscribeToken(), '_secure' => true, '_scope' => $storeId]
        );

        $templateVars = [
            'product_name'    => (string) $product->getName(),
            'product_url'     => (string) $product->getProductUrl(),
            'first_name'      => $subscription->getFirstName() ?? '',
            'unsubscribe_url' => $unsubscribeUrl,
            'store'           => $store,
        ];

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getEmailTemplate())
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope($this->config->getEmailSender(), $storeId)
                ->addTo($subscription->getEmail(), $subscription->getFirstName() ?? '')
                ->getTransport();
            $transport->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
