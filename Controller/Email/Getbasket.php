<?php

namespace Dotdigitalgroup\Email\Controller\Email;

class Getbasket extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    public $quoteFactory;
    /**
     * @var \Magento\Customer\Model\SessionFactory
     */
    public $sessionFactory;
    /**
     * @var \Magento\Checkout\Model\SessionFactory
     */
    public $checkoutSession;
    /**
     * @var
     */
    public $quote;

    /**
     * Getbasket constructor.
     *
     * @param \Magento\Checkout\Model\SessionFactory $checkoutSessionFactory
     * @param \Magento\Quote\Model\QuoteFactory      $quoteFactory
     * @param \Magento\Customer\Model\SessionFactory $sessionFactory
     * @param \Magento\Framework\App\Action\Context  $context
     */
    public function __construct(
        \Magento\Checkout\Model\SessionFactory $checkoutSessionFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Customer\Model\SessionFactory $sessionFactory,
        \Magento\Framework\App\Action\Context $context
    ) {
        $this->checkoutSession = $checkoutSessionFactory;
        $this->sessionFactory  = $sessionFactory;
        $this->quoteFactory    = $quoteFactory;
        parent::__construct($context);
    }

    /**
     * Wishlist page to display the user items with specific email.
     */
    public function execute()
    {
        $quoteId = $this->getRequest()->getParam('quote_id');
        //no quote id redirect to base url
        if (!$quoteId) {
            return $this->_redirect('');
        }

        /** @var \Magento\Quote\Model\Quote $quoteModel */
        $quoteModel = $this->quoteFactory->create();

        $quoteModel->getResource()->load($quoteModel, $quoteId);

        //no quote id redirect to base url
        if (! $quoteModel->getId()) {
            return $this->_redirect('');
        }

        //set quoteModel to _quote property for later use
        $this->quote = $quoteModel;

        if ($quoteModel->getCustomerId()) {
            $this->_handleCustomerBasket();
        } else {
            $this->_handleGuestBasket();
        }
    }

    /**
     * Process customer basket.
     */
    public function _handleCustomerBasket()
    {
        $customerSession = $this->sessionFactory->create();
        $configCartUrl = $this->quote->getStore()->getWebsite()->getConfig(
            \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_CONTENT_CART_URL
        );

        //if customer is logged in then redirect to cart
        if ($customerSession->isLoggedIn()) {
            $checkoutSession = $this->checkoutSession->create();
            if ($checkoutSession->getQuote()
                && $checkoutSession->getQuote()->hasItems()
            ) {
                $quote = $checkoutSession->getQuote();
                if ($this->quote->getId() != $quote->getId()) {
                    $this->_checkMissingAndAdd();
                }
            } else {
                $this->_loadAndReplace();
            }

            if ($configCartUrl) {
                $url = $configCartUrl;
            } else {
                $url = $customerSession->getCustomer()->getStore()->getUrl(
                    'checkout/cart'
                );
            }

            $this->_redirect($url);
        } else {
            //set after auth url. customer will be redirected to cart after successful login
            if ($configCartUrl) {
                $cartUrl = $configCartUrl;
            } else {
                $cartUrl = 'checkout/cart';
            }
            $customerSession->setAfterAuthUrl(
                $this->quote->getStore()->getUrl($cartUrl)
            );

            //send customer to login page
            $configLoginUrl = $this->quote->getStore()->getWebsite()
                ->getConfig(
                    \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_CONTENT_LOGIN_URL
                );
            if ($configLoginUrl) {
                $loginUrl = $configLoginUrl;
            } else {
                $loginUrl = 'customer/account/login';
            }
            $this->_redirect($this->quote->getStore()->getUrl($loginUrl));
        }
    }

    /**
     * Check missing items from current quote and add.
     */
    public function _checkMissingAndAdd()
    {
        $checkoutSession = $this->checkoutSession->create();
        $currentQuote = $checkoutSession->getQuote();

        if ($currentQuote->hasItems()) {
            $currentSessionItems = $currentQuote->getAllItems();
            $currentItemIds = [];

            foreach ($currentSessionItems as $currentSessionItem) {
                $currentItemIds[] = $currentSessionItem->getId();
            }
            foreach ($this->quote->getAllItems() as $item) {
                if (!in_array($item->getId(), $currentItemIds)) {
                    $currentQuote->addItem($item);
                }
            }
            $currentQuote->collectTotals()->save();
        } else {
            $this->_loadAndReplace();
        }
    }

    /**
     * Load quote and replace in session.
     */
    public function _loadAndReplace()
    {
        $checkoutSession = $this->checkoutSession->create();
        $quote = $this->quoteFactory->create()
            ->load($this->quote->getId());
        $quote->setIsActive(true)->save();
        $checkoutSession->replaceQuote($quote);
    }

    /**
     * Process guest basket.
     */
    public function _handleGuestBasket()
    {
        $checkoutSession = $this->checkoutSession->create();

        if ($checkoutSession->getQuote()
            && $checkoutSession->getQuote()->hasItems()
        ) {
            $this->_checkMissingAndAdd();
        } else {
            $this->_loadAndReplace();
        }

        $configCartUrl = $this->quote->getStore()->getWebsite()->getConfig(
            \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_CONTENT_CART_URL
        );

        if ($configCartUrl) {
            $url = $configCartUrl;
        } else {
            $url = 'checkout/cart';
        }
        $this->_redirect($this->quote->getStore()->getUrl($url));
    }
}
