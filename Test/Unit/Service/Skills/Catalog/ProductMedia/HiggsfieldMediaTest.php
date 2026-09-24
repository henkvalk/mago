<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductMedia;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Higgsfield\McpClient;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\HiggsfieldMedia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HiggsfieldMediaTest extends TestCase
{
    private const JOB = 'b2cfbf3c-c847-4a2f-b0f9-c0b5740fc4ab';

    /**
     * @var McpClient&MockObject
     */
    private McpClient $mcp;

    /**
     * @var ClientInterface&MockObject
     */
    private ClientInterface $http;

    protected function setUp(): void
    {
        $this->mcp = $this->createMock(McpClient::class);
        $this->mcp->method('isConnected')->willReturn(true);
        $this->http = $this->createMock(ClientInterface::class);
    }

    #[Test]
    public function itUploadsToThePresignedUrlWithoutTheTokenAndConfirms(): void
    {
        $this->mcp->expects(self::exactly(2))
            ->method('callTool')
            ->willReturnCallback(static fn (string $tool, array $args): array => match ($tool) {
                'media_upload' => self::toolOutput(['uploads' => [[
                    'upload_url' => 'https://bucket.s3.amazonaws.com/in.jpg?X-Amz-Signature=x',
                    'media_id' => 'media-1',
                    'content_type' => 'image/jpeg',
                ]]]),
                'media_confirm' => $args === ['type' => 'image', 'media_id' => 'media-1']
                    ? self::toolOutput(['confirmed' => true])
                    : ['error' => 'unexpected confirm'],
                default => ['error' => 'unexpected tool'],
            });
        $this->http->expects(self::once())
            ->method('request')
            ->with('PUT', 'https://bucket.s3.amazonaws.com/in.jpg?X-Amz-Signature=x', self::callback(
                static fn (array $options): bool => $options['headers'] === ['Content-Type' => 'image/jpeg']
                    && $options['body'] === 'bytes'
            ))
            ->willReturn(new Response(200));

        self::assertSame(['media_id' => 'media-1'], $this->media()->uploadImage('bytes', 'image/jpeg', 'in.jpg'));
    }

    #[Test]
    public function itSubmitsOneCreditPaidJobAndReadsItsId(): void
    {
        $this->mcp->expects(self::once())
            ->method('callTool')
            ->with('generate_image', ['params' => [
                'model' => 'nano_banana_2',
                'count' => 1,
                'use_unlim' => false,
            ]])
            ->willReturn(self::toolOutput(['results' => [[
                'id' => strtoupper(self::JOB),
                'type' => 'image',
                'status' => 'queued',
                'model' => 'nano_banana_2',
            ]]]));

        self::assertSame(
            ['job_id' => self::JOB, 'status' => 'queued'],
            $this->media()->submit('generate_image', ['model' => 'nano_banana_2'])
        );
    }

    #[Test]
    public function itReportsAToolErrorWithItsText(): void
    {
        $this->mcp->method('callTool')->willReturn([
            'structured' => null,
            'text' => 'Insufficient credits',
            'is_error' => true,
        ]);

        self::assertSame(
            ['error' => 'Higgsfield refused the request: Insufficient credits'],
            $this->media()->submit('generate_video', ['model' => 'seedance_2_0'])
        );
    }

    #[Test]
    public function itReadsTheFullSizeOutputOfACompletedJob(): void
    {
        $this->mcp->method('callTool')->willReturn(self::toolOutput(['generation' => [
            'id' => self::JOB,
            'type' => 'image',
            'status' => 'completed',
            'results' => [
                'rawUrl' => 'https://cdn.example.com/out.png',
                'minUrl' => 'https://cdn.example.com/out_min.webp',
            ],
        ]]));

        self::assertSame(
            ['status' => 'completed', 'type' => 'image', 'urls' => ['https://cdn.example.com/out.png']],
            $this->media()->status(self::JOB)
        );
    }

    #[Test]
    public function itReadsThePreflightCost(): void
    {
        $this->mcp->method('callTool')->willReturn(self::toolOutput(['cost' => ['credits' => 22.5]]));

        self::assertSame(22.5, $this->media()->cost('generate_video', ['model' => 'seedance_2_0']));
    }

    /**
     * Tool output as McpClient returns it.
     *
     * @param array<string,mixed> $structured
     * @return array<string,mixed>
     */
    private static function toolOutput(array $structured): array
    {
        return ['structured' => $structured, 'text' => '', 'is_error' => false];
    }

    private function media(): HiggsfieldMedia
    {
        return new HiggsfieldMedia($this->mcp, $this->http, $this->createStub(ErrorLogger::class));
    }
}
