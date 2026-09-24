<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Reports the state of a Higgsfield generation and saves finished files to the media folder.
 */
class CheckStatusAction implements ActionInterface
{
    private const PENDING = ['queued', 'in_progress'];

    public function __construct(
        private readonly HiggsfieldClient $client,
        private readonly MediaStorage $storage
    ) {
    }

    public function getName(): string
    {
        return 'check_status';
    }

    public function getDescription(): string
    {
        return 'Check a generate_image or generate_video request by request_id; when it is done, returns '
            . 'links to the saved image(s) or video';
    }

    public function getParameterSchema(): array
    {
        return [
            'request_id' => [
                'type' => 'string',
                'description' => 'request_id returned by generate_image or generate_video',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'request_id' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'number' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'When completed, show the links. Offer to add a generated image to the product gallery with '
            . 'attach_image; videos cannot be attached, the admin can download them from the link. When still '
            . 'queued or in progress, say so and offer to check again later; do not poll in a loop.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $requestId = strtolower(trim((string)($params['request_id'] ?? '')));
        if (!$this->storage->isValidRequestId($requestId)) {
            return ['error' => 'request_id must be the id returned by generate_image or generate_video'];
        }

        $stored = $this->storage->files($requestId);
        if ($stored !== []) {
            return $this->completed($requestId, $stored);
        }

        $result = $this->client->status($requestId);
        // A failed generation carries its reason in "error" too, so only a missing status means the call failed.
        if (!isset($result['status'])) {
            return ['error' => (string)($result['error'] ?? 'Higgsfield returned no status.')];
        }

        $status = (string)($result['status'] ?? '');
        if (in_array($status, self::PENDING, true)) {
            return ['request_id' => $requestId, 'status' => $status, 'message' => 'Not ready yet.'];
        }

        if ($status !== 'completed') {
            $reason = match ($status) {
                'nsfw' => 'Higgsfield blocked the result for its content policy.',
                'canceled' => 'The request was canceled.',
                default => 'Generation failed' . (is_string($result['error'] ?? null) ? ': ' . $result['error'] : '.'),
            };

            return [
                'request_id' => $requestId,
                'status' => $status,
                'message' => $reason . ' No credits were charged.',
            ];
        }

        [$urls, $extension] = $this->outputs($result);
        if ($urls === []) {
            return ['error' => 'Higgsfield reports the request as completed but returned no files.'];
        }

        $files = $this->storage->store($requestId, $urls, $extension);
        if ($files === null) {
            return ['error' => 'The generated files could not be downloaded from Higgsfield. Try again.'];
        }

        return $this->completed($requestId, $files);
    }

    /**
     * Output URLs and the file extension to fall back on.
     *
     * @param array<string,mixed> $result
     * @return array{0:list<string>,1:string}
     */
    private function outputs(array $result): array
    {
        if (is_array($result['video'] ?? null) && is_string($result['video']['url'] ?? null)) {
            return [[$result['video']['url']], 'mp4'];
        }

        $urls = [];
        foreach ((array)($result['images'] ?? []) as $image) {
            if (is_array($image) && is_string($image['url'] ?? null)) {
                $urls[] = $image['url'];
            }
        }

        return [$urls, 'png'];
    }

    /**
     * Result with a link per stored file.
     *
     * @param string $requestId
     * @param list<string> $files
     * @return array<string,mixed>
     */
    private function completed(string $requestId, array $files): array
    {
        $rows = [];
        foreach ($files as $index => $file) {
            $rows[] = [
                'number' => $index + 1,
                'type' => $this->storage->isImage($file) ? 'image' : 'video',
                'url' => $this->storage->url($file),
            ];
        }

        return ['request_id' => $requestId, 'status' => 'completed', 'files' => $rows];
    }
}
