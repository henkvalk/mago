<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps generated files under pub/media/mago/higgsfield/<request id>/, because Higgsfield removes
 * its outputs after about seven days.
 */
class MediaStorage
{
    public const BASE_DIR = 'mago/higgsfield';
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    public const VIDEO_EXTENSIONS = ['mp4'];

    private const REQUEST_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly HiggsfieldMedia $client
    ) {
    }

    public function isValidRequestId(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }

    /**
     * Downloads the outputs of a request once and returns the stored files.
     *
     * @param string $requestId
     * @param list<string> $urls
     * @param string $defaultExtension Used when the URL path has no known extension
     * @return list<string>|null Paths relative to pub/media, null when a download failed
     */
    public function store(string $requestId, array $urls, string $defaultExtension): ?array
    {
        $stored = $this->files($requestId);
        if ($stored !== []) {
            return $stored;
        }

        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $dir = $this->dir($requestId);
        $files = [];
        try {
            $media->create($dir);
            foreach (array_values($urls) as $index => $url) {
                $file = sprintf('%s/%d.%s', $dir, $index + 1, $this->extension($url, $defaultExtension));
                if (!$this->client->download($url, $media->getAbsolutePath($file))) {
                    $media->delete($dir);
                    return null;
                }
                $files[] = $file;
            }
        } catch (FileSystemException) {
            return null;
        }

        return $files;
    }

    /**
     * Stored files of a request, in output order.
     *
     * @param string $requestId
     * @return list<string>
     */
    public function files(string $requestId): array
    {
        $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $dir = $this->dir($requestId);
        try {
            $files = $media->isDirectory($dir) ? $media->read($dir) : [];
        } catch (FileSystemException) {
            return [];
        }

        $allowed = array_merge(self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS);
        $files = array_values(array_filter(
            $files,
            fn (string $file): bool => in_array($this->extensionOf($file), $allowed, true)
        ));
        natsort($files);

        return array_values($files);
    }

    public function url(string $file): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $file;
    }

    public function isImage(string $file): bool
    {
        return in_array($this->extensionOf($file), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Lower-case extension of a file name or URL path, without the dot.
     *
     * @param string $path
     * @return string
     */
    private function extensionOf(string $path): string
    {
        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');

        return $dot === false || ($slash !== false && $dot < $slash) ? '' : strtolower(substr($path, $dot + 1));
    }

    private function dir(string $requestId): string
    {
        return self::BASE_DIR . '/' . $requestId;
    }

    private function extension(string $url, string $default): string
    {
        $extension = $this->extensionOf((string)strtok($url, '?#'));

        return in_array($extension, array_merge(self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS), true)
            ? $extension
            : $default;
    }
}
