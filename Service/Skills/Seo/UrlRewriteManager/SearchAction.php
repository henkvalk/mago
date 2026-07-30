<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Seo\UrlRewriteManager;

use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollectionFactory;
use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class SearchAction implements ActionInterface
{
    public function __construct(
        private readonly UrlRewriteCollectionFactory $collectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getDescription(): string
    {
        return 'Search URL rewrites by request path, target path, entity type, or store';
    }

    public function getParameterSchema(): array
    {
        return [
            'request_path' => [
                'type' => 'string',
                'description' => 'Filter by request path (partial match, e.g. "old-page")',
            ],
            'target_path' => [
                'type' => 'string',
                'description' => 'Filter by target path (partial match, e.g. "catalog/product/view")',
            ],
            'entity_type' => [
                'type' => 'string',
                'description' => 'Filter by entity type: "product", "category", "cms-page", or "custom"',
            ],
            'store_id' => [
                'type' => 'integer',
                'description' => 'Filter by store view ID',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 20)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $requestPath = $params['request_path'] ?? '';
        $targetPath = $params['target_path'] ?? '';
        $entityType = $params['entity_type'] ?? '';
        $storeId = isset($params['store_id']) ? (int)$params['store_id'] : null;

        if (!$requestPath && !$targetPath && !$entityType && $storeId === null) {
            return ['error' => 'At least one filter parameter is required (request_path, target_path, entity_type, or store_id)'];
        }

        $limit = (int)($params['limit'] ?? 20);
        $collection = $this->collectionFactory->create();

        if ($requestPath) {
            $collection->addFieldToFilter('request_path', ['like' => '%' . $requestPath . '%']);
        }
        if ($targetPath) {
            $collection->addFieldToFilter('target_path', ['like' => '%' . $targetPath . '%']);
        }
        if ($entityType) {
            $collection->addFieldToFilter('entity_type', ['eq' => $entityType]);
        }
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', ['eq' => $storeId]);
        }

        $collection->setPageSize($limit);

        $rewrites = [];
        foreach ($collection as $rewrite) {
            $rewriteId = (int)$rewrite->getData('url_rewrite_id');
            $rewrites[] = [
                'url_rewrite_id' => $rewriteId,
                'request_path' => $rewrite->getData('request_path'),
                'target_path' => $rewrite->getData('target_path'),
                'redirect_type' => (int)$rewrite->getData('redirect_type'),
                'entity_type' => $rewrite->getData('entity_type'),
                'entity_id' => (int)$rewrite->getData('entity_id'),
                'store_id' => (int)$rewrite->getData('store_id'),
                'description' => $rewrite->getData('description'),
                'admin_url' => $this->secureAdminUrl->getUrl('adminhtml/url_rewrite/edit', ['id' => $rewriteId]),
            ];
        }

        return [
            'total_found' => $collection->getSize(),
            'count' => count($rewrites),
            'rewrites' => $rewrites,
        ];
    }
}
