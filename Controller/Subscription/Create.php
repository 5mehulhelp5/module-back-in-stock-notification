<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Subscription;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\Notification\ConfirmSender;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use ETechFlow\BackInStockNotification\Model\SubscriptionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * POST handler for the PDP subscribe form.
 *
 * Dual-mode: when the request includes `X-Requested-With: XMLHttpRequest`
 * (or `Accept: application/json`), returns a JSON envelope that the
 * inline-AJAX progressive-enhancement script renders inline. Otherwise
 * falls back to the classic flash-message + redirect-to-referrer flow,
 * so JS-disabled visitors still get a working subscribe.
 *
 * Same persistence + dedupe + license + double-opt-in logic in both
 * paths — only the response shape differs.
 *
 * Never crashes — every failure mode returns a clean error response.
 */
class Create implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly Config $config,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly SubscriptionFactory $subscriptionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerSession $customerSession,
        private readonly MessageManager $messageManager,
        private readonly UrlInterface $urlBuilder,
        private readonly ConfirmSender $confirmSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $referrer = (string) $this->request->getServer('HTTP_REFERER', '') ?: $this->urlBuilder->getUrl('');

        if (!$this->config->isEnabled()) {
            return $this->error(__('Back-in-stock notifications are not available right now.'), $referrer);
        }

        if (!$this->formKeyValidator->validate($this->request)) {
            return $this->error(__('Your session expired. Please try again.'), $referrer);
        }

        $span = Profiler::start('ETechFlow_BISN_SubscriptionCreate');
        try {
            $productId = (int) $this->request->getParam('product_id');
            $storeId   = (int) $this->request->getParam('store_id');
            $email     = strtolower(trim((string) $this->request->getParam('email')));
            $firstName = trim((string) $this->request->getParam('first_name'));

            if ($productId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->error(__('Please provide a valid email address.'), $referrer);
            }

            try {
                $product = $this->productRepository->getById($productId);
            } catch (NoSuchEntityException $e) {
                return $this->error(__('We could not find the product you tried to subscribe to.'), $referrer);
            }

            $subscription = $this->subscriptionFactory->create();
            $subscription
                ->setEmail($email)
                ->setFirstName($firstName !== '' ? $firstName : null)
                ->setProductId($productId)
                ->setStoreId($storeId)
                ->setStoreFilterId(null)
                ->setStatus(
                    $this->config->isDoubleOptInEnabled()
                        ? SubscriptionInterface::STATUS_PENDING
                        : SubscriptionInterface::STATUS_CONFIRMED
                )
                ->setSubscribedAt(date('Y-m-d H:i:s'))
                ->setConfirmedAt($this->config->isDoubleOptInEnabled() ? null : date('Y-m-d H:i:s'));

            if ($this->customerSession->isLoggedIn()) {
                $customerId = (int) $this->customerSession->getCustomerId();
                if ($customerId > 0) {
                    $subscription->setCustomerId($customerId);
                }
            }

            try {
                $this->subscriptionRepository->save($subscription);
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'unique') !== false
                    || stripos($e->getMessage(), 'duplicate') !== false) {
                    // Already subscribed — treat as success from the customer's POV.
                    return $this->success(__("You're already on the list for this product."), $referrer);
                }
                $this->logger->warning('ETechFlow_BISN subscribe failed', ['exception' => $e->getMessage()]);
                return $this->error(__('Something went wrong — please try again later.'), $referrer);
            }

            if ($this->config->isDoubleOptInEnabled()) {
                try {
                    $this->confirmSender->send($subscription);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'ETechFlow_BISN confirm email failed (subscription kept pending)',
                        ['exception' => $e->getMessage(), 'subscription_id' => $subscription->getSubscriptionId()]
                    );
                }
                return $this->success(
                    __("Thanks — please check your inbox to confirm your subscription."),
                    $referrer
                );
            }

            return $this->success(
                __("You're on the list — we'll email you the moment %1 is back in stock.", $product->getName()),
                $referrer
            );
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Did the client ask for a JSON response (AJAX/fetch)?
     */
    private function wantsJson(): bool
    {
        if ($this->request->isXmlHttpRequest()) {
            return true;
        }
        $accept = (string) $this->request->getHeader('Accept');
        return str_contains($accept, 'application/json');
    }

    /**
     * Success response — JSON envelope for AJAX, flash-message + redirect for plain POST.
     */
    private function success($message, string $referrer): ResultInterface
    {
        if ($this->wantsJson()) {
            $json = $this->jsonFactory->create();
            $json->setData(['success' => true, 'message' => (string) $message]);
            return $json;
        }
        $this->messageManager->addSuccessMessage($message);
        return $this->redirectFactory->create()->setUrl($referrer);
    }

    /**
     * Error response — same shape pattern.
     */
    private function error($message, string $referrer): ResultInterface
    {
        if ($this->wantsJson()) {
            $json = $this->jsonFactory->create();
            $json->setHttpResponseCode(400);
            $json->setData(['success' => false, 'message' => (string) $message]);
            return $json;
        }
        $this->messageManager->addErrorMessage($message);
        return $this->redirectFactory->create()->setUrl($referrer);
    }
}
