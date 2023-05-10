<?php
namespace OH\Bestsellers\Model\ResourceModel;

use Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection;

class BestsellersCollection extends Collection
{
    public function loadSelect()
    {
        $this->_beforeLoad();
        $this->_renderFilters()->_renderOrders()->_renderLimit();
        return $this;
    }
}
