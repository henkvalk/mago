<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

/**
 * The one place per-entity admin routing knowledge lives. `AdminNavigator` and the page_form
 * skill's navigate-then-act path (task 009) both resolve an entity's edit URL through this map
 * rather than each keeping their own copy of it. It only ever maps an entity type to its admin
 * route and the URL parameter key that route expects an id under: resolving a SKU or other
 * identifier to an id stays the model's own job through product_data / cms_data.
 */
class EntityRouteMap
{
    private const ROUTES = [
        'order' => 'sales/order/view',
        'invoice' => 'sales/invoice/view',
        'shipment' => 'sales/shipment/view',
        'creditmemo' => 'sales/creditmemo/view',
        'customer' => 'customer/index/edit',
        'product' => 'catalog/product/edit',
        'cms_page' => 'cms/page/edit',
        'cms_block' => 'cms/block/edit',
        'category' => 'catalog/category/edit',
    ];

    private const NEW_ROUTES = [
        'product' => 'catalog/product/new',
        'cms_page' => 'cms/page/new',
        'cms_block' => 'cms/block/new',
        'category' => 'catalog/category/add',
    ];

    private const PARAM_KEYS = [
        'order' => 'order_id',
        'invoice' => 'invoice_id',
        'shipment' => 'shipment_id',
        'creditmemo' => 'creditmemo_id',
        'customer' => 'id',
        'product' => 'id',
        'cms_page' => 'page_id',
        'cms_block' => 'block_id',
        'category' => 'id',
    ];

    public function getRoute(string $entityType): ?string
    {
        return self::ROUTES[$entityType] ?? null;
    }

    public function getNewRoute(string $entityType): ?string
    {
        return self::NEW_ROUTES[$entityType] ?? null;
    }

    public function getParamKey(string $entityType): ?string
    {
        return self::PARAM_KEYS[$entityType] ?? null;
    }

    /**
     * @return array<int,string>
     */
    public function getEntityTypes(): array
    {
        return array_keys(self::ROUTES);
    }
}
