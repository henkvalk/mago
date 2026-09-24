<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Seo;

use MagoAssistant\Mago\Service\Skills\Seo\UrlRewriteManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeIrreversibleAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlRewriteManagerTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param array<string, \MagoAssistant\Mago\Api\Skill\ActionInterface> $actions
     */
    private function manager(array $actions = []): UrlRewriteManager
    {
        return new UrlRewriteManager(new FakeAuthorization(), $actions);
    }

    #[Test]
    public function aRedirectToAnExternalHostIsIrreversibleAndNamesTheHost(): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'checkout', 'target_path' => 'https://pay.example.net/x'];

        self::assertTrue($manager->isIrreversibleAction($input));

        $impacts = implode("\n", $manager->getImpacts($input, self::ADMIN_USER_ID));
        self::assertStringContainsString('pay.example.net', $impacts);
        self::assertStringContainsString('/checkout', $impacts);
    }

    #[Test]
    public function aProtocolRelativeTargetIsExternalToo(): void
    {
        self::assertTrue(
            $this->manager()->isIrreversibleAction(['action' => 'create', 'target_path' => '//evil.example/x'])
        );
    }

    #[Test]
    public function anInternalRedirectIsNotFlagged(): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'old.html', 'target_path' => 'catalog/category/view/id/42'];

        self::assertFalse($manager->isIrreversibleAction($input));
        self::assertSame([], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }

    #[Test]
    public function itStillDefersToAnIrreversibleDeleteAction(): void
    {
        $manager = $this->manager(['delete' => new FakeIrreversibleAction('delete', ['The rewrite is gone.'])]);
        $input = ['action' => 'delete', 'url_rewrite_id' => 5];

        self::assertTrue($manager->isIrreversibleAction($input));
        self::assertSame(['The rewrite is gone.'], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }
}
