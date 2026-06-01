<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Subscription;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

/**
 * Double-opt-in confirmation handler.
 *
 * URL shape: /etechflow_bisn/subscription/confirm?token=<signed-token>
 *
 * Only relevant when "Require Double Opt-In" is on. After the customer
 * fills the PDP form, an email goes to them with a confirm link pointing
 * here. Clicking flips status pending → confirmed; from then on, the
 * subscription will be drained by the QueueConsumer when stock returns.
 */
class Confirm implements HttpGetActionInterface
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
            $this->messageManager->addErrorMessage(__('Invalid confirmation link.'));
            return $page;
        }

        $span = Profiler::start('ETechFlow_BISN_Confirm');
        try {
            try {
                $subscription = $this->subscriptionRepository->getByToken($token);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(
                    __('We could not find that subscription — it may have been cancelled.')
                );
                return $page;
            }

            if ($subscription->getStatus() === SubscriptionInterface::STATUS_CONFIRMED) {
                $this->messageManager->addSuccessMessage(
                    __("You're already confirmed. We'll email you when this product is back in stock.")
                );
                return $page;
            }

            if ($subscription->getStatus() !== SubscriptionInterface::STATUS_PENDING) {
                $this->messageManager->addNoticeMessage(__('This subscription is no longer active.'));
                return $page;
            }

            try {
                $subscription->setStatus(SubscriptionInterface::STATUS_CONFIRMED)
                    ->setConfirmedAt(date('Y-m-d H:i:s'));
                $this->subscriptionRepository->save($subscription);
                $this->messageManager->addSuccessMessage(
                    __("Confirmed — we'll email you the moment this product is back in stock.")
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ETechFlow_BISN confirm failed',
                    ['exception' => $e->getMessage(), 'token_prefix' => substr($token, 0, 8)]
                );
                $this->messageManager->addErrorMessage(__('Sorry — we could not confirm your subscription right now. Please try again later.'));
            }

            return $page;
        } finally {
            Profiler::stop($span);
        }
    }
}