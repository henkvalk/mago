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

/**
 * The feed is a document on someone else's server, rendered inside the admin of every store that
 * installs this module. Whoever can change that document can put text on an administrator's screen,
 * so these are the things that must not get through.
 */
final class FeedClientHostileTest extends TestCase
{
    private function fetch(array $entries): array
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getBody')->willReturn((string)json_encode(['addons' => $entries]));
        $curl->method('getStatus')->willReturn(200);

        return (new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class)))->fetch() ?? [];
    }

    #[Test]
    public function itDropsEveryLinkSchemeButHttps(): void
    {
        $hostile = [
            'javascript:alert(document.cookie)',
            'JaVaScRiPt:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            'http://askmago.com/plain',
            '//askmago.com/protocol-relative',
            ' javascript:alert(1)',
        ];

        foreach ($hostile as $index => $url) {
            $addons = $this->fetch([['name' => 'X', 'package' => 'v/x' . $index, 'url' => $url]]);

            self::assertSame('', $addons[0]['url'], 'Should have dropped: ' . $url);
        }

        $addons = $this->fetch([['name' => 'X', 'package' => 'v/x', 'url' => 'https://askmago.com/ok']]);
        self::assertSame('https://askmago.com/ok', $addons[0]['url']);
    }

    /**
     * Nothing from the feed is ever inserted as markup, but a field that carries a tag is still
     * worth keeping as literal text rather than silently stripping: the administrator should see
     * exactly what the feed said.
     */
    #[Test]
    public function itKeepsMarkupAsTextRatherThanAsMarkup(): void
    {
        $addons = $this->fetch([[
            'name' => '<img src=x onerror=alert(1)>',
            'package' => 'v/x',
            'description' => '</span><script>alert(1)</script>',
        ]]);

        self::assertSame('<img src=x onerror=alert(1)>', $addons[0]['name']);
        self::assertSame('</span><script>alert(1)</script>', $addons[0]['description']);
    }

    #[Test]
    public function itRefusesAnIconThatIsNotOneCharacter(): void
    {
        $addons = $this->fetch([
            ['name' => 'A', 'package' => 'v/a', 'icon' => str_repeat('💥', 200)],
            ['name' => 'B', 'package' => 'v/b', 'icon' => '<svg onload=alert(1)>'],
            ['name' => 'C', 'package' => 'v/c', 'icon' => ['nested' => 'array']],
            ['name' => 'D', 'package' => 'v/d', 'icon' => '⚡'],
        ]);

        $icons = array_column($addons, 'icon', 'name');
        self::assertSame(['A' => '', 'B' => '', 'C' => '', 'D' => '⚡'], $icons);
    }

    /**
     * A feed that grows keys the panel does not know must not be able to smuggle them onto the
     * screen, and must not be able to reach into anything the browser treats specially.
     */
    #[Test]
    public function itKeepsNothingButTheFieldsThePanelDraws(): void
    {
        $addons = $this->fetch([[
            'name' => 'X',
            'package' => 'v/x',
            '__proto__' => ['polluted' => true],
            'constructor' => 'boom',
            'onclick' => 'alert(1)',
            'innerHTML' => '<script>alert(1)</script>',
            'className' => 'mago-addon is-evil',
        ]]);

        self::assertSame(
            ['name', 'package', 'description', 'url', 'version', 'released_at', 'icon'],
            array_keys($addons[0])
        );
    }

    #[Test]
    public function itCannotBeMadeToRenderAThousandCards(): void
    {
        $entries = [];
        for ($i = 0; $i < 5000; $i++) {
            $entries[] = ['name' => 'Flood ' . $i, 'package' => 'v/p' . $i];
        }

        self::assertCount(50, $this->fetch($entries));
    }

    #[Test]
    public function itSurvivesAFeedThatIsNotTheShapeItExpects(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $client = new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class));

        foreach (['{"addons": "not an array"}', '{"addons": null}', '[]', 'null', '{}'] as $body) {
            $curl = $this->createMock(Curl::class);
            $curl->method('getStatus')->willReturn(200);
            $curl->method('getBody')->willReturn($body);
            $client = new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class));

            self::assertNull($client->fetch(), 'Should have refused: ' . $body);
        }
    }
}
