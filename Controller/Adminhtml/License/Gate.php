<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Controller\Adminhtml\License;

use ETechFlow\BackInStockNotification\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Gate extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_BackInStockNotification::config';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('etechflow_bisn/subscription/index');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Back-in-Stock Notification — License Required'));
        return $page;
    }
}