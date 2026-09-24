<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductMedia;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\CheckStatusAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\HiggsfieldClient;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\MediaStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CheckStatusActionTest extends TestCase
{
    private const ID = 'd7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff';

    /**
     * @var HiggsfieldClient&Stub
     */
    private HiggsfieldClient $client;

    /**
     * @var MediaStorage&Stub
     */
    private MediaStorage $storage;

    protected function setUp(): void
    {
        $this->client = $this->createStub(HiggsfieldClient::class);
        $this->storage = $this->configure($this->createStub(MediaStorage::class));
    }

    /**
     * @template T of MediaStorage&Stub
     * @param T $storage
     * @return T
     */
    private function configure(MediaStorage&Stub $storage): MediaStorage&Stub
    {
        $storage->method('isValidRequestId')->willReturnCallback(
            static fn (string $id): bool => $id === self::ID
        );
        $storage->method('url')
            ->willReturnCallback(static fn (string $file): string => 'https://shop.test/media/' . $file);
        $storage->method('isImage')
            ->willReturnCallback(static fn (string $file): bool => !str_ends_with($file, '.mp4'));

        return $storage;
    }

    #[Test]
    public function itRejectsAnythingButARequestId(): void
    {
        $this->client = $this->createMock(HiggsfieldClient::class);
        $this->client->expects(self::never())->method('status');

        $result = $this->action()->execute(['request_id' => '../../etc'], 1);

        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function itReportsAPendingRequest(): void
    {
        $this->client->method('status')->willReturn(['status' => 'in_progress', 'request_id' => self::ID]);

        $result = $this->action()->execute(['request_id' => strtoupper(self::ID)], 1);

        self::assertSame('in_progress', $result['status']);
    }

    #[Test]
    public function itReportsAFailureAsFree(): void
    {
        $this->client->method('status')->willReturn(['status' => 'failed', 'error' => 'Generation failed']);

        $result = $this->action()->execute(['request_id' => self::ID], 1);

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('No credits were charged', $result['message']);
    }

    #[Test]
    public function itStoresACompletedVideo(): void
    {
        $this->client->method('status')->willReturn([
            'status' => 'completed',
            'video' => ['url' => 'https://cdn.example.com/out'],
        ]);
        $this->storage = $this->configure($this->createMock(MediaStorage::class));
        $this->storage->method('files')->willReturn([]);
        $this->storage->expects(self::once())
            ->method('store')
            ->with(self::ID, ['https://cdn.example.com/out'], 'mp4')
            ->willReturn(['mago/higgsfield/' . self::ID . '/1.mp4']);

        $result = $this->action()->execute(['request_id' => self::ID], 1);

        self::assertSame([[
            'number' => 1,
            'type' => 'video',
            'url' => 'https://shop.test/media/mago/higgsfield/' . self::ID . '/1.mp4',
        ]], $result['files']);
    }

    #[Test]
    public function itAnswersFromStoredFilesWithoutCallingHiggsfield(): void
    {
        $this->storage->method('files')->willReturn(['mago/higgsfield/' . self::ID . '/1.png']);
        $this->client = $this->createMock(HiggsfieldClient::class);
        $this->client->expects(self::never())->method('status');

        $result = $this->action()->execute(['request_id' => self::ID], 1);

        self::assertSame('completed', $result['status']);
        self::assertSame('image', $result['files'][0]['type']);
    }

    private function action(): CheckStatusAction
    {
        return new CheckStatusAction($this->client, $this->storage);
    }
}
