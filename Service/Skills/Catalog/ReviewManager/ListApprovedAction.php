<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Catalog\ReviewManager;

use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class ListApprovedAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'list_approved';
    }

    public function getDescription(): string
    {
        return 'List approved reviews';
    }

    public function getParameterSchema(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 20)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Review::reviews_all';
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
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $limit = (int)($params['limit'] ?? 20);

        $collection = $this->collectionFactory->create();
        $collection->addStatusFilter(Review::STATUS_APPROVED);
        $collection->setOrder('review_id', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $reviews = [];
        foreach ($collection as $review) {
            $reviewId = (int)$review->getId();
            $reviews[] = [
                'review_id' => $reviewId,
                'title' => $review->getTitle(),
                'nickname' => $review->getNickname(),
                'detail' => $review->getDetail(),
                'product_id' => (int)$review->getEntityPkValue(),
                'created_at' => $review->getCreatedAt(),
                'admin_url' => $this->secureAdminUrl->getUrl('review/product/edit', ['id' => $reviewId]),
            ];
        }

        return [
            'approved_reviews' => $reviews,
            'total' => $collection->getSize(),
        ];
    }
}
