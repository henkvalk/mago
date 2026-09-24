<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Addons;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;
use MagoAssistant\Mago\Api\ModuleInfo\InstalledPackagesInterface;

/**
 * The add-on feed as the dashboard sees it.
 *
 * The feed is fetched by the first request that needs it and then cached for a week, which is why
 * there is no cron job: one request per week per store is not worth a schedule, and a schedule that
 * is not running is why a panel would never appear at all.
 *
 * Nothing here runs while a page is being rendered — the panel asks for its add-ons over its own
 * request once the admin screen is already up, so a feed that hangs costs that background request
 * and nothing else. Three guards keep even that cheap: a short timeout in the client, a failed
 * attempt remembered for an hour, and a lock so a cold cache in ten open tabs is one fetch rather
 * than ten.
 */
class AddonFeed
{
    public const CACHE_KEY = 'mago_addon_feed';
    public const CACHE_TAG = 'MAGO_ADDON_FEED';

    /** Marks that a fetch was tried and failed, so the next request does not try again straight away */
    private const ATTEMPT_KEY = 'mago_addon_feed_attempt';

    /** Held while a fetch is in flight, so simultaneous requests do not all go out */
    private const LOCK_KEY = 'mago_addon_feed_lock';

    private const CACHE_LIFETIME = 604800;
    private const RETRY_AFTER = 3600;

    /**
     * How long the lock stands. Comfortably longer than the client's own timeout, so it always
     * outlives the request that took it, and short enough that a killed process frees it quickly.
     */
    private const LOCK_TTL = 30;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly FeedClient $client,
        private readonly ConfigRepositoryInterface $configRepository,
        private readonly InstalledPackagesInterface $installedPackages
    ) {
    }

    /**
     * The newest add-ons the store does not have yet, at most $limit of them.
     *
     * @return list<array<string, mixed>>
     */
    public function getLatest(int $limit = 3): array
    {
        if (!$this->configRepository->isAddonFeedEnabled()) {
            return [];
        }

        $addons = [];
        foreach ($this->cached() ?? $this->fetchAndCache() as $addon) {
            // An add-on already installed is not news; the panel is there to show what is new.
            if ($this->installedPackages->getVersion((string)($addon['package'] ?? '')) !== '') {
                continue;
            }
            $addons[] = $addon;
        }

        return array_slice($addons, 0, max(0, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAndCache(): array
    {
        // A failed fetch is remembered, so a feed that is down is not retried on every request.
        if ($this->cache->load(self::ATTEMPT_KEY)) {
            return [];
        }

        // Flush the cache with several admins logged in and every one of their panels asks at once.
        // The first takes the lock and goes out; the rest answer with nothing and ask again later.
        if ($this->cache->load(self::LOCK_KEY)) {
            return [];
        }
        $this->cache->save('1', self::LOCK_KEY, [self::CACHE_TAG], self::LOCK_TTL);

        try {
            $addons = $this->client->fetch();
        } finally {
            $this->cache->remove(self::LOCK_KEY);
        }

        if ($addons === null) {
            $this->cache->save('1', self::ATTEMPT_KEY, [self::CACHE_TAG], self::RETRY_AFTER);

            return [];
        }

        $this->cache->save(
            (string)$this->json->serialize($addons),
            self::CACHE_KEY,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $addons;
    }

    /**
     * The cached feed, or null when there is none — which is different from a cached empty feed.
     *
     * What comes back out of the cache is whatever was in it, so each entry is checked on the way
     * out as well: a half-written or hand-edited entry costs its own card, not the panel.
     *
     * @return list<array<string, mixed>>|null
     */
    private function cached(): ?array
    {
        $stored = $this->cache->load(self::CACHE_KEY);
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $addons = [];
        foreach ($decoded as $addon) {
            if (is_array($addon)) {
                $addons[] = $addon;
            }
        }

        return $addons;
    }
}
