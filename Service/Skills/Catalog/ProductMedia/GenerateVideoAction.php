<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

/**
 * Generates a short product video on Higgsfield with Seedance 2.0 or Kling 3.0, starting from the
 * main image.
 */
class GenerateVideoAction extends AbstractGenerateAction
{
    public const SEEDANCE = 'seedance_2_0';
    public const KLING = 'kling3_0';
    public const MODELS = [self::SEEDANCE, self::KLING];
    public const DEFAULT_DURATION = 5;

    /** Duration range in seconds per model. */
    public const DURATIONS = [self::SEEDANCE => [4, 15], self::KLING => [3, 15]];

    public const ASPECT_RATIOS = [
        self::SEEDANCE => ['16:9', '9:16', '4:3', '3:4', '1:1', '21:9'],
        self::KLING => ['16:9', '9:16', '1:1'],
    ];

    public const RESOLUTIONS = ['480p', '720p', '1080p'];

    public function getName(): string
    {
        return 'generate_video';
    }

    public function getDescription(): string
    {
        return 'Start generating a short video of an existing product, starting from its main image, with '
            . 'Seedance 2.0 or Kling 3.0 (costs Higgsfield credits, the admin confirms). Returns a request_id; '
            . 'the video itself comes from check_status';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => ['type' => 'string', 'description' => 'SKU of the product'],
            'prompt' => [
                'type' => 'string',
                'description' => 'English description of action, environment, camera movement, lighting and '
                    . 'mood, e.g. "slow 360 degree turn on a white studio background, soft daylight"',
            ],
            'model' => [
                'type' => 'string',
                'enum' => self::MODELS,
                'description' => 'seedance_2_0 (default, product-consistent, up to 1080p) or kling3_0 '
                    . '(cinematic, 16:9, 9:16 or 1:1 only)',
            ],
            'duration' => [
                'type' => 'integer',
                'minimum' => 3,
                'maximum' => 15,
                'description' => 'Length in seconds, default ' . self::DEFAULT_DURATION
                    . '; Seedance needs at least 4',
            ],
            'aspect_ratio' => [
                'type' => 'string',
                'enum' => self::ASPECT_RATIOS[self::SEEDANCE],
                'description' => 'Video aspect ratio, default 16:9',
            ],
            'resolution' => [
                'type' => 'string',
                'enum' => self::RESOLUTIONS,
                'description' => 'Seedance output resolution, default 720p; Kling ignores it',
            ],
            'sound' => ['type' => 'boolean', 'description' => 'Generate native audio, default false'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Tell the admin the video is being generated and usually takes a few minutes, and that you can '
            . 'check the result. Do not claim a video exists before check_status says completed.';
    }

    protected function tool(): string
    {
        return 'generate_video';
    }

    protected function kind(): string
    {
        return 'video';
    }

    protected function imageRole(): string
    {
        return 'start_image';
    }

    protected function maxPromptLength(): int
    {
        return 2500;
    }

    protected function findParamRefusal(array $params): ?array
    {
        $model = $this->model($params);
        $aspectRatio = $params['aspect_ratio'] ?? null;
        if (is_string($aspectRatio) && !in_array($aspectRatio, self::ASPECT_RATIOS[$model], true)) {
            return ['error' => sprintf(
                '%s supports the aspect ratios %s',
                $model,
                implode(', ', self::ASPECT_RATIOS[$model])
            )];
        }

        return null;
    }

    protected function generationParams(array $params): array
    {
        $model = $this->model($params);
        [$min, $max] = self::DURATIONS[$model];
        $sound = ($params['sound'] ?? false) === true;

        $generation = [
            'model' => $model,
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'duration' => max($min, min($max, (int)($params['duration'] ?? self::DEFAULT_DURATION))),
            'aspect_ratio' => $this->oneOf($params['aspect_ratio'] ?? null, self::ASPECT_RATIOS[$model], '16:9'),
        ];

        if ($model === self::KLING) {
            return $generation + ['mode' => 'std', 'sound' => $sound ? 'on' : 'off'];
        }

        return $generation + [
            'resolution' => $this->oneOf($params['resolution'] ?? null, self::RESOLUTIONS, '720p'),
            'mode' => 'std',
            'generate_audio' => $sound,
        ];
    }

    /**
     * Chosen video model, Seedance 2.0 by default.
     *
     * @param array<string,mixed> $params
     * @return string
     */
    private function model(array $params): string
    {
        return $this->oneOf($params['model'] ?? null, self::MODELS, self::SEEDANCE);
    }
}
