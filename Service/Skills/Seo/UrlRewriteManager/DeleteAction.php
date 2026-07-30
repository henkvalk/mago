<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Seo\UrlRewriteManager;

use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class DeleteAction implements ActionInterface
{
    public function __construct(
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly UrlRewriteResource $urlRewriteResource
    ) {
    }

    public function getName(): string
    {
        return 'delete';
    }

    public function getDescription(): string
    {
        return 'Delete a custom URL rewrite (refuses auto-generated rewrites)';
    }

    public function getParameterSchema(): array
    {
        return [
            'url_rewrite_id' => [
                'type' => 'integer',
                'description' => 'The ID of the URL rewrite to delete',
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
        $rewriteId = (int)($params['url_rewrite_id'] ?? 0);
        if (!$rewriteId) {
            return ['error' => 'url_rewrite_id is required'];
        }

        try {
            $urlRewrite = $this->urlRewriteFactory->create();
            $this->urlRewriteResource->load($urlRewrite, $rewriteId);

            if (!$urlRewrite->getId()) {
                return ['error' => 'URL rewrite with ID ' . $rewriteId . ' not found'];
            }

            $entityType = $urlRewrite->getData('entity_type');
            if ($entityType !== 'custom') {
                return [
                    'error' => 'Cannot delete auto-generated URL rewrite (type: "' . $entityType . '"). '
                        . 'Only "custom" rewrites can be deleted. Auto-generated rewrites are managed by Magento indexers.',
                ];
            }

            $requestPath = $urlRewrite->getData('request_path');
            $this->urlRewriteResource->delete($urlRewrite);

            return [
                'success' => true,
                'message' => 'URL rewrite deleted: "' . $requestPath . '" (ID: ' . $rewriteId . ')',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to delete URL rewrite: ' . $e->getMessage()];
        }
    }
}
