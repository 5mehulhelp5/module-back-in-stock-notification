<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Subscription;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

/**
 * One-click unsubscribe — RFC 8058.
 *
 * URL shape: /etechflow_bisn/subscription/unsubscribe?token=<signed-token>
 *
 * The token is a 48-byte random value generated when the subscription
 * was created; it's URL-safe-base64-encoded into the column. Look up
 * the subscription by token, mark as cancelled, render a confirmation
 * page.
 *
 * No login required — that's the entire point of one-click. The token
 * itself is the credential.
 */
class Unsubscribe implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly MessageManager $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $token = trim((string) $this->request->getParam('token', ''));

        if ($token === '' || strlen($token) < 32) {
            $this->messageManager->addErrorMessage(__('Invalid unsubscribe link.'));
            return $page;
        }

        $span = Profiler::start('ETechFlow_BISN_Unsubscribe');
        try {
            try {
                $subscription = $this->subscriptionRepository->getByToken($token);
            } catch (NoSuchEntityException $e) {
                // Token didn't match anything — could be expired, deleted, or fake.
                // Be vague so we don't help token-fishers.
                $this->messageManager->addNoticeMessage(
                    __("You're already unsubscribed, or this link has expired.")
                );
                return $page;
            }

            // Already cancelled / already notified / already expired — be friendly.
            $status = $subscription->getStatus();
            if (in_array($status, [
                SubscriptionInterface::STATUS_CANCELLED,
                SubscriptionInterface::STATUS_EXPIRED,
            ], true)) {
                $this->messageManager->addSuccessMessage(
                    __("You're unsubscribed. No further emails will be sent.")
                );
                return $page;
            }

            try {
                $subscription->setStatus(SubscriptionInterface::STATUS_CANCELLED);
                $this->subscriptionRepository->save($subscription);
                $this->messageManager->addSuccessMessage(
                    __("Done — you've been unsubscribed.")
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ETechFlow_BISN unsubscribe failed',
                    ['exception' => $e->getMessage(), 'token_prefix' => substr($token, 0, 8)]
                );
                $this->messageManager->addErrorMessage(__('Sorry — something went wrong unsubscribing you. Please contact support.'));
            }

            return $page;
        } finally {
            Profiler::stop($span);
        }
    }
}