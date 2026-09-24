<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

/**
 * Generates a short product video with Higgsfield Kling 3.0 image-to-video, starting from the main
 * image.
 */
class GenerateVideoAction extends AbstractGenerateAction
{
    public const ENDPOINT = 'kling-video/v3.0/std/image-to-video';
    public const MIN_DURATION = 3;
    public const MAX_DURATION = 15;
    public const DEFAULT_DURATION = 5;

    public function getName(): string
    {
        return 'generate_video';
    }

    public function getDescription(): string
    {
        return 'Start generating a short video of an existing product, starting from its main image (costs '
            . 'Higgsfield credits, the admin confirms). Returns a request_id; the video itself comes from '
            . 'check_status';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => ['type' => 'string', 'description' => 'SKU of the product'],
            'prompt' => [
                'type' => 'string',
                'description' => 'English description of the motion and camera work, e.g. "slow 360 degree turn '
                    . 'on a white studio background"',
            ],
            'duration' => [
                'type' => 'integer',
                'minimum' => self::MIN_DURATION,
                'maximum' => self::MAX_DURATION,
                'description' => 'Length in seconds, default ' . self::DEFAULT_DURATION,
            ],
            'sound' => ['type' => 'boolean', 'description' => 'Generate background sound, default false'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Tell the admin the video is being generated and usually takes a few minutes, and that you can '
            . 'check the result. Do not claim a video exists before check_status says completed.';
    }

    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    protected function kind(): string
    {
        return 'video';
    }

    protected function maxPromptLength(): int
    {
        return 2500;
    }

    protected function body(array $params, string $imageUrl): array
    {
        $duration = (int)($params['duration'] ?? self::DEFAULT_DURATION);

        return [
            'image_url' => $imageUrl,
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'duration' => max(self::MIN_DURATION, min(self::MAX_DURATION, $duration)),
            'sound' => ($params['sound'] ?? false) === true ? 'on' : 'off',
        ];
    }
}
