<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Addons;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Addons\FeedClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedClientTest extends TestCase
{
    private function client(string $body, int $status = 200): FeedClient
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getBody')->willReturn($body);
        $curl->method('getStatus')->willReturn($status);

        return new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class));
    }

    #[Test]
    public function itReadsTheEntriesOutOfTheFeed(): void
    {
        $addons = $this->client((string)json_encode(['addons' => [[
            'name' => 'PageSpeed Insights',
            'package' => 'mago-assistant/magento2-pagespeed',
            'description' => 'Audit a page from the admin chat.',
            'url' => 'https://askmago.com/add-ons/pagespeed',
            'version' => '1.0.0',
            'released_at' => '2026-09-24',
        ]]]))->fetch();

        self::assertCount(1, $addons);
        self::assertSame('PageSpeed Insights', $addons[0]['name']);
        self::assertSame('mago-assistant/magento2-pagespeed', $addons[0]['package']);
        self::assertSame('https://askmago.com/add-ons/pagespeed', $addons[0]['url']);
    }

    #[Test]
    public function itPutsTheNewestFirst(): void
    {
        $addons = $this->client((string)json_encode(['addons' => [
            ['name' => 'Older', 'package' => 'v/older', 'released_at' => '2026-01-01'],
            ['name' => 'Newest', 'package' => 'v/newest', 'released_at' => '2026-09-24'],
            ['name' => 'Middle', 'package' => 'v/middle', 'released_at' => '2026-05-05'],
        ]]))->fetch();

        self::assertSame(['Newest', 'Middle', 'Older'], array_column($addons, 'name'));
    }

    /**
     * The feed is someone else's document rendered on an admin screen, so only the fields the
     * dashboard draws survive, and a link that is not https is dropped rather than shown.
     */
    #[Test]
    public function itKeepsOnlyTheFieldsTheDashboardDraws(): void
    {
        $addons = $this->client((string)json_encode(['addons' => [[
            'name' => 'Odd one',
            'package' => 'v/odd',
            'url' => 'javascript:alert(1)',
            'onclick' => 'alert(1)',
            'html' => '<script>alert(1)</script>',
        ]]]))->fetch();

        self::assertSame(
            ['name', 'package', 'description', 'url', 'version', 'released_at', 'icon'],
            array_keys($addons[0])
        );
        self::assertSame('', $addons[0]['url']);
    }

    /**
     * An icon is one character, not a URL: an image would make every admin's browser call the
     * feed's host on every dashboard.
     */
    #[Test]
    public function itTakesAGlyphAsAnIconAndNothingLonger(): void
    {
        $addons = $this->client((string)json_encode(['addons' => [
            ['name' => 'Emoji', 'package' => 'v/emoji', 'icon' => '⚡'],
            ['name' => 'A URL', 'package' => 'v/url', 'icon' => 'https://askmago.com/icon.png'],
            ['name' => 'An object', 'package' => 'v/object', 'icon' => ['src' => 'x']],
            ['name' => 'None', 'package' => 'v/none'],
        ]]))->fetch();

        $icons = array_column($addons, 'icon', 'name');

        self::assertSame('⚡', $icons['Emoji']);
        self::assertSame('', $icons['A URL']);
        self::assertSame('', $icons['An object']);
        self::assertSame('', $icons['None']);
    }

    #[Test]
    public function itSkipsAnEntryWithoutANameOrAPackage(): void
    {
        $addons = $this->client((string)json_encode(['addons' => [
            ['name' => 'No package'],
            ['package' => 'v/no-name'],
            ['name' => 'Complete', 'package' => 'v/complete'],
            'not an object',
        ]]))->fetch();

        self::assertSame(['Complete'], array_column($addons, 'name'));
    }

    #[Test]
    public function itCapsAVeryLongFeed(): void
    {
        $entries = [];
        for ($i = 0; $i < 80; $i++) {
            $entries[] = ['name' => 'Add-on ' . $i, 'package' => 'v/p' . $i];
        }

        self::assertCount(50, $this->client((string)json_encode(['addons' => $entries]))->fetch());
    }

    /**
     * Null and [] are different answers: nothing was fetched, versus the feed says there is nothing.
     */
    #[Test]
    public function itAnswersNullWhenItCannotReadTheFeed(): void
    {
        self::assertNull($this->client('not json at all')->fetch());
        self::assertNull($this->client('{"something":"else"}')->fetch());
        self::assertNull($this->client('{"addons":[]}', 503)->fetch());
    }

    #[Test]
    public function itAnswersAnEmptyListWhenTheFeedIsEmpty(): void
    {
        self::assertSame([], $this->client('{"addons":[]}')->fetch());
    }
}
