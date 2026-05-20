<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Block\Product;

use ETechFlow\BackInStockNotification\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PDP block — renders the "Notify me when back in stock" form when:
 *   1. BISN is enabled (license valid + admin toggle on)
 *   2. The current product is out of stock
 *
 * On in-stock products this block emits nothing — layout always loads
 * it; the template short-circuits via canDisplay().
 *
 * Pre-fills the email field for logged-in customers. Anonymous
 * subscribers can still subscribe (most pertinent feature — the merchant
 * doesn't want to lose the lead just because the visitor isn't logged
 * in).
 */
class NotifyMeForm extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Master gate: should this block render at all?
     */
    public function canDisplay(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }
        $product = $this->getProduct();
        if (!$product) {
            return false;
        }
        return !$this->isInStock($product);
    }

    public function getProduct(): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof ProductInterface ? $product : null;
    }

    public function getProductId(): int
    {
        $product = $this->getProduct();
        return $product ? (int) $product->getId() : 0;
    }

    public function getProductName(): string
    {
        $product = $this->getProduct();
        return $product ? (string) $product->getName() : '';
    }

    public function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Customer's email if logged in, empty otherwise.
     */
    public function getCustomerEmail(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }
        try {
            return (string) $this->customerSession->getCustomer()->getEmail();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getCustomerFirstName(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }
        try {
            return (string) $this->customerSession->getCustomer()->getFirstname();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getFormActionUrl(): string
    {
        return $this->getUrl('etechflow_bisn/subscription/create');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function isDoubleOptInEnabled(): bool
    {
        return $this->config->isDoubleOptInEnabled();
    }

    private function isInStock(ProductInterface $product): bool
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem(
                (int) $product->getId(),
                $this->getStoreWebsiteId()
            );
            return (float) $stockItem->getQty() > 0.0 && (bool) $stockItem->getIsInStock();
        } catch (\Exception $e) {
            // If we can't determine stock, default to "in stock" — hides the form.
            // Better to hide than to show on a product that's actually buyable.
            return true;
        }
    }

    private function getStoreWebsiteId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getWebsiteId();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
