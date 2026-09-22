<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Debug\ProductDebug;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use MagoAssistant\Mago\Api\Skill\ActionInterface;

class MediaGalleryAction implements ActionInterface
{
    private const IMAGE_ROLE_LABELS = [
        'image' => 'Base image',
        'small_image' => 'Small image',
        'thumbnail' => 'Thumbnail',
    ];

    private const UNSET_IMAGE_ROLE_VALUES = ['', 'no_selection'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {
    }

    public function getName(): string
    {
        return 'media_gallery';
    }

    public function getDescription(): string
    {
        return 'Inspect a product\'s media gallery — image count, hidden/disabled images, '
            . 'and per-store-view overrides';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => [
                'type' => 'string',
                'description' => 'Product SKU to inspect',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return <<<INSTRUCTIONS
Present results as a compact table — one row per scope (Global + each store view):

| Scope | Images | Issues |
|-------|--------|--------|
| Global | 2 (1 hidden) | ⚠️ 1 image disabled |
| Default Store View | 2 (2 hidden) ⚠️ | Base image not set (overridden) |

Rules:
- Use ✅/❌/⚠️ icons instead of prose.
- Only list issues that are actually present. Skip empty rows.
- After the table, one line per issue: "**[Scope]** — [fix]". Be brief.
- Do not repeat information already shown in the table.
- Do not add recommendations unless the user asks.
INSTRUCTIONS;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $sku = trim($params['sku'] ?? '');
        if ($sku === '') {
            return ['error' => 'SKU is required'];
        }

        try {
            $globalProduct = $this->productRepository->get($sku, false, Store::DEFAULT_STORE_ID, true);
        } catch (NoSuchEntityException) {
            return ['error' => 'Product not found: ' . $sku];
        }

        $globalEntries = $this->mapEntries($globalProduct);
        $globalImageRoles = $this->imageRoles($globalProduct);

        $result = [
            'sku' => $sku,
            'product_id' => (int)$globalProduct->getId(),
            'global_scope' => [
                'image_count' => count($globalEntries),
                'entries' => $globalEntries,
                'image_roles' => $globalImageRoles,
                'issues' => $this->buildIssues($globalEntries, $globalImageRoles),
            ],
            'store_views' => [],
        ];

        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            $storeId = (int)$store->getId();
            if ($storeId === Store::DEFAULT_STORE_ID) {
                continue;
            }

            try {
                $storeProduct = $this->productRepository->get($sku, false, $storeId, true);
            } catch (NoSuchEntityException) {
                $result['store_views'][] = [
                    'store_id' => $storeId,
                    'store_code' => $store->getCode(),
                    'store_name' => $store->getName(),
                    'issues' => ['Product not found for this store view'],
                ];
                continue;
            }

            $storeEntries = $this->mapEntries($storeProduct, $globalEntries);
            $storeImageRoles = $this->imageRoles($storeProduct, $globalImageRoles);

            $result['store_views'][] = [
                'store_id' => $storeId,
                'store_code' => $store->getCode(),
                'store_name' => $store->getName(),
                'image_count' => count($storeEntries),
                'entries' => $storeEntries,
                'image_roles' => $storeImageRoles,
                'issues' => $this->buildIssues($storeEntries, $storeImageRoles),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{value_id: int, file: ?string, label: ?string, position: ?int,
     *     disabled: bool, disabled_overridden: bool, roles: string[]}>
     */
    private function mapEntries(ProductInterface $product, array $globalEntries = []): array
    {
        $globalByValueId = array_column($globalEntries, null, 'value_id');

        return array_map(
            function (ProductAttributeMediaGalleryEntryInterface $entry) use ($globalByValueId): array {
                $valueId = (int)$entry->getId();
                $globalEntry = $globalByValueId[$valueId] ?? null;

                $disabled = (bool)$entry->isDisabled();

                return [
                    'value_id' => $valueId,
                    'file' => $entry->getFile(),
                    'label' => $entry->getLabel(),
                    'position' => $entry->getPosition(),
                    'disabled' => $disabled,
                    'disabled_overridden' => $globalEntry !== null && $globalEntry['disabled'] !== $disabled,
                    'roles' => $entry->getTypes() ?? [],
                ];
            },
            $product->getMediaGalleryEntries() ?? []
        );
    }

    /**
     * @return array<string, array{value: ?string, overridden: bool}>
     */
    private function imageRoles(ProductInterface $product, array $globalImageRoles = []): array
    {
        $roles = [];
        foreach (self::IMAGE_ROLE_LABELS as $attributeCode => $label) {
            $rawValue = $product->getData($attributeCode);
            $value = in_array($rawValue, self::UNSET_IMAGE_ROLE_VALUES, true) ? null : $rawValue;

            $roles[$attributeCode] = [
                'value' => $value,
                'overridden' => isset($globalImageRoles[$attributeCode])
                    && $globalImageRoles[$attributeCode]['value'] !== $value,
            ];
        }

        return $roles;
    }

    private function buildIssues(array $entries, array $imageRoles): array
    {
        $issues = [];

        if ($entries === []) {
            $issues[] = 'Media gallery has no images';
        } elseif (count(array_filter($entries, fn (array $entry): bool => !$entry['disabled'])) === 0) {
            $issues[] = 'All gallery images are disabled';
        }

        foreach ($entries as $entry) {
            if (!$entry['disabled']) {
                continue;
            }
            $label = $entry['disabled_overridden'] ? ' (overridden on this store view)' : '';
            $issues[] = 'Image "' . ($entry['file'] ?? $entry['value_id']) . '" is disabled' . $label;
        }

        foreach (self::IMAGE_ROLE_LABELS as $attributeCode => $label) {
            $role = $imageRoles[$attributeCode];
            if ($role['value'] !== null) {
                continue;
            }
            $overrideLabel = $role['overridden'] ? ' (overridden on this store view)' : '';
            $issues[] = $label . ' is not set' . $overrideLabel;
        }

        return $issues;
    }
}
