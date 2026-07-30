<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Seo\UrlRewriteManager;

use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollectionFactory;
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class CreateAction implements ActionInterface
{
    public function __construct(
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly UrlRewriteResource $urlRewriteResource,
        private readonly UrlRewriteCollectionFactory $collectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create';
    }

    public function getDescription(): string
    {
        return 'Create a custom URL rewrite/redirect';
    }

    public function getParameterSchema(): array
    {
        return [
            'request_path' => [
                'type' => 'string',
                'description' => 'The source URL path without leading slash (e.g. "old-page.html")',
            ],
            'target_path' => [
                'type' => 'string',
                'description' => 'The destination URL path (e.g. "new-page.html" or "catalog/category/view/id/42")',
            ],
            'redirect_type' => [
                'type' => 'integer',
                'description' => 'Redirect type: 301 (permanent) or 302 (temporary). Default: 301',
            ],
            'store_id' => [
                'type' => 'integer',
                'description' => 'Store view ID (default: 0 for all stores)',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Optional description for this rewrite',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $requestPath = $params['request_path'] ?? '';
        $targetPath = $params['target_path'] ?? '';

        if (!$requestPath) {
            return ['error' => 'request_path is required'];
        }
        if (!$targetPath) {
            return ['error' => 'target_path is required'];
        }

        $requestPath = ltrim($requestPath, '/');
        $redirectType = (int)($params['redirect_type'] ?? 301);
        $storeId = (int)($params['store_id'] ?? 0);
        $description = $params['description'] ?? '';

        if (!in_array($redirectType, [301, 302])) {
            return ['error' => 'redirect_type must be 301 (permanent) or 302 (temporary)'];
        }

        $existing = $this->collectionFactory->create()
            ->addFieldToFilter('request_path', ['eq' => $requestPath])
            ->addFieldToFilter('store_id', ['eq' => $storeId]);

        if ($existing->getSize() > 0) {
            $existingRewrite = $existing->getFirstItem();
            return [
                'error' => 'A URL rewrite already exists for "' . $requestPath . '" in store ' . $storeId,
                'existing_rewrite' => [
                    'url_rewrite_id' => (int)$existingRewrite->getData('url_rewrite_id'),
                    'target_path' => $existingRewrite->getData('target_path'),
                    'redirect_type' => (int)$existingRewrite->getData('redirect_type'),
                    'entity_type' => $existingRewrite->getData('entity_type'),
                ],
            ];
        }

        try {
            $urlRewrite = $this->urlRewriteFactory->create();
            $urlRewrite->setData([
                'entity_type' => 'custom',
                'entity_id' => 0,
                'request_path' => $requestPath,
                'target_path' => $targetPath,
                'redirect_type' => $redirectType,
                'store_id' => $storeId,
                'description' => $description,
            ]);

            $this->urlRewriteResource->save($urlRewrite);

            $rewriteId = (int)$urlRewrite->getId();

            return [
                'success' => true,
                'message' => 'URL rewrite created: "' . $requestPath . '" → "' . $targetPath . '" (' . $redirectType . ')',
                'rewrite' => [
                    'url_rewrite_id' => $rewriteId,
                    'request_path' => $requestPath,
                    'target_path' => $targetPath,
                    'redirect_type' => $redirectType,
                    'entity_type' => 'custom',
                    'store_id' => $storeId,
                    'description' => $description,
                    'admin_url' => $this->secureAdminUrl->getUrl('adminhtml/url_rewrite/edit', ['id' => $rewriteId]),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to create URL rewrite: ' . $e->getMessage()];
        }
    }
}
