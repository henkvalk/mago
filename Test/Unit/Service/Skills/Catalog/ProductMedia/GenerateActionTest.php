<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductMedia;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\AbstractGenerateAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\GenerateImageAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\GenerateVideoAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\HiggsfieldMedia;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\ProductImageSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class GenerateActionTest extends TestCase
{
    private const PRODUCT = [
        'product_id' => 5,
        'sku' => 'MUG-1',
        'product_name' => 'Mug',
        'file' => 'catalog/product/m/u/mug.jpg',
        'content_type' => 'image/jpeg',
        'public_url' => 'https://shop.test/media/catalog/product/m/u/mug.jpg',
    ];

    /**
     * @var HiggsfieldMedia&Stub
     */
    private HiggsfieldMedia $client;

    /**
     * @var ProductImageSource&Stub
     */
    private ProductImageSource $source;

    protected function setUp(): void
    {
        $this->client = $this->createStub(HiggsfieldMedia::class);
        $this->client->method('isConnected')->willReturn(true);
        $this->source = $this->createStub(ProductImageSource::class);
        $this->source->method('find')->willReturn(self::PRODUCT);
        $this->source->method('read')->willReturn('bytes');
    }

    #[Test]
    public function itRefusesBeforeConfirmationWhenNotConnected(): void
    {
        $client = $this->createStub(HiggsfieldMedia::class);
        $client->method('isConnected')->willReturn(false);
        $action = new GenerateImageAction($client, $this->source);

        self::assertSame(
            ['error' => AbstractGenerateAction::NOT_CONNECTED],
            $action->findRefusal(['sku' => 'MUG-1', 'prompt' => 'kitchen'])
        );
    }

    #[Test]
    public function itRefusesAProductWithoutImage(): void
    {
        $source = $this->createStub(ProductImageSource::class);
        $source->method('find')->willReturn(['error' => 'Product "MUG-1" has no main image to generate from.']);
        $action = new GenerateImageAction($this->client, $source);

        self::assertSame(
            ['error' => 'Product "MUG-1" has no main image to generate from.'],
            $action->findRefusal(['sku' => 'MUG-1', 'prompt' => 'kitchen'])
        );
    }

    #[Test]
    public function itUploadsTheMainImageAndQueuesNanoBananaPro(): void
    {
        $this->client = $this->configuredMock();
        $this->client->expects(self::once())
            ->method('uploadImage')
            ->with('bytes', 'image/jpeg', 'mug.jpg')
            ->willReturn(['media_id' => 'media-1']);
        $this->client->expects(self::once())
            ->method('submit')
            ->with('generate_image', [
                'model' => 'nano_banana_2',
                'prompt' => 'on a kitchen table',
                'aspect_ratio' => '1:1',
                'resolution' => '4k',
                'medias' => [['value' => 'media-1', 'role' => 'image_references']],
            ])
            ->willReturn(['job_id' => 'job-1', 'status' => 'queued']);

        $result = (new GenerateImageAction($this->client, $this->source))->execute(
            ['sku' => 'MUG-1', 'prompt' => ' on a kitchen table ', 'aspect_ratio' => '7:5', 'resolution' => '4k'],
            1
        );

        self::assertSame('job-1', $result['request_id']);
        self::assertSame('image', $result['kind']);
        self::assertSame('MUG-1', $result['sku']);
    }

    #[Test]
    public function itStartsSeedanceFromTheMainImageWithClampedDuration(): void
    {
        $this->client = $this->configuredMock();
        $this->client->method('uploadImage')->willReturn(['media_id' => 'media-2']);
        $this->client->expects(self::once())
            ->method('submit')
            ->with('generate_video', [
                'model' => 'seedance_2_0',
                'prompt' => 'slow turn',
                'duration' => 15,
                'aspect_ratio' => '16:9',
                'resolution' => '720p',
                'mode' => 'std',
                'generate_audio' => false,
                'medias' => [['value' => 'media-2', 'role' => 'start_image']],
            ])
            ->willReturn(['job_id' => 'job-2', 'status' => 'queued']);

        $result = (new GenerateVideoAction($this->client, $this->source))->execute(
            ['sku' => 'MUG-1', 'prompt' => 'slow turn', 'duration' => 60],
            1
        );

        self::assertSame('video', $result['kind']);
        self::assertSame('seedance_2_0', $result['model']);
    }

    #[Test]
    public function itMapsSoundToTheKlingParameter(): void
    {
        $this->client = $this->configuredMock();
        $this->client->method('uploadImage')->willReturn(['media_id' => 'media-3']);
        $this->client->expects(self::once())
            ->method('submit')
            ->with('generate_video', [
                'model' => 'kling3_0',
                'prompt' => 'orbit',
                'duration' => 3,
                'aspect_ratio' => '9:16',
                'mode' => 'std',
                'sound' => 'on',
                'medias' => [['value' => 'media-3', 'role' => 'start_image']],
            ])
            ->willReturn(['job_id' => 'job-3', 'status' => 'queued']);

        (new GenerateVideoAction($this->client, $this->source))->execute(
            ['sku' => 'MUG-1', 'prompt' => 'orbit', 'model' => 'kling3_0', 'duration' => 3,
                'aspect_ratio' => '9:16', 'sound' => true],
            1
        );
    }

    #[Test]
    public function itRefusesAnAspectRatioKlingDoesNotSupport(): void
    {
        $refusal = (new GenerateVideoAction($this->client, $this->source))->findRefusal(
            ['sku' => 'MUG-1', 'prompt' => 'orbit', 'model' => 'kling3_0', 'aspect_ratio' => '4:3']
        );

        self::assertSame(['error' => 'kling3_0 supports the aspect ratios 16:9, 9:16, 1:1'], $refusal);
    }

    #[Test]
    public function itShowsThePreflightedCostInTheImpacts(): void
    {
        $this->client->method('cost')->willReturn(22.5);

        $impacts = (new GenerateVideoAction($this->client, $this->source))->getImpacts(
            ['sku' => 'MUG-1', 'prompt' => 'kitchen'],
            1
        );

        self::assertStringContainsString('MUG-1 (Mug) with seedance_2_0', $impacts[0]);
        self::assertStringContainsString('22.5 credits', $impacts[0]);
    }

    private function configuredMock(): HiggsfieldMedia&MockObject
    {
        $client = $this->createMock(HiggsfieldMedia::class);
        $client->method('isConnected')->willReturn(true);

        return $client;
    }
}
