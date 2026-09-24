<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Addons;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;

/**
 * Fetches the add-on feed.
 *
 * A plain GET with no query string and no headers of its own beyond the user agent: the store tells
 * the feed nothing about itself, which is the whole reason this is a static document rather than an
 * endpoint that answers per installation.
 */
class FeedClient
{
    private const FEED_URL = 'https://askmago.com/addons.json';

    /** Where the panel sends someone who wants more than the two it has room for */
    public const OVERVIEW_URL = 'https://askmago.com/add-ons';
    private const USER_AGENT = 'MagoAssistant-Mago';
    /** Short on purpose: a cold cache means an administrator is waiting on this */
    private const TIMEOUT = 3;

    /** A feed longer than this is a mistake on the other end, not something to render */
    private const MAX_ENTRIES = 50;

    /** An icon is one emoji; anything longer is not an icon */
    private const MAX_ICON_LENGTH = 4;

    public function __construct(
        // One call per cron run, so the client itself is enough; no factory needed.
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    /**
     * The entries in the feed, newest first, or null when it could not be fetched or read.
     *
     * Null and an empty list are different answers: nothing was fetched, versus the feed says there
     * is nothing new. Only the second one is worth caching as a result.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetch(): ?array
    {
        $body = $this->get(self::FEED_URL);
        if ($body === null) {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('AddonFeed', 'Feed is not valid JSON: ' . $e->getMessage());

            return null;
        }

        if (!is_array($decoded) || !isset($decoded['addons']) || !is_array($decoded['addons'])) {
            $this->errorLogger->addLog('AddonFeed', 'Feed has no "addons" array');

            return null;
        }

        return $this->normalise($decoded['addons']);
    }

    /**
     * Keeps the fields the dashboard draws and drops everything else, so a feed that grows new keys
     * cannot put unexpected content on an admin screen.
     *
     * @param array<mixed> $entries
     * @return list<array<string, mixed>>
     */
    private function normalise(array $entries): array
    {
        $addons = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string)($entry['name'] ?? ''));
            $package = trim((string)($entry['package'] ?? ''));
            if ($name === '' || $package === '') {
                continue;
            }

            $url = trim((string)($entry['url'] ?? ''));
            $addons[] = [
                'name' => $name,
                'package' => $package,
                'description' => trim((string)($entry['description'] ?? '')),
                // Only an https link is kept: the panel renders it as a link an admin will click.
                'url' => preg_match('#^https://#i', $url) ? $url : '',
                'version' => trim((string)($entry['version'] ?? '')),
                'released_at' => trim((string)($entry['released_at'] ?? '')),
                'icon' => $this->icon($entry['icon'] ?? ''),
            ];
        }

        usort($addons, static fn (array $a, array $b): int => strcmp($b['released_at'], $a['released_at']));

        return array_slice($addons, 0, self::MAX_ENTRIES);
    }

    /**
     * The entry's icon, as a character rather than an image.
     *
     * An <img> would make every admin's browser fetch from the feed's host on every dashboard, which
     * turns a static document into something that sees who is looking at it. A glyph costs no
     * request, so the feed may send one emoji and nothing else.
     */
    private function icon(mixed $value): string
    {
        $icon = trim((string)(is_scalar($value) ? $value : ''));

        return mb_strlen($icon) <= self::MAX_ICON_LENGTH ? $icon : '';
    }

    private function get(string $url): ?string
    {
        try {
            $this->curl->setOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $this->curl->addHeader('User-Agent', self::USER_AGENT);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get($url);

            if ($this->curl->getStatus() !== 200) {
                $this->errorLogger->addLog('AddonFeed', 'Feed answered with HTTP ' . $this->curl->getStatus());

                return null;
            }

            return $this->curl->getBody();
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('AddonFeed', 'Could not reach the feed: ' . $e->getMessage());

            return null;
        }
    }
}
