<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CancelAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CancelActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param array<string, mixed> $postResult The wrapped response InternalApiClient returns for the cancel call
     */
    private function action(array $postResult): CancelAction
    {
        $orderResolver = $this->createStub(OrderResolver::class);
        $orderResolver->method('resolve')->willReturn([
            'entity_id' => 5,
            'increment_id' => '000000549',
            'status' => 'processing',
        ]);

        $apiClient = $this->createStub(InternalApiClient::class);
        $apiClient->method('post')->willReturn($postResult);

        $adminUrl = $this->createStub(SecureAdminUrl::class);
        $adminUrl->method('getUrl')->willReturn('http://example.test/admin/order/5');

        return new CancelAction($apiClient, $adminUrl, $orderResolver);
    }

    #[Test]
    public function itReportsFailureWhenMagentoDeclinesTheCancel(): void
    {
        $result = $this->action(['result' => false])->execute(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertArrayHasKey('error', $result);
        self::assertArrayNotHasKey('success', $result);
        self::assertStringContainsString('000000549', $result['error']);
    }

    #[Test]
    public function itReportsSuccessWhenTheCancelIsAccepted(): void
    {
        $result = $this->action(['result' => true])->execute(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertStringContainsString('canceled', $result['message']);
    }
}
