<?php
namespace Extend\CustomAddToCart\Observer;

/**
 * Class AddToCart
 * @package Extend\Warranty\Observer\Warranty
 */
class AddToCart implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * Cart Helper
     *
     * @var \Magento\Checkout\Helper\Cart
     */
    protected $_cartHelper;

    /**
     * Product Repository Interface
     *
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $_productRepository;

    /**
     * Search Criteria Builder
     *
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $_searchCriteriaBuilder;

    /**
     * Message Manager Interface
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $_messageManager;

    /**
     * Tracking Helper
     *
     * @var \Extend\Warranty\Helper\Tracking
     */
    protected $_trackingHelper;

    /**
     * Logger Interface
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * Offer Model
     *
     * @var \Extend\Warranty\Model\Offers
     */
    protected $offerModel;

    /**
     * @var \Magento\Quote\Api\Data\CartItemInterfaceFactory
     */
    protected $cartItemFactory;

    /**
     * AddToCart constructor
     *
     * @param \Magento\Checkout\Helper\Cart $cartHelper
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Extend\Warranty\Helper\Tracking $trackingHelper
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Extend\Warranty\Helper\Data $helper
     */
    public function __construct(
        \Magento\Checkout\Helper\Cart $cartHelper,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Extend\Warranty\Helper\Tracking $trackingHelper,
        \Psr\Log\LoggerInterface $logger,
        \Extend\Warranty\Model\Offers $offerModel,
        \Magento\Quote\Api\Data\CartItemInterfaceFactory $cartItemFactory
    ) {
        $this->_cartHelper = $cartHelper;
        $this->_productRepository = $productRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_messageManager = $messageManager;
        $this->_trackingHelper = $trackingHelper;
        $this->_logger = $logger;
        $this->offerModel = $offerModel;
        $this->cartItemFactory = $cartItemFactory;
    }

    /**
     * Add to cart warranty
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Framework\App\RequestInterface $request */
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $observer->getData('request');
        /** @var \Magento\Checkout\Model\Cart $cart */
        $cart = $this->_cartHelper->getCart();

        $quote = $this->_cartHelper->getQuote();

        if ($observer->getProduct()->getTypeId() === 'grouped') {
            $items = $request->getPost('super_group');
            foreach ($items as $id => $qty) {
                $warrantyData = $request->getPost('warranty_' . $id, []);
                $this->addWarranty($cart, $warrantyData, $qty);
            }
        } else {
            $qty = $request->getPost('qty', 1);
            $warrantyData = $request->getPost('warranty', []);

            $this->addWarranty($cart, $warrantyData, $qty);
        }
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $cart
     * @param array $warrantyData
     * @param int $qty
     * @return void
     * @throws \Exception
     */
    private function addWarranty($cart, array $warrantyData, $qty)
    {
        if (empty($warrantyData) || $qty < 1 || empty($qty)) {
            return;
        }

        $errors = $this->offerModel->validateWarranty($warrantyData);
        if (!empty($errors)) {
            $this->_messageManager->addErrorMessage(
                __('Oops! There was an error adding the protection plan product.')
            );
            $errorsAsString = implode(' ', $errors);
            $this->_logger->error(
                'Invalid warranty data. ' . $errorsAsString . ' Warranty data: ' . $this->offerModel->getWarrantyDataAsString($warrantyData)
            );

            return;
        }

        $this->_searchCriteriaBuilder
            ->setPageSize(1)->addFilter('type_id', \Extend\Warranty\Model\Product\Type::TYPE_CODE);
        /** @var \Magento\Framework\Api\SearchCriteria $searchCriteria */
        $searchCriteria = $this->_searchCriteriaBuilder->create();
        $searchResults = $this->_productRepository->getList($searchCriteria);
        /** @var \Magento\Catalog\Model\Product[] $results */
        $results = $searchResults->getItems();
        /** @var \Magento\Catalog\Model\Product $warranty */
        $warranty = reset($results);
        if (!$warranty) {
            $this->_messageManager->addErrorMessage('Oops! There was an error adding the protection plan product.');
            $this->_logger->error(
                'Oops! There was an error finding the protection plan product, please ensure the protection plan product is in your catalog and is enabled! '
                . 'Warranty data: ' . $this->offerModel->getWarrantyDataAsString($warrantyData)
            );

            return;
        }
        $warrantyData['qty'] = $qty;
        try {
            $cart->addProduct($warranty, $warrantyData);
            $cart->getQuote()->removeAllAddresses();
            /** @noinspection PhpUndefinedMethodInspection */
            $cart->getQuote()->setTotalsCollectedFlag(false);
            $cart->save();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->_logger->critical($e);
            $this->_messageManager->addErrorMessage('Oops! There was an error adding the protection plan product.');

            return;
        }
        if ($this->_trackingHelper->isTrackingEnabled()) {
            if (!isset($warrantyData['component']) || $warrantyData['component'] !== 'modal') {
                $trackingData = [
                    'eventName' => 'trackOfferAddedToCart',
                    'productId' => $warrantyData['product'] ?? '',
                    'productQuantity' => $qty,
                    'warrantyQuantity' => $qty,
                    'planId' => $warrantyData['planId'] ?? '',
                    'area' => 'product_page',
                    'component' => $warrantyData['component'] ?? 'buttons',
                ];
            } else {
                $trackingData = [
                    'eventName' => 'trackOfferUpdated',
                    'productId' => $warrantyData['product'] ?? '',
                    'productQuantity' => $qty,
                    'warrantyQuantity' => $qty,
                    'planId' => $warrantyData['planId'] ?? '',
                    'area' => 'product_page',
                    'component' => $warrantyData['component'] ?? 'buttons',
                ];
            }
            $this->_trackingHelper->setTrackingData($trackingData);
        }
    }
}
