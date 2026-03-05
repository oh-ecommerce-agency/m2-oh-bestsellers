<?php

namespace OH\Bestsellers\Block;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Serialize\SerializerInterface;
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

    private const CACHE_BESTSELLERS = 'oh_bestsellers_collection';
    private const CACHE_PRODUCT_PREFIX = 'oh_bestsellers_product_';
    private const CACHE_TTL = 3600;

    public function __construct(
        protected readonly SerializerInterface $serializer,
        protected readonly \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurable,
        protected readonly \Magento\Catalog\Block\Product\ReviewRendererInterface $reviewRenderer,
        protected readonly \Magento\Catalog\Model\Product\Attribute\Source\Status $productStatus,
        protected readonly CollectionFactory $productCollectionFactory,
        protected readonly \Magento\Catalog\Helper\Product\Compare $compareProduct,
        protected readonly \Magento\Catalog\Block\Product\ImageBuilder $imageBuilder,
        protected readonly \Magento\Reports\Model\ResourceModel\Report\Collection\Factory $resourceFactory,
        protected readonly \Magento\Checkout\Helper\Cart $cartHelper,
        protected readonly \Magento\Framework\View\Element\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
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
        $collection = $this->resourceFactory
            ->create('Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection')
            ->addStoreFilter($this->_storeManager->getStore()->getId())
            ->setPageSize(100);

        $cached = $this->_cache->load(self::CACHE_BESTSELLERS);

        if ($cached) {
            return $this->hydrateCollectionFromCache($collection, $this->serializer->unserialize($cached));
        }

        $this->cacheCollection($collection);

        return $collection;
    }

    private function hydrateCollectionFromCache($collection, array $itemsData)
    {
        $collection->clear();
        foreach ($itemsData as $itemData) {
            $collection->addItem(
                $collection->getNewEmptyItem()->setData($itemData)
            );
        }
        return $collection;
    }

    private function cacheCollection($collection): void
    {
        $items = array_map(fn($item) => $item->getData(), $collection->getItems());
        $this->_cache->save($this->serializer->serialize($items), self::CACHE_BESTSELLERS, [], self::CACHE_TTL);
    }

    public function isVisible($product)
    {
        return in_array($product->getStatus(), $this->productStatus->getVisibleStatusIds()) && in_array($product->getVisibility(),
                [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]);
    }

    public function getProduct($id)
    {
        $cacheKey = self::CACHE_PRODUCT_PREFIX . $id;
        $cached = $this->_cache->load($cacheKey);

        if ($cached) {
            $product = $this->productCollectionFactory->create()->getNewEmptyItem();
            $product->setData($this->serializer->unserialize($cached));
            return $product;
        }

        $parentProd = $this->configurable->getParentIdsByChild($id);
        $prodId = $parentProd ? reset($parentProd) : $id;

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

        if ($parentProd) {
            $product->setData('parent_id', $parentProd);
        }

        $this->_cache->save($this->serializer->serialize($product->getData()), $cacheKey, [], self::CACHE_TTL);

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