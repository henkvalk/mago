<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Debug\ProductDebug;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MagoAssistant\Mago\Service\Skills\Debug\ProductDebug\MediaGalleryAction;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDataFixture Magento/Catalog/_files/product_simple.php
 */
final class MediaGalleryActionTest extends TestCase
{
    private const SKU = 'simple';
    private const TEST_MEDIA_DIR = '/m/mgtest/';

    private MediaGalleryAction $action;
    private ProductRepositoryInterface $productRepository;
    private MediaConfig $mediaConfig;
    private WriteInterface $mediaDirectory;
    private int $storeId;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->action = $objectManager->create(MediaGalleryAction::class);
        $this->productRepository = $objectManager->create(ProductRepositoryInterface::class);
        $this->mediaConfig = $objectManager->get(MediaConfig::class);
        $this->mediaDirectory = $objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);

        $this->storeId = (int)$objectManager->get(StoreManagerInterface::class)->getStore('default')->getId();
    }

    protected function tearDown(): void
    {
        $this->mediaDirectory->delete($this->mediaConfig->getBaseMediaPath() . self::TEST_MEDIA_DIR);
        $this->mediaDirectory->delete($this->mediaConfig->getBaseTmpMediaPath() . self::TEST_MEDIA_DIR);
    }

    public function testReportsNoImagesWhenGalleryIsEmpty(): void
    {
        $result = $this->action->execute(['sku' => self::SKU], 1);

        self::assertSame(0, $result['global_scope']['image_count']);
        self::assertContains('Media gallery has no images', $result['global_scope']['issues']);
    }

    public function testFlagsAGloballyDisabledImage(): void
    {
        $this->setGlobalGallery([
            $this->newImage('disabled.jpg', disabled: true, position: 1),
        ]);

        $result = $this->action->execute(['sku' => self::SKU], 1);

        self::assertSame(1, $result['global_scope']['image_count']);
        $entry = $result['global_scope']['entries'][0];
        self::assertTrue($entry['disabled']);
        self::assertFalse($entry['disabled_overridden']);
        self::assertContains('Image "' . $entry['file'] . '" is disabled', $result['global_scope']['issues']);
    }

    public function testFlagsAStoreViewOnlyDisabledImageAsOverridden(): void
    {
        $this->setGlobalGallery([
            $this->newImage('override.jpg', disabled: false, position: 1),
        ]);

        $storeProduct = $this->productRepository->get(self::SKU, false, $this->storeId, true);
        $entries = $storeProduct->getMediaGalleryEntries();
        $entries[0]->setDisabled(true);
        $storeProduct->setMediaGalleryEntries($entries);
        $storeProduct->setStoreId($this->storeId);
        $this->productRepository->save($storeProduct);

        $result = $this->action->execute(['sku' => self::SKU], 1);

        self::assertFalse($result['global_scope']['entries'][0]['disabled']);

        $storeView = $this->findStoreView($result, $this->storeId);
        $entry = $storeView['entries'][0];
        self::assertTrue($entry['disabled']);
        self::assertTrue($entry['disabled_overridden']);
        self::assertContains(
            'Image "' . $entry['file'] . '" is disabled (overridden on this store view)',
            $storeView['issues']
        );
    }

    public function testErrorsOnUnknownSku(): void
    {
        $result = $this->action->execute(['sku' => 'does-not-exist'], 1);

        self::assertArrayHasKey('error', $result);
    }

    /**
     * Builds a "new" media gallery image entry (no value_id) whose tmp file physically
     * exists, since Gallery\CreateHandler moves every value_id-less entry from the tmp
     * media directory on save and errors if the source file is missing.
     */
    private function newImage(string $filename, bool $disabled, int $position): array
    {
        $relativePath = self::TEST_MEDIA_DIR . $filename;
        $this->mediaDirectory->writeFile($this->mediaConfig->getBaseTmpMediaPath() . $relativePath, 'test-image-bytes');

        return [
            'file' => $relativePath,
            'position' => $position,
            'label' => $filename,
            'disabled' => $disabled ? 1 : 0,
            'media_type' => 'image',
        ];
    }

    private function setGlobalGallery(array $images, ?string $baseImage = null): void
    {
        $product = $this->productRepository->get(self::SKU);
        $product->setStoreId(Store::DEFAULT_STORE_ID);
        $product->setData('media_gallery', ['images' => $images]);
        if ($baseImage !== null) {
            $product->setImage($baseImage)->setSmallImage($baseImage)->setThumbnail($baseImage);
        }
        $product->setCanSaveCustomOptions(true);
        // Model-level save (not the repository) accepts gallery entries as plain file
        // references without base64 "content" — the repository's MediaGalleryProcessor
        // requires content for brand-new entries, which this fixture data does not have.
        $product->save();
        $this->productRepository->cleanCache();
    }

    private function findStoreView(array $result, int $storeId): array
    {
        foreach ($result['store_views'] as $storeView) {
            if ($storeView['store_id'] === $storeId) {
                return $storeView;
            }
        }

        self::fail('Store view ' . $storeId . ' not found in result');
    }
}
