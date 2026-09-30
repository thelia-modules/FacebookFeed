<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacebookFeed\API\Resource;

use FacebookFeed\Model\FacebookFeedProductExcluded as FacebookFeedProductExcludedModel;
use FacebookFeed\Model\FacebookFeedProductExcludedQuery;
use FacebookFeed\Model\Map\FacebookFeedProductExcludedTableMap;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\Serializer\Attribute\Groups;
use Thelia\Api\Resource\ProductSaleElements as ProductSaleElementsResource;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\ResourceAddonInterface;
use Thelia\Api\Resource\ResourceAddonTrait;
use Thelia\Model\ProductSaleElements;

class FacebookFeedProductExcluded implements ResourceAddonInterface
{
    use ResourceAddonTrait;

    public ProductSaleElementsResource $productSaleElements;

    #[Groups([ProductSaleElementsResource::GROUP_ADMIN_READ, ProductSaleElementsResource::GROUP_ADMIN_WRITE])]
    public bool $isExcluded = false;

    /**
     * @throws PropelException
     */
    public function buildFromModel(ActiveRecordInterface|ProductSaleElements $activeRecord, PropelResourceInterface $abstractPropelResource): ResourceAddonInterface
    {
        if (null === $facebookFeedProductExcluded = FacebookFeedProductExcludedQuery::create()->filterByProductSaleElements($activeRecord)->findOne()) {
            return $this;
        }

        $this->setIsExcluded(
            $activeRecord->hasVirtualColumn('FacebookFeedProductExcluded_is_excluded')
                ? (bool) $activeRecord->getVirtualColumn('FacebookFeedProductExcluded_is_excluded')
                : (bool) $facebookFeedProductExcluded->getIsExcluded()
        );

        return $this;
    }

    public function buildFromArray(array $data, PropelResourceInterface $abstractPropelResource): ResourceAddonInterface
    {
        if (isset($data['isExcluded'])) {
            $this->setIsExcluded((bool) $data['isExcluded']);
        }

        return $this;
    }

    /**
     * @throws PropelException
     */
    public function doSave(ActiveRecordInterface|ProductSaleElements $activeRecord, PropelResourceInterface $abstractPropelResource): void
    {
        $model = FacebookFeedProductExcludedQuery::create()->filterByPseId($activeRecord->getId())->findOne();
        if (null === $model) {
            $model = new FacebookFeedProductExcludedModel();
            $model->setProductSaleElements($activeRecord);
        }

        $model->setIsExcluded($this->isExcluded() ? 1 : 0);
        $model->save();
    }

    public function isExcluded(): bool
    {
        return $this->isExcluded;
    }

    public function setIsExcluded(bool $isExcluded): FacebookFeedProductExcluded
    {
        $this->isExcluded = $isExcluded;

        return $this;
    }

    public function getProductSaleElements(): ProductSaleElementsResource
    {
        return $this->productSaleElements;
    }

    public function setProductSaleElements(ProductSaleElementsResource $productSaleElements): FacebookFeedProductExcluded
    {
        $this->productSaleElements = $productSaleElements;

        return $this;
    }

    public static function getResourceParent(): string
    {
        return ProductSaleElementsResource::class;
    }

    public static function getPropelRelatedTableMap(): ?TableMap
    {
        return new FacebookFeedProductExcludedTableMap();
    }
}
