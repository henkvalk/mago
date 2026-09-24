<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Finds a product by SKU together with its main image, the input for every generation.
 */
class ProductImageSource
{
    public const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Product id, SKU and name, or error.
     *
     * @param string $sku
     * @return array<string,mixed> product_id, sku and product_name, or error
     */
    public function product(string $sku): array
    {
        $product = $this->load($sku);

        return $product instanceof ProductInterface ? $this->describe($product) : $product;
    }

    /**
     * Product data and main image, or error.
     *
     * @param string $sku
     * @return array<string,mixed> product_id, sku, product_name, file, content_type and public_url, or error
     */
    public function find(string $sku): array
    {
        $product = $this->load($sku);
        if (!$product instanceof ProductInterface) {
            return $product;
        }

        $file = (string)$product->getData('image');
        if ($file === '' || $file === 'no_selection') {
            return ['error' => 'Product "' . $product->getSku() . '" has no main image to generate from.'];
        }

        $extension = $this->extensionOf($file);
        if (!isset(self::CONTENT_TYPES[$extension])) {
            return ['error' => 'The main image of "' . $product->getSku() . '" is not a JPEG, PNG or WebP file.'];
        }

        $file = 'catalog/product/' . ltrim($file, '/');

        return $this->describe($product) + [
            'file' => $file,
            'content_type' => self::CONTENT_TYPES[$extension],
            'public_url' => $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $file,
        ];
    }

    /**
     * Contents of a media file, or null when it cannot be read.
     *
     * @param string $file Path relative to pub/media
     * @return string|null
     */
    public function read(string $file): ?string
    {
        try {
            return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->readFile($file);
        } catch (FileSystemException) {
            return null;
        }
    }

    /**
     * Product in the admin store view, or error.
     *
     * @param string $sku
     * @return ProductInterface|array{error:string}
     */
    private function load(string $sku): ProductInterface|array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return ['error' => 'sku is required'];
        }

        try {
            return $this->productRepository->get($sku, false, 0);
        } catch (NoSuchEntityException) {
            return ['error' => 'No product with SKU "' . $sku . '".'];
        }
    }

    /**
     * Id, SKU and name of a product.
     *
     * @param ProductInterface $product
     * @return array{product_id:int,sku:string,product_name:string}
     */
    private function describe(ProductInterface $product): array
    {
        return [
            'product_id' => (int)$product->getId(),
            'sku' => (string)$product->getSku(),
            'product_name' => (string)$product->getName(),
        ];
    }

    /**
     * Lower-case extension of a file name or URL path, without the dot.
     *
     * @param string $path
     * @return string
     */
    private function extensionOf(string $path): string
    {
        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');

        return $dot === false || ($slash !== false && $dot < $slash) ? '' : strtolower(substr($path, $dot + 1));
    }
}
