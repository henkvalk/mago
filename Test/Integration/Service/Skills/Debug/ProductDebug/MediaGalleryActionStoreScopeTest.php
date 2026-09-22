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
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\TestFramework\Helper\Bootstrap;
use MagoAssistant\Mago\Service\Skills\Debug\ProductDebug\MediaGalleryAction;
use PHPUnit\Framework\TestCase;

/**
 * Kept separate from MediaGalleryActionTest: this is the only scenario needing a second
 * store view (Gallery\CreateHandler::getStoreIdForUpdate() collapses the base/small/thumbnail
 * image attributes to global scope via StoreManagerInterface::hasSingleStore() when a Magento
 * install has only one non-admin store view, so overriding one per store view is only
 * observable with a second store view present). Creating that store view needs a disabled
 * transaction wrapper below, which this suite's fixture resolver does not reliably combine
 * with other, transaction-wrapped tests in the same class/run — run this file on its own:
 *   vendor/bin/phpunit -c phpunit.xml .../MediaGalleryActionStoreScopeTest.php
 *
 * @magentoDataFixture Magento/Catalog/_files/product_simple.php
 * @magentoDataFixture Magento/Store/_files/second_store.php
 * @magentoDbIsolation disabled
 */
final class MediaGalleryActionStoreScopeTest extends TestCase
{
    private const SKU = 'simple';
    private const TEST_MEDIA_DIR = '/m/mgtest2/';

    private MediaGalleryAction $action;
    private ProductRepositoryInterface $productRepository;
    private MediaConfig $mediaConfig;
    private WriteInterface $mediaDirectory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->action = $objectManager->create(MediaGalleryAction::class);
        $this->productRepository = $objectManager->create(ProductRepositoryInterface::class);
        $this->mediaConfig = $objectManager->get(MediaConfig::class);
        $this->mediaDirectory = $objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
    }

    protected function tearDown(): void
    {
        $this->mediaDirectory->delete($this->mediaConfig->getBaseMediaPath() . self::TEST_MEDIA_DIR);
        $this->mediaDirectory->delete($this->mediaConfig->getBaseTmpMediaPath() . self::TEST_MEDIA_DIR);
    }

    public function testFlagsABaseImageUnsetOnlyAtStoreViewScope(): void
    {
        $secondStoreId = (int)Bootstrap::getObjectManager()->get(StoreRepositoryInterface::class)
            ->get('fixture_second_store')
            ->getId();

        $relativePath = self::TEST_MEDIA_DIR . 'base.jpg';
        $this->mediaDirectory->writeFile($this->mediaConfig->getBaseTmpMediaPath() . $relativePath, 'test-image-bytes');

        $product = $this->productRepository->get(self::SKU);
        $product->setStoreId(Store::DEFAULT_STORE_ID);
        $product->setData('media_gallery', ['images' => [
            ['file' => $relativePath, 'position' => 1, 'label' => 'Base', 'disabled' => 0, 'media_type' => 'image'],
        ]]);
        $product->setImage($relativePath)->setSmallImage($relativePath)->setThumbnail($relativePath);
        $product->setCanSaveCustomOptions(true);
        $product->save();
        $this->productRepository->cleanCache();

        $storeProduct = $this->productRepository->get(self::SKU, false, $secondStoreId, true);
        $storeProduct->setStoreId($secondStoreId);
        $storeProduct->setImage('no_selection');
        $this->productRepository->save($storeProduct);

        $result = $this->action->execute(['sku' => self::SKU], 1);

        self::assertSame($relativePath, $result['global_scope']['image_roles']['image']['value']);
        self::assertSame([], $result['global_scope']['issues']);

        $storeView = null;
        foreach ($result['store_views'] as $candidate) {
            if ($candidate['store_id'] === $secondStoreId) {
                $storeView = $candidate;
            }
        }
        self::assertNotNull($storeView, 'Store view ' . $secondStoreId . ' not found in result');

        self::assertNull($storeView['image_roles']['image']['value']);
        self::assertTrue($storeView['image_roles']['image']['overridden']);
        self::assertContains('Base image is not set (overridden on this store view)', $storeView['issues']);
    }
}
