<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\GetPageAction;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\UpdatePageAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdatePageActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /** @var array<int, array<string, mixed>> */
    private array $putBodies = [];

    /**
     * @param array<string, mixed> $page What GetPageAction returns for the looked-up page
     */
    private function action(array $page): UpdatePageAction
    {
        $getPageAction = $this->createStub(GetPageAction::class);
        $getPageAction->method('execute')->willReturn($page);

        $apiClient = $this->createStub(InternalApiClient::class);
        $apiClient->method('put')->willReturnCallback(function (string $endpoint, array $body, int $adminUserId): array {
            $this->putBodies[] = $body;

            return ['success' => true];
        });

        return new UpdatePageAction($apiClient, $getPageAction);
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingContent(): void
    {
        $result = $this->action(['id' => 5, 'identifier' => 'about-us'])
            ->execute(['identifier' => 'about-us', 'content' => 'New body'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame('about-us', $this->putBodies[0]['page']['identifier']);
        self::assertSame('New body', $this->putBodies[0]['page']['content']);
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingTitle(): void
    {
        $this->action(['id' => 5, 'identifier' => 'about-us'])
            ->execute(['identifier' => 'about-us', 'title' => 'About Our Company'], self::ADMIN_USER_ID);

        self::assertSame('about-us', $this->putBodies[0]['page']['identifier']);
        self::assertSame('About Our Company', $this->putBodies[0]['page']['title']);
    }
}
