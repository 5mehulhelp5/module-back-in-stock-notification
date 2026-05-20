<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Plugin\Customer;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\Performance\Profiler;
use ETechFlow\BackInStockNotification\Model\ResourceModel\Subscription\CollectionFactory;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Psr\Log\LoggerInterface;

/**
 * When a guest subscribes to back-in-stock alerts then later registers an
 * account with the same email, link those anonymous subscriptions to the
 * new customer so they show up in My Account → Back-in-Stock Subscriptions.
 *
 * Fires after Magento\Customer\Api\AccountManagementInterface::createAccount
 * returns successfully. Cheap: bails immediately if the email has no
 * matching anonymous subscription.
 *
 * Plugins on AccountManagementInterface are the standard pattern for
 * "do X when a customer is created" — works for guest checkout, frontend
 * register, admin-created customers, social-login modules. Anything that
 * goes through createAccount.
 */
class AutoLinkSubscriptionsPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterCreateAccount(
        AccountManagementInterface $subject,
        CustomerInterface $customer,
        $password = null,
        $redirectUrl = ''
    ): CustomerInterface {
        if (!$this->config->isAdminEnabled()) {
            return $customer;
        }

        $span = Profiler::start('ETechFlow_BISN_AutoLinkSubscriptions');
        try {
            $email = strtolower(trim((string) $customer->getEmail()));
            $customerId = (int) $customer->getId();
            if ($email === '' || $customerId <= 0) {
                return $customer;
            }

            // Find anonymous subscriptions for this email (customer_id NULL)
            $collection = $this->collectionFactory->create();
            $collection
                ->addFieldToFilter(SubscriptionInterface::EMAIL, $email)
                ->addFieldToFilter(SubscriptionInterface::CUSTOMER_ID, ['null' => true]);

            $linked = 0;
            foreach ($collection as $sub) {
                try {
                    $sub->setCustomerId($customerId);
                    $this->subscriptionRepository->save($sub);
                    $linked++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'ETechFlow_BISN AutoLink failed for one row',
                        ['subscription_id' => $sub->getSubscriptionId(), 'exception' => $e->getMessage()]
                    );
                }
            }

            if ($linked > 0) {
                $this->logger->info(
                    'ETechFlow_BISN linked anonymous subscriptions to new customer',
                    ['customer_id' => $customerId, 'count' => $linked]
                );
            }
        } catch (\Throwable $e) {
            // Never let our plugin break customer creation. Log + swallow.
            $this->logger->warning(
                'ETechFlow_BISN AutoLinkSubscriptions suppressed exception',
                ['exception' => $e->getMessage()]
            );
        } finally {
            Profiler::stop($span);
        }

        return $customer;
    }
}
