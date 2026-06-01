<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Adminhtml\License;

use ETechFlow\BackInStockNotification\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Creates a Stripe Checkout session using the keys entered in
 * Stores → Config → eTechFlow → BISN → Payment Settings,
 * then redirects the browser to Stripe for card payment.
 */
class Checkout extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_BackInStockNotification::config';

    private const XML_STRIPE_SECRET  = 'etechflow_bisn/payment/stripe_secret_key';
    private const XML_STRIPE_PUBKEY  = 'etechflow_bisn/payment/stripe_publishable_key';
    private const XML_STRIPE_CURR    = 'etechflow_bisn/payment/stripe_currency';

    /** Plan slugs → [name, price in USD cents, price display] */
    private const PLAN_INFO = [
        'bisn_starter'      => ['name' => 'Back-in-Stock Starter',      'amount' => 1900, 'display' => '$19'],
        'bisn_professional' => ['name' => 'Back-in-Stock Professional',  'amount' => 4900, 'display' => '$49'],
        'bisn_enterprise'   => ['name' => 'Back-in-Stock Enterprise',    'amount' => 9900, 'display' => '$99'],
    ];

    public function __construct(
        Context $context,
        private readonly Curl $curl,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $plan   = trim((string) $this->getRequest()->getPost('plan', ''));
        $name   = trim((string) $this->getRequest()->getPost('name', ''));
        $email  = trim((string) $this->getRequest()->getPost('email', ''));
        $domain = $this->licenseValidator->getCurrentHost();

        // Validate inputs
        if (!$plan || !isset(self::PLAN_INFO[$plan])) {
            $this->messageManager->addErrorMessage(__('Invalid plan selected.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_bisn/license/gate');
        }
        if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Please enter a valid name and email address.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_bisn/license/gate');
        }

        // Read Stripe keys from Magento admin config
        $stripeKeyRaw = trim((string) $this->scopeConfig->getValue(self::XML_STRIPE_SECRET));
        $stripeKey  = $stripeKeyRaw !== '' ? trim((string) $this->encryptor->decrypt($stripeKeyRaw)) : '';
        $currency   = strtolower(trim((string) $this->scopeConfig->getValue(self::XML_STRIPE_CURR))) ?: 'usd';

        if (!$stripeKey || str_starts_with($stripeKey, '****')) {
            $this->messageManager->addErrorMessage(
                __('Stripe Secret Key is not configured. Please go to Stores → Config → eTechFlow → Back-in-Stock → Payment Settings and enter your Stripe keys.')
            );
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_bisn/license/gate');
        }

        $planInfo   = self::PLAN_INFO[$plan];
        $successUrl = $this->getUrl('etechflow_bisn/license/activated') . '?session_id={CHECKOUT_SESSION_ID}&plan=' . urlencode($plan) . '&domain=' . urlencode($domain) . '&name=' . urlencode($name) . '&email=' . urlencode($email);
        $cancelUrl  = $this->getUrl('etechflow_bisn/license/gate');

        // Call Stripe API directly — no SDK dependency, plain cURL
        $postData = http_build_query([
            'payment_method_types[0]'                     => 'card',
            'line_items[0][price_data][currency]'          => $currency,
            'line_items[0][price_data][product_data][name]'=> $planInfo['name'] . ' — ETechFlow',
            'line_items[0][price_data][product_data][description]' => 'Back-in-Stock Notification for ' . $domain,
            'line_items[0][price_data][unit_amount]'       => $planInfo['amount'],
            'line_items[0][quantity]'                      => 1,
            'mode'                                         => 'payment',
            'customer_email'                               => $email,
            'metadata[plan]'                               => $plan,
            'metadata[domain]'                             => $domain,
            'metadata[name]'                               => $name,
            'metadata[email]'                              => $email,
            'success_url'                                  => $successUrl,
            'cancel_url'                                   => $cancelUrl,
        ]);

        try {
            $this->curl->setTimeout(15);
            $this->curl->addHeader('Authorization', 'Bearer ' . $stripeKey);
            $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->curl->post('https://api.stripe.com/v1/checkout/sessions', $postData);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not connect to Stripe. Please try again.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_bisn/license/gate');
        }

        $data = json_decode($body, true);

        if ($status !== 200 || empty($data['url'])) {
            $err = $data['error']['message'] ?? ('Stripe returned status ' . $status);
            $this->messageManager->addErrorMessage(__('Stripe error: %1', $err));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_bisn/license/gate');
        }

        // Redirect browser to Stripe Checkout
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setUrl($data['url']);
    }
}