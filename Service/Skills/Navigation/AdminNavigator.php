<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Navigation;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class AdminNavigator implements ToolInterface
{
    public function __construct(
        private readonly PageRegistry $pageRegistry,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly EntityRouteMap $entityRouteMap
    ) {
    }

    public function getName(): string
    {
        return 'admin_navigator';
    }

    public function getDescription(): string
    {
        return 'Find direct clickable links to Magento admin pages. Two modes: '
            . '(1) Search: pass "query" to find admin pages by keyword. '
            . '(2) Direct link: pass "entity_type" + "entity_id" to get a link to a specific record '
            . '(use after fetching entity data from sales_data, customer_data, or product_data). '
            . 'ALWAYS use this tool when the user asks where to find something in the admin. '
            . 'ALWAYS include the returned URLs as markdown links in your response, '
            . 'e.g. [Orders](https://store.com/admin/sales/order/key/...)';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to find admin pages (e.g. "orders", "store name", "cache")',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'enum' => $this->entityRouteMap->getEntityTypes(),
                    'description' => 'Entity type for direct link (use with entity_id)',
                ],
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'Entity ID (the internal Magento entity_id, not the increment_id)',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['Dashboard', 'Sales', 'Catalog', 'Customers', 'Marketing', 'Content', 'Reports', 'Stores', 'System'],
                    'description' => 'Optional: filter search results by category',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of search results (default: 5)',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        // Direct entity link mode
        if (!empty($params['entity_type']) && !empty($params['entity_id'])) {
            return $this->resolveEntityLink(
                (string)$params['entity_type'],
                (int)$params['entity_id']
            );
        }

        // Search mode
        $query = $params['query'] ?? '';
        if (trim($query) === '') {
            return ['error' => 'Provide either "query" for search or "entity_type" + "entity_id" for a direct link'];
        }

        $category = $params['category'] ?? null;
        $limit = max(1, min((int)($params['limit'] ?? 5), 10));
        $matches = $this->pageRegistry->search($query, $limit);

        if ($category !== null) {
            $matches = array_values(array_filter(
                $matches,
                fn(array $m) => mb_strtolower($m['category']) === mb_strtolower($category)
            ));
        }

        if (empty($matches)) {
            return [
                'results' => [],
                'message' => 'No admin pages found for "' . $query . '". Try different keywords.',
            ];
        }

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'label' => $match['label'],
                'category' => $match['category'],
                'url' => $this->secureAdminUrl->getUrl($match['route']),
            ];
        }

        return ['results' => $results];
    }

    private function resolveEntityLink(string $entityType, int $entityId): array
    {
        $route = $this->entityRouteMap->getRoute($entityType);
        $paramKey = $this->entityRouteMap->getParamKey($entityType);

        if (!$route || !$paramKey) {
            return ['error' => 'Unknown entity type: ' . $entityType];
        }

        $url = $this->secureAdminUrl->getUrl($route, [$paramKey => $entityId]);

        return [
            'results' => [[
                'label' => ucfirst(str_replace('_', ' ', $entityType)) . ' #' . $entityId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'url' => $url,
            ]],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '';
    }
}
