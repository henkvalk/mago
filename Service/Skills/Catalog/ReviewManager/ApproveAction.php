<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Catalog\ReviewManager;

use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class ApproveAction implements ActionInterface
{
    public function __construct(
        private readonly ReviewFactory $reviewFactory,
        private readonly ReviewResource $reviewResource
    ) {
    }

    public function getName(): string
    {
        return 'approve';
    }

    public function getDescription(): string
    {
        return 'Approve a pending review by review ID';
    }

    public function getParameterSchema(): array
    {
        return [
            'review_id' => [
                'type' => 'integer',
                'description' => 'The review ID to approve',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Review::reviews_all';
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
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $reviewId = (int)($params['review_id'] ?? 0);
        if (!$reviewId) {
            return ['error' => 'review_id is required'];
        }

        $review = $this->reviewFactory->create();
        $this->reviewResource->load($review, $reviewId);

        if (!$review->getId()) {
            return ['error' => 'Review not found: ' . $reviewId];
        }

        $review->setStatusId(Review::STATUS_APPROVED);
        $this->reviewResource->save($review);
        $review->aggregate();

        return [
            'success' => true,
            'message' => 'Review #' . $reviewId . ' has been approved',
        ];
    }
}
