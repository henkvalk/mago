<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Adds a generated image to a product's media gallery.
 */
class AttachImageAction implements ActionInterface, ValidatingActionInterface
{
    public const ROLES = ['image', 'small_image', 'thumbnail'];

    /** Content types the Magento gallery accepts, with their file extension. */
    private const GALLERY_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly ProductImageSource $imageSource,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getName(): string
    {
        return 'attach_image';
    }

    public function getDescription(): string
    {
        return 'Add an image from a completed generate_image request to the product media gallery. Hidden on '
            . 'the storefront and without roles unless visible or roles are given';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => ['type' => 'string', 'description' => 'SKU of the product'],
            'request_id' => [
                'type' => 'string',
                'description' => 'request_id of the completed generate_image request',
            ],
            'image_number' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Which image of the request, default 1',
            ],
            'visible' => ['type' => 'boolean', 'description' => 'Show the image on the storefront, default false'],
            'roles' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::ROLES],
                'description' => 'Image roles to give it (image = base image), default none',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'visible' => [PiiClass::PUBLIC],
            'roles' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Confirm briefly and give the admin_url, where the admin can review the image, change its roles '
            . 'or remove it.';
    }

    public function findRefusal(array $params): ?array
    {
        $found = $this->resolve($params);

        return isset($found['error']) ? ['error' => $found['error']] : null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $found = $this->resolve($params);
        if (isset($found['error'])) {
            return ['error' => $found['error']];
        }

        /** @var array<string,mixed> $product */
        $product = $found['product'];
        $file = (string)$found['file'];
        $contents = $this->imageSource->read($file);
        if ($contents === null) {
            return ['error' => 'The generated image could not be read.'];
        }

        $contentType = (string)(getimagesizefromstring($contents)['mime'] ?? '');
        $extension = self::GALLERY_TYPES[$contentType] ?? null;
        if ($extension === null) {
            return ['error' => 'The generated image is ' . ($contentType ?: 'no image') . '; the Magento gallery '
                . 'accepts JPEG, PNG and GIF only. Download it from the link instead.'];
        }

        $roles = array_values(array_intersect(self::ROLES, (array)($params['roles'] ?? [])));
        $visible = ($params['visible'] ?? false) === true;

        $content = $this->imageContentFactory->create()
            ->setBase64EncodedData(base64_encode($contents))
            ->setType($contentType)
            ->setName($this->fileName((string)$product['sku'], $extension));

        $entry = $this->entryFactory->create()
            ->setMediaType('image')
            ->setLabel((string)$product['product_name'])
            ->setDisabled(!$visible)
            ->setTypes($roles)
            ->setContent($content);

        // Saved in the admin store so hidden and roles apply to all store views, not only the current one.
        $currentStore = $this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore(Store::DEFAULT_STORE_ID);
        try {
            $this->galleryManagement->create((string)$product['sku'], $entry);
        } catch (LocalizedException $e) {
            return ['error' => 'Magento did not add the image: ' . $e->getMessage()];
        } finally {
            $this->storeManager->setCurrentStore($currentStore);
        }

        return [
            'success' => true,
            'sku' => $product['sku'],
            'visible' => $visible,
            'roles' => $roles,
            'message' => 'Image added to the product gallery.',
            'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => $product['product_id']]),
        ];
    }

    /**
     * The product and the stored image file the parameters point to.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed> product and file, or error
     */
    private function resolve(array $params): array
    {
        $requestId = strtolower(trim((string)($params['request_id'] ?? '')));
        if (!$this->storage->isValidRequestId($requestId)) {
            return ['error' => 'request_id must be the id returned by generate_image'];
        }

        $files = $this->storage->files($requestId);
        if ($files === []) {
            return ['error' => 'No saved result for this request. Run check_status first until it is completed.'];
        }

        $number = (int)($params['image_number'] ?? 1);
        $file = $files[$number - 1] ?? null;
        if ($file === null) {
            return [
                'error' => sprintf(
                    'This request has %d file(s); image_number %d does not exist.',
                    count($files),
                    $number
                ),
            ];
        }
        if (!$this->storage->isImage($file)) {
            return ['error' => 'Only images can be added to the gallery; a video stays available through its link.'];
        }

        $product = $this->imageSource->product((string)($params['sku'] ?? ''));
        if (isset($product['error'])) {
            return ['error' => $product['error']];
        }

        return ['product' => $product, 'file' => $file];
    }

    private function fileName(string $sku, string $extension): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($sku)), '-');

        return ($slug !== '' ? $slug : 'product') . '-generated.' . $extension;
    }
}
