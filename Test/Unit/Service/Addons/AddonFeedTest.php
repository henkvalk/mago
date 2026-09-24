<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Addons;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Addons\AddonFeed;
use MagoAssistant\Mago\Service\Addons\FeedClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInstalledPackages;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddonFeedTest extends TestCase
{
    private const FEED = '{"addons":[{"name":"PageSpeed","package":"mago-assistant/magento2-pagespeed","released_at":"2026-09-24"},'
        . '{"name":"VIES","package":"mago-assistant/magento2-vies","released_at":"2026-09-22"},'
        . '{"name":"Issue Tracker","package":"mago-assistant/magento2-issue-tracker","released_at":"2026-09-20"},'
        . '{"name":"Fourth","package":"v/fourth","released_at":"2026-09-01"}]}';

    private array $store = [];

    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->store[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->store[$key] = $data;

            return true;
        });
        $cache->method('remove')->willReturnCallback(function (string $key): bool {
            unset($this->store[$key]);

            return true;
        });

        return $cache;
    }

    private function feed(
        string $body = self::FEED,
        int $status = 200,
        ?FakeInstalledPackages $installed = null,
        ?FakeConfigRepository $config = null
    ): AddonFeed {
        $curl = $this->createMock(Curl::class);
        $curl->method('getBody')->willReturn($body);
        $curl->method('getStatus')->willReturn($status);

        $config ??= new FakeConfigRepository();
        $client = new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class));

        return new AddonFeed(
            $this->cache(),
            new Json(),
            $client,
            $config,
            $installed ?? new FakeInstalledPackages()
        );
    }

    /**
     * No cron fills this: the first dashboard that needs the feed fetches it.
     */
    #[Test]
    public function itFetchesTheFeedOnTheFirstAsk(): void
    {
        $feed = $this->feed();

        self::assertSame(['PageSpeed', 'VIES', 'Issue Tracker'], array_column($feed->getLatest(), 'name'));
        self::assertNotSame([], $this->store);
    }

    #[Test]
    public function itAnswersFromTheCacheOnceItHasOne(): void
    {
        $this->feed()->getLatest();

        // A client that would fail if it were called at all: the second ask must not reach it.
        $offline = $this->feed('gateway timeout', 504);

        self::assertSame(['PageSpeed', 'VIES', 'Issue Tracker'], array_column($offline->getLatest(), 'name'));
    }

    /**
     * An add-on the store already has is not news, so it steps aside for the next one down.
     */
    #[Test]
    public function itLeavesOutWhatIsAlreadyInstalled(): void
    {
        $installed = (new FakeInstalledPackages())->withPackage('mago-assistant/magento2-vies', '1.0.0');
        $feed = $this->feed(installed: $installed);

        self::assertSame(['PageSpeed', 'Issue Tracker', 'Fourth'], array_column($feed->getLatest(), 'name'));
    }

    #[Test]
    public function itShowsNothingWhenTheFeedIsSwitchedOff(): void
    {
        $off = (new FakeConfigRepository())->withAddonFeedEnabled(false);
        $feed = $this->feed(config: $off);

        self::assertSame([], $feed->getLatest());
        self::assertSame([], $this->store);
    }

    /**
     * A feed that cannot be reached is not news that there are no add-ons. Blanking the panel would
     * be the worst reading of a network error.
     */
    #[Test]
    public function itKeepsTheLastGoodListWhenALaterFetchWouldFail(): void
    {
        $this->feed()->getLatest();

        $broken = $this->feed('gateway timeout', 504);

        self::assertSame(['PageSpeed', 'VIES', 'Issue Tracker'], array_column($broken->getLatest(), 'name'));
    }

    /**
     * Without this, a feed that is down costs every single dashboard render its timeout.
     */
    #[Test]
    public function itRemembersAFailedFetchAndDoesNotRetryStraightAway(): void
    {
        $broken = $this->feed('gateway timeout', 504);

        self::assertSame([], $broken->getLatest());
        self::assertArrayHasKey('mago_addon_feed_attempt', $this->store);

        // The marker stands, so a second ask does not reach the client either.
        self::assertSame([], $this->feed('gateway timeout', 504)->getLatest());
    }

    /**
     * Flush the cache with several admins logged in and every panel asks at once. Without a lock
     * that is one outbound request per open tab, each holding a PHP worker for the timeout.
     */
    #[Test]
    public function itLetsOnlyOneRequestFetchAColdFeed(): void
    {
        $this->store['mago_addon_feed_lock'] = '1';

        self::assertSame([], $this->feed()->getLatest());
        self::assertArrayNotHasKey('mago_addon_feed', $this->store);
    }

    #[Test]
    public function itReleasesTheLockAfterAFetch(): void
    {
        $this->feed()->getLatest();

        self::assertArrayNotHasKey('mago_addon_feed_lock', $this->store);
    }

    #[Test]
    public function itReleasesTheLockWhenTheFetchBlowsUp(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \RuntimeException('connection reset'));

        $config = new FakeConfigRepository();
        $feed = new AddonFeed(
            $this->cache(),
            new Json(),
            new FeedClient($curl, new Json(), $this->createMock(ErrorLogger::class)),
            $config,
            new FakeInstalledPackages()
        );

        self::assertSame([], $feed->getLatest());
        self::assertArrayNotHasKey('mago_addon_feed_lock', $this->store);
    }

    #[Test]
    public function itRespectsTheLimitItIsGiven(): void
    {
        $feed = $this->feed();

        self::assertCount(1, $feed->getLatest(1));
        self::assertSame([], $feed->getLatest(0));
    }
}
