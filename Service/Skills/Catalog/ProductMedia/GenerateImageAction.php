<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

/**
 * Generates a new product image with Nano Banana 2 on Higgsfield, using the main image as
 * reference.
 */
class GenerateImageAction extends AbstractGenerateAction
{
    public const MODEL = 'nano_banana_2';
    public const ASPECT_RATIOS = ['1:1', '3:2', '2:3', '4:3', '3:4', '4:5', '5:4', '9:16', '16:9', '21:9'];
    public const RESOLUTIONS = ['1k', '2k', '4k'];

    public function getName(): string
    {
        return 'generate_image';
    }

    public function getDescription(): string
    {
        return 'Start generating a new image of an existing product from its main image with Nano Banana 2 '
            . '(costs Higgsfield credits, the admin confirms). Returns a request_id; the image itself comes from '
            . 'check_status';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => ['type' => 'string', 'description' => 'SKU of the product'],
            'prompt' => [
                'type' => 'string',
                'description' => 'English description of the wanted image: scene, background, lighting, style. '
                    . 'The product itself stays as in its main image',
            ],
            'aspect_ratio' => [
                'type' => 'string',
                'enum' => self::ASPECT_RATIOS,
                'description' => 'Image aspect ratio, default 1:1',
            ],
            'resolution' => [
                'type' => 'string',
                'enum' => self::RESOLUTIONS,
                'description' => 'Resolution tier, default 2k',
            ],
        ];
    }

    public function getInstructions(): string
    {
        return 'Tell the admin the image is being generated and that you can check the result; usually it is '
            . 'ready within a minute. Do not claim an image exists before check_status says completed.';
    }

    protected function tool(): string
    {
        return 'generate_image';
    }

    protected function kind(): string
    {
        return 'image';
    }

    protected function imageRole(): string
    {
        return 'image_references';
    }

    protected function maxPromptLength(): int
    {
        return 5000;
    }

    protected function generationParams(array $params): array
    {
        return [
            'model' => self::MODEL,
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'aspect_ratio' => $this->oneOf($params['aspect_ratio'] ?? null, self::ASPECT_RATIOS, '1:1'),
            'resolution' => $this->oneOf($params['resolution'] ?? null, self::RESOLUTIONS, '2k'),
        ];
    }
}
