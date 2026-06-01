<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Customer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Customer account "My Subscriptions" page.
 *
 * Lists the customer's active back-in-stock subscriptions with one-click
 * unsubscribe per row. Login-only; anonymous redirects to login.
 */
class Subscriptions implements HttpGetActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly RedirectInterface $redirect
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->customerSession->setBeforeAuthUrl($this->redirect->getRefererUrl());
            $redirect = $this->redirectFactory->create();
            return $redirect->setPath('customer/account/login');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My back-in-stock subscriptions'));
        return $page;
    }
}