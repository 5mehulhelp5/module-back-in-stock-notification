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
 * Validates form-key, validates email + product, deduplicates against
 * existing subscriptions, persists. On double-opt-in mode it stays in
 * status=pending and (Phase 2) sends a confirmation email; otherwise
 * marks confirmed immediately.
 *
 * Never crashes — every failure mode redirects back to the referrer with
 * a clear error message.
 */
class Create implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
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
        $redirect = $this->redirectFactory->create();
        $referrer = (string) $this->request->getServer('HTTP_REFERER', '') ?: $this->urlBuilder->getUrl('');

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Back-in-stock notifications are not available right now.'));
            return $this->redirectTo($redirect, $referrer);
        }

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Your session expired. Please try again.'));
            return $this->redirectTo($redirect, $referrer);
        }

        $span = Profiler::start('ETechFlow_BISN_SubscriptionCreate');
        try {
            $productId = (int) $this->request->getParam('product_id');
            $storeId   = (int) $this->request->getParam('store_id');
            $email     = strtolower(trim((string) $this->request->getParam('email')));
            $firstName = trim((string) $this->request->getParam('first_name'));

            if ($productId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->messageManager->addErrorMessage(__('Please provide a valid email address.'));
                return $this->redirectTo($redirect, $referrer);
            }

            // Confirm product exists (anti-tamper — form fields are POST-supplied)
            try {
                $product = $this->productRepository->getById($productId);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('We could not find the product you tried to subscribe to.'));
                return $this->redirectTo($redirect, $referrer);
            }

            // Dedupe check using the unique key (email + product + store + store_filter).
            // Implementation note: rely on the DB unique constraint to catch true
            // dupes (race-safe), then handle the exception with a friendlier message.
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
                ->setConfirmedAt(
                    $this->config->isDoubleOptInEnabled()
                        ? null
                        : date('Y-m-d H:i:s')
                );

            // Link to customer if they're signed in (lets them manage in account)
            if ($this->customerSession->isLoggedIn()) {
                $customerId = (int) $this->customerSession->getCustomerId();
                if ($customerId > 0) {
                    $subscription->setCustomerId($customerId);
                }
            }

            try {
                $this->subscriptionRepository->save($subscription);
            } catch (\Exception $e) {
                // Heuristic: DB unique constraint violation → "already subscribed".
                // Any other failure → log and show generic error.
                if (stripos($e->getMessage(), 'unique') !== false
                    || stripos($e->getMessage(), 'duplicate') !== false) {
                    $this->messageManager->addSuccessMessage(__("You're already on the list for this product."));
                    return $this->redirectTo($redirect, $referrer);
                }
                $this->logger->warning('ETechFlow_BISN subscribe failed', ['exception' => $e->getMessage()]);
                $this->messageManager->addErrorMessage(__('Something went wrong — please try again later.'));
                return $this->redirectTo($redirect, $referrer);
            }

            if ($this->config->isDoubleOptInEnabled()) {
                // Best-effort confirm-email send. If SMTP fails right now we
                // still keep the pending subscription so the customer can
                // re-click the form later; logging the failure is enough.
                try {
                    $this->confirmSender->send($subscription);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'ETechFlow_BISN confirm email failed (subscription kept pending)',
                        ['exception' => $e->getMessage(), 'subscription_id' => $subscription->getSubscriptionId()]
                    );
                }
                $this->messageManager->addSuccessMessage(
                    __("Thanks — please check your inbox to confirm your subscription.")
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __("You're on the list — we'll email you the moment %1 is back in stock.", $product->getName())
                );
            }
            return $this->redirectTo($redirect, $referrer);
        } finally {
            Profiler::stop($span);
        }
    }

    private function redirectTo(Redirect $redirect, string $url): Redirect
    {
        $redirect->setUrl($url);
        return $redirect;
    }
}
