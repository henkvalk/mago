<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductMedia;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\AbstractGenerateAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\GenerateImageAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\GenerateVideoAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\HiggsfieldClient;
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
     * @var HiggsfieldClient&Stub
     */
    private HiggsfieldClient $client;

    /**
     * @var ProductImageSource&Stub
     */
    private ProductImageSource $source;

    protected function setUp(): void
    {
        $this->client = $this->createStub(HiggsfieldClient::class);
        $this->client->method('isConfigured')->willReturn(true);
        $this->source = $this->createStub(ProductImageSource::class);
        $this->source->method('find')->willReturn(self::PRODUCT);
        $this->source->method('read')->willReturn('bytes');
    }

    #[Test]
    public function itRefusesBeforeConfirmationWhenNotConfigured(): void
    {
        $client = $this->createStub(HiggsfieldClient::class);
        $client->method('isConfigured')->willReturn(false);
        $action = new GenerateImageAction($client, $this->source);

        self::assertSame(
            ['error' => AbstractGenerateAction::NOT_CONFIGURED],
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
    public function itUploadsTheMainImageAndQueuesTheImage(): void
    {
        $this->client = $this->configuredMock();
        $this->client->expects(self::once())
            ->method('uploadImage')
            ->with('bytes', 'image/jpeg')
            ->willReturn(['public_url' => 'https://cdn.example.com/in.jpeg']);
        $this->client->expects(self::once())
            ->method('submit')
            ->with(GenerateImageAction::ENDPOINT, [
                'prompt' => 'on a kitchen table',
                'image_urls' => ['https://cdn.example.com/in.jpeg'],
                'aspect_ratio' => '1:1',
                'resolution' => '4k',
                'quality' => 'high',
                'enhance_prompt' => false,
            ])
            ->willReturn(['status' => 'queued', 'request_id' => 'req-1']);

        $result = (new GenerateImageAction($this->client, $this->source))->execute(
            ['sku' => 'MUG-1', 'prompt' => ' on a kitchen table ', 'aspect_ratio' => '5:4', 'resolution' => '4k'],
            1
        );

        self::assertSame('req-1', $result['request_id']);
        self::assertSame('image', $result['kind']);
        self::assertSame('MUG-1', $result['sku']);
    }

    #[Test]
    public function itClampsTheVideoDurationAndTurnsSoundOffByDefault(): void
    {
        $this->client = $this->configuredMock();
        $this->client->method('uploadImage')->willReturn(['public_url' => 'https://cdn.example.com/in.jpeg']);
        $this->client->expects(self::once())
            ->method('submit')
            ->with(GenerateVideoAction::ENDPOINT, [
                'image_url' => 'https://cdn.example.com/in.jpeg',
                'prompt' => 'slow turn',
                'duration' => 15,
                'sound' => 'off',
            ])
            ->willReturn(['status' => 'queued', 'request_id' => 'req-2']);

        $result = (new GenerateVideoAction($this->client, $this->source))->execute(
            ['sku' => 'MUG-1', 'prompt' => 'slow turn', 'duration' => 60],
            1
        );

        self::assertSame('video', $result['kind']);
    }

    #[Test]
    public function itShowsTheEstimatedCostInTheImpacts(): void
    {
        $this->client->method('estimate')->willReturn(['credits' => '1.500', 'usd' => '0.094']);

        $impacts = (new GenerateImageAction($this->client, $this->source))->getImpacts(
            ['sku' => 'MUG-1', 'prompt' => 'kitchen'],
            1
        );

        self::assertStringContainsString('MUG-1 (Mug)', $impacts[0]);
        self::assertStringContainsString('1.500 credits (about $0.094)', $impacts[0]);
    }

    private function configuredMock(): HiggsfieldClient&MockObject
    {
        $client = $this->createMock(HiggsfieldClient::class);
        $client->method('isConfigured')->willReturn(true);

        return $client;
    }
}
