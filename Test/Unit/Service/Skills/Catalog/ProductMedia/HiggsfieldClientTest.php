<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductMedia;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia\HiggsfieldClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class HiggsfieldClientTest extends TestCase
{
    /**
     * @var ClientInterface&Stub
     */
    private ClientInterface $http;

    protected function setUp(): void
    {
        $this->http = $this->createStub(ClientInterface::class);
    }

    #[Test]
    public function itMakesNoCallWithoutCredentials(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->http->expects(self::never())->method('request');

        $result = $this->client('', '')->submit('marketing-studio/image', ['prompt' => 'x']);

        self::assertSame(['error' => 'Higgsfield is not configured.'], $result);
    }

    #[Test]
    public function itSendsTheKeyInTheAuthorizationHeader(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->http->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.higgsfield.ai/marketing-studio/image', self::callback(
                static fn (array $options): bool => $options['headers']['Authorization'] === 'Key kid:ksecret'
                    && $options['body'] === '{"prompt":"x"}'
                    && $options['timeout'] > 0
            ))
            ->willReturn(new Response(200, [], '{"status":"queued","request_id":"abc"}'));

        $result = $this->client()->submit('marketing-studio/image', ['prompt' => 'x']);

        self::assertSame(['status' => 'queued', 'request_id' => 'abc'], $result);
    }

    #[Test]
    public function itTranslatesAnErrorStatusWithoutLeakingTheKey(): void
    {
        $this->http->method('request')->willReturn(new Response(403, [], '{"detail":"Not enough credits"}'));

        $result = $this->client()->submit('marketing-studio/image', ['prompt' => 'x']);

        self::assertSame('The Higgsfield account has not enough credits for this request.', $result['error']);
        self::assertStringNotContainsString('ksecret', (string)json_encode($result));
    }

    #[Test]
    public function itUploadsToThePresignedUrlWithoutCredentials(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->http->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url, array $options): Response {
                if ($method === 'POST') {
                    return new Response(200, [], json_encode([
                        'public_url' => 'https://cdn.example.com/input.jpeg',
                        'upload_url' => 'https://storage.example.com/put',
                        'upload_headers' => ['Content-Type' => 'image/jpeg'],
                    ]));
                }
                self::assertSame('PUT', $method);
                self::assertSame('https://storage.example.com/put', $url);
                self::assertArrayNotHasKey('Authorization', $options['headers']);
                self::assertSame('bytes', $options['body']);

                return new Response(200);
            });

        $result = $this->client()->uploadImage('bytes', 'image/jpeg');

        self::assertSame(['public_url' => 'https://cdn.example.com/input.jpeg'], $result);
    }

    #[Test]
    public function itRefusesToDownloadOverPlainHttp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->http->expects(self::never())->method('request');

        self::assertFalse($this->client()->download('http://example.com/a.png', '/tmp/a.png'));
    }

    #[Test]
    public function itReturnsNoEstimateWhenTheCallFails(): void
    {
        $this->http->method('request')->willReturn(new Response(422, [], '{"detail":"bad"}'));

        self::assertNull($this->client()->estimate('marketing-studio/image', ['prompt' => 'x']));
    }

    private function client(string $keyId = 'kid', string $secret = 'encrypted'): HiggsfieldClient
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            [HiggsfieldClient::XML_PATH_KEY_ID, 'default', null, $keyId],
            [HiggsfieldClient::XML_PATH_KEY_SECRET, 'default', null, $secret],
        ]);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('ksecret');

        return new HiggsfieldClient(
            $this->http,
            $config,
            $encryptor,
            new Json(),
            $this->createStub(ErrorLogger::class)
        );
    }
}
