<?php

namespace OH\Bestsellers\Block;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;
use Magento\Widget\Block\BlockInterface;

class Bestsellers extends Template implements BlockInterface
{
    protected $_template = 'OH_Bestsellers::bestseller.phtml';

    /**
     * Default value for products count that will be shown
     */
    const DEFAULT_PRODUCTS_COUNT = 4;

    /**
     * Default value whether show title
     */
    const DEFAULT_TITLE = 'Bestseller Products';

    /**
     * @var \Magento\Catalog\Helper\Product\Compare
     */
    protected $compareProduct;

    /**
     * @var \Magento\Catalog\Block\Product\ImageBuilder
     */
    protected $imageBuilder;

    /**
     * @var \Magento\Reports\Model\ResourceModel\Report\Collection\Factory
     */
    protected $resourceFactory;

    /**
     * @var CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var \Magento\Checkout\Helper\Cart
     */
    protected $cartHelper;

    /**
     * @var \Magento\Catalog\Model\Product\Attribute\Source\Status
     */
    protected $productStatus;

    /**
     * @var \Magento\Catalog\Block\Product\ReviewRendererInterface
     */
    protected $reviewRenderer;

    /**
     * @var \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable
     */
    protected $configurable;

    public function __construct(
        \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurable,
        \Magento\Catalog\Block\Product\ReviewRendererInterface $reviewRenderer,
        \Magento\Catalog\Model\Product\Attribute\Source\Status $productStatus,
        CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Helper\Product\Compare $compareProduct,
        \Magento\Catalog\Block\Product\ImageBuilder $imageBuilder,
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Reports\Model\ResourceModel\Report\Collection\Factory $resourceFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configurable = $configurable;
        $this->reviewRenderer = $reviewRenderer;
        $this->productStatus = $productStatus;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->imageBuilder = $imageBuilder;
        $this->compareProduct = $compareProduct;
        $this->resourceFactory = $resourceFactory;
        $this->cartHelper = $context->getCartHelper();
    }

    public function getShowTitleBestsellers()
    {
        return $this->getData('show_title');
    }

    public function getTitleBestsellers()
    {
        $title = $this->getData('title');
        if ($title === null) {
            $title = __(self::DEFAULT_TITLE);
        }

        return $title;
    }

    public function getBestsellerProduct()
    {
        return $this->resourceFactory
            ->create('Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection')
            ->addStoreFilter($this->_storeManager->getStore()->getId())
            ->setPageSize(100);
    }

    public function isVisible($product)
    {
        return in_array($product->getStatus(), $this->productStatus->getVisibleStatusIds()) && in_array($product->getVisibility(),
                [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]);
    }

    public function getProduct($id)
    {
        $parentProd = $this->configurable->getParentIdsByChild($id);

        if ($parentProd) {
            $parentProdId = reset($parentProd);
            $prodId = $parentProdId;
        } else {
            $prodId = $id;
        }

        $product = $this->productCollectionFactory
            ->create()
            ->addFieldToFilter('entity_id', $prodId)
            ->addAttributeToSelect('*')
            ->addAttributeToSelect('request_path')
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('parent_ids')
            ->addAttributeToSelect('special_price')
            ->addAttributeToSelect('visibility')
            ->getFirstItem();

        if (!empty($parentProdId)) {
            $product->setData('parent_id', $parentProd);
        }

        return $product;
    }

    public function getProductUrl($product, $additional = [])
    {
        if ($this->hasProductUrl($product)) {
            if (!isset($additional['_escape'])) {
                $additional['_escape'] = true;
            }
            return $product->getUrlModel()->getUrl($product, $additional);
        }

        return '#';
    }

    public function hasProductUrl($product)
    {
        if ($product->getVisibleInSiteVisibilities()) {
            return true;
        }
        if ($product->hasUrlDataObject()) {
            if (in_array($product->hasUrlDataObject()->getVisibility(), $product->getVisibleInSiteVisibilities())) {
                return true;
            }
        }

        return false;
    }

    public function getProductLimit()
    {
        $productsCount = $this->getData('products_count');
        if ($productsCount === null || !ctype_digit($productsCount)) {
            $productsCount = self::DEFAULT_PRODUCTS_COUNT; // default value
        }

        return $productsCount;
    }

    public function getAddToCompareUrl()
    {
        return $this->compareProduct->getAddUrl();
    }

    public function getImage($product, $imageId, $attributes = [])
    {
        return $this->imageBuilder->create($product, $imageId, $attributes);
    }

    public function getAddToCartUrl($product, $additional = [])
    {
        return $this->cartHelper->getAddUrl($product, $additional);
    }

    public function getReviewsSummaryHtml(
        \Magento\Catalog\Model\Product $product,
        $templateType = false,
        $displayIfNoReviews = false
    ) {
        return $this->reviewRenderer->getReviewsSummaryHtml($product, $templateType, $displayIfNoReviews);
    }

    public function getProductPriceHtml(
        \Magento\Catalog\Model\Product $product,
        $priceType = null,
        $renderZone = \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST,
        array $arguments = []
    ) {
        if (!isset($arguments['zone'])) {
            $arguments['zone'] = $renderZone;
        }
        $arguments['zone'] = isset($arguments['zone'])
            ? $arguments['zone']
            : $renderZone;
        $arguments['price_id'] = isset($arguments['price_id'])
            ? $arguments['price_id']
            : 'old-price-' . $product->getId() . '-' . $priceType;
        $arguments['include_container'] = isset($arguments['include_container'])
            ? $arguments['include_container']
            : true;
        $arguments['display_minimal_price'] = isset($arguments['display_minimal_price'])
            ? $arguments['display_minimal_price']
            : true;

        /** @var \Magento\Framework\Pricing\Render $priceRender */
        $priceRender = $this->getLayout()->getBlock('product.price.render.default');

        $price = '';
        if ($priceRender) {
            $price = $priceRender->render(
                \Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE,
                $product,
                $arguments
            );
        }
        return $price;
    }

    public function _toHtml()
    {
        if (!$this->_scopeConfig->isSetFlag('oh_bestsellers/settings/enabled', ScopeInterface::SCOPE_STORE)) {
            return '';
        }

        return parent::_toHtml();
    }
}