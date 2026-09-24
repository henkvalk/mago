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
use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\LookupCustomerAction;
use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\RecentSignupsAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A customer's name, address and phone number are masked rather than dropped: the provider is given
 * a token, and the admin is given the value back. Dropping them left the assistant unable to answer
 * who a customer is, which is the most ordinary question there is, and bought nothing extra in
 * return since neither form sends the value.
 */
final class CustomerIdentifierTokenTest extends TestCase
{
    private const ROW = [
        'customer_id' => 32,
        'name' => 'Jan Jansen',
        'email' => 'jan@example.com',
        'telephone' => '0612345678',
        'city' => 'Utrecht',
        'registered' => '2026-08-31 10:00:00',
    ];

    /**
     * @return array{0:PrivacyFilter,1:ConversationVault}
     */
    private function filter(): array
    {
        $vault = new ConversationVault();

        return [new PrivacyFilter($vault, new PiiHeuristic()), $vault];
    }

    /**
     * @param class-string $action
     * @return array<string,array{0:string,1?:string}>
     */
    private function classesOf(string $action): array
    {
        return (new \ReflectionClass($action))->newInstanceWithoutConstructor()->getFieldClassification();
    }

    #[Test]
    public function aNewCustomerReachesTheProviderAsTokensAndTheAdminAsHimself(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(RecentSignupsAction::class), ['recent' => [self::ROW]]);
        $sent = (string)json_encode($out);

        self::assertStringNotContainsString('Jan Jansen', $sent);
        self::assertStringNotContainsString('jan@example.com', $sent);
        self::assertStringNotContainsString('0612345678', $sent);
        self::assertStringContainsString('Utrecht', $sent, 'city is not an identifier and stays usable');

        $row = $out['recent'][0];
        self::assertSame('Jan Jansen', $vault->rehydrate($row['name']));
        self::assertSame('jan@example.com', $vault->rehydrate($row['email']));
    }

    #[Test]
    public function aLookupAnswersInTheSameShapeAsASignup(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(LookupCustomerAction::class), ['results' => [self::ROW]]);
        $row = $out['results'][0];

        self::assertMatchesRegularExpression('#^mago://name_\d+$#', $row['name']);
        self::assertSame('Jan Jansen', $vault->rehydrate($row['name']));
    }

    #[Test]
    public function aMaskedNameWrittenBackIsFlaggedForTheConfirmationWarning(): void
    {
        $vault = new ConversationVault();
        $service = new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic());

        self::assertTrue($service->containsPersonalToken(['firstname' => 'mago://name_1']));
        self::assertFalse($service->containsSensitiveToken(['firstname' => 'mago://name_1']));
    }
}
