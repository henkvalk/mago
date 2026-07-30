<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Catalog\ReviewManager;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class GetStatsAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    public function getName(): string
    {
        return 'get_stats';
    }

    public function getDescription(): string
    {
        return 'Get review statistics: count per status, average rating. Optionally filter by product SKU.';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => [
                'type' => 'string',
                'description' => 'Optional product SKU to filter stats for a specific product',
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

        $productId = null;
        $sku = $params['sku'] ?? '';

        if ($sku) {
            try {
                $product = $this->productRepository->get($sku);
                $productId = (int)$product->getId();
            } catch (NoSuchEntityException $e) {
                return ['error' => 'Product not found with SKU: ' . $sku];
            }
        }

        $stats = [
            'approved' => $this->getCountByStatus(Review::STATUS_APPROVED, $productId),
            'pending' => $this->getCountByStatus(Review::STATUS_PENDING, $productId),
            'not_approved' => $this->getCountByStatus(3, $productId),
        ];
        $stats['total'] = $stats['approved'] + $stats['pending'] + $stats['not_approved'];

        $averageRating = $this->getAverageRating($productId);
        $stats['average_rating'] = $averageRating;

        if ($sku) {
            $stats['sku'] = $sku;
        }

        return ['review_stats' => $stats];
    }

    private function getCountByStatus(int $statusId, ?int $productId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addStatusFilter($statusId);

        if ($productId) {
            $collection->addEntityFilter('product', $productId);
        }

        return $collection->getSize();
    }

    private function getAverageRating(?int $productId): ?float
    {
        $collection = $this->collectionFactory->create();
        $collection->addStatusFilter(Review::STATUS_APPROVED);

        if ($productId) {
            $collection->addEntityFilter('product', $productId);
        }

        $collection->addRateVotes();

        $totalRating = 0;
        $totalVotes = 0;

        foreach ($collection as $review) {
            $votes = $review->getRatingVotes();
            if ($votes && count($votes)) {
                foreach ($votes as $vote) {
                    $totalRating += (float)$vote->getPercent();
                    $totalVotes++;
                }
            }
        }

        if ($totalVotes === 0) {
            return null;
        }

        return round($totalRating / $totalVotes, 1);
    }
}
