<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PrivacyServiceTest extends TestCase
{
    private function service(ConversationVault $vault): PrivacyService
    {
        return new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic());
    }

    #[Test]
    public function itScrubsPiiTypedIntoTheUserMessageBeforeItReachesTheLlm(): void
    {
        $messages = $this->service(new ConversationVault())->scrubMessages([
            ['role' => 'system', 'content' => 'You are an assistant.'],
            ['role' => 'user', 'content' => 'Email jan@example.com about order 000000549'],
        ]);

        self::assertSame('Email mago://email_1 about order 000000549', $messages[1]['content']);
        self::assertStringNotContainsString('jan@example.com', (string)json_encode($messages));
    }

    #[Test]
    public function theSameTypedValueKeepsItsTokenAcrossMessages(): void
    {
        $service = $this->service(new ConversationVault());

        $first = $service->scrubMessages([['role' => 'user', 'content' => 'mail jan@example.com']]);
        $second = $service->scrubMessages([['role' => 'user', 'content' => 'again jan@example.com']]);

        self::assertSame('mail mago://email_1', $first[0]['content']);
        self::assertSame('again mago://email_1', $second[0]['content']);
    }

    #[Test]
    public function displayShowsTheRealValueForAResolvableTokenAndANeutralLabelOtherwise(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubText('mail jan@example.com');

        $display = $service->displayText('Sent to mago://email_1, earlier case was mago://customer_9.');

        self::assertSame('Sent to jan@example.com, earlier case was [earlier record].', $display);
    }

    #[Test]
    public function onlyAdminUrlTokensAreRefusedForWrites(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsSensitiveToken(['nested' => ['link' => 'See mago://url_2']]));
        self::assertFalse($service->containsSensitiveToken(['content' => 'Mail mago://email_1 now']));
        self::assertFalse($service->containsSensitiveToken(['comment' => 'About mago://order_1 and mago://customer_3']));
    }

    #[Test]
    public function personalTokensAreFlaggedForTheConfirmationWarning(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsPersonalToken(['content' => 'Mail mago://email_1 now']));
        self::assertTrue($service->containsPersonalToken(['nested' => ['phone' => 'mago://phone_3']]));
        self::assertTrue($service->containsPersonalToken(['legacy' => 'Mail [email_1] now']));
        self::assertFalse($service->containsPersonalToken(['link' => 'See mago://url_2']));
        self::assertFalse($service->containsPersonalToken(['comment' => 'About mago://order_1']));
    }

    #[Test]
    public function itMasksValuesIrreversiblyForVaultlessSinks(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertSame(
            'Bel [phone] over [email]',
            $service->maskText('Bel 06 12345678 over jan@example.com')
        );
    }

    #[Test]
    public function aTitleNeverEndsInHalfAToken(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertSame('Stuur een mail naar ', $service->safeTitle('Stuur een mail naar [ema', 24));
        self::assertSame('Order mago://order_1', $service->safeTitle('Order mago://order_1', 50));
    }

    #[Test]
    public function itScrubsASingleTextForStorage(): void
    {
        $service = $this->service(new ConversationVault());

        $stored = $service->scrubText('Bel 06 12345678 over jan@example.com');

        self::assertSame('Bel mago://phone_1 over mago://email_1', $stored);
        self::assertSame($stored, $service->scrubText($stored));
    }

    #[Test]
    public function itRehydratesTokensInToolCallArgumentsBeforeExecution(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubMessages([['role' => 'user', 'content' => 'find jan@example.com']]);

        $input = $service->rehydrateArguments(['action' => 'lookup_customer', 'search' => 'mago://email_1']);

        self::assertSame('jan@example.com', $input['search']);
        self::assertSame('lookup_customer', $input['action']);
    }

    #[Test]
    public function itRehydratesATokenThatSplitsAcrossStreamedChunks(): void
    {
        $service = $this->service(new ConversationVault());
        $service->scrubMessages([['role' => 'user', 'content' => 'mail jan@example.com']]);

        [$emit1, $carry1] = $service->rehydrateStreamDelta('', 'Mailing mago://email');
        [$emit2, $carry2] = $service->rehydrateStreamDelta($carry1, '_1 now');

        self::assertSame('Mailing ', $emit1);
        self::assertSame('jan@example.com now', $emit2);
        self::assertSame('', $carry2);
    }

    #[Test]
    public function aTokenSplitRightAfterItsFirstLetterIsStillRehydrated(): void
    {
        $service = $this->service(new ConversationVault());
        $service->scrubMessages([['role' => 'user', 'content' => 'mail jan@example.com']]);

        [$emit1, $carry1] = $service->rehydrateStreamDelta('', 'Mail m');
        [$emit2, $carry2] = $service->rehydrateStreamDelta($carry1, 'ago://email_1 now');

        self::assertSame('Mail ', $emit1);
        self::assertSame('jan@example.com now', $emit2);
        self::assertSame('', $carry2);
    }

    #[Test]
    public function aUrlTokenSplitAcrossChunksIsHeldBackUntilItIsWhole(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $url = 'https://shop.test/admin/sales/order/view/order_id/660/key/abc/';
        $token = $vault->tokenise($url, 'url');

        $emitted = '';
        $carry = '';
        // Chunk boundaries the provider chose, cutting the token in three.
        foreach (['Bekijk de details [hier](ma', 'go://url', '_1).'] as $delta) {
            [$text, $carry] = $service->rehydrateStreamDelta($carry, $delta);
            $emitted .= $text;
        }
        $emitted .= $service->displayText($carry);

        self::assertSame('Bekijk de details [hier](' . $url . ').', $emitted);
        self::assertStringNotContainsString($token, $emitted);
    }

    #[Test]
    public function itDetectsATokenInWriteArgumentsSoAWriteCanBeRefused(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsToken(['comment' => 'Call mago://customer_1 back']));
        self::assertTrue($service->containsToken(['nested' => ['ref' => 'mago://order_2']]));
        self::assertFalse($service->containsToken(['status' => 'processing', 'qty' => 3]));
    }

    #[Test]
    public function itRehydratesTokensForTheAdmin(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubMessages([['role' => 'user', 'content' => 'call 0612345678']]);

        self::assertSame('I will call 0612345678', $service->rehydrate('I will call mago://phone_1'));
    }
}
