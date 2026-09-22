<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the admin URL of an entity's "new" form, the page the assistant sends the browser to when
 * it is asked to create something rather than change something that already exists. Most new
 * forms take nothing but an optional store scope; the product form is the exception, since
 * Magento's own "Add Product" button always names an attribute set and a product type, and a
 * product form opened without either has no attribute set to draw its fields from.
 */
class NewEntityUrlBuilder
{
    private const ENTITY_TYPE_PRODUCT = 'product';
    private const ENTITY_TYPE_CATEGORY = 'category';
    private const PARAM_STORE = 'store';
    private const PARAM_ATTRIBUTE_SET = 'set';
    private const PARAM_PRODUCT_TYPE = 'type';
    private const PARAM_PARENT = 'parent';

    public function __construct(
        private readonly EntityRouteMap $entityRouteMap,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function build(string $entityType, string $storeId): ?string
    {
        $route = $this->entityRouteMap->getNewRoute($entityType);
        if ($route === null) {
            return null;
        }

        return $this->secureAdminUrl->getUrl($route, $this->storeParams($storeId) + $this->entityParams($entityType));
    }

    /**
     * @return array<string,string>
     */
    private function storeParams(string $storeId): array
    {
        return $storeId !== '' ? [self::PARAM_STORE => $storeId] : [];
    }

    /**
     * @return array<string,string>
     */
    private function entityParams(string $entityType): array
    {
        return match ($entityType) {
            self::ENTITY_TYPE_PRODUCT => [
                self::PARAM_ATTRIBUTE_SET => (string)$this->eavConfig->getEntityType(Product::ENTITY)->getDefaultAttributeSetId(),
                self::PARAM_PRODUCT_TYPE => ProductType::TYPE_SIMPLE,
            ],
            self::ENTITY_TYPE_CATEGORY => [
                self::PARAM_PARENT => (string)$this->storeManager->getDefaultStoreView()?->getRootCategoryId(),
            ],
            default => [],
        };
    }
}
