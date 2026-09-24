<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Starts a Higgsfield generation from a product's main image. Spent credits cannot be returned,
 * so the admin confirms each call with the preflighted cost in view.
 */
abstract class AbstractGenerateAction implements IrreversibleActionInterface, ValidatingActionInterface
{
    public const NOT_CONNECTED = 'Higgsfield is not connected. Connect an account under Stores > Configuration > '
        . 'Mago Assistant > General > Higgsfield Media Generation.';

    public function __construct(
        protected readonly HiggsfieldMedia $client,
        protected readonly ProductImageSource $imageSource
    ) {
    }

    /**
     * MCP tool that runs the generation: generate_image or generate_video.
     */
    abstract protected function tool(): string;

    /**
     * "image" or "video".
     */
    abstract protected function kind(): string;

    /**
     * Generation parameters for the MCP tool, without the input image.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    abstract protected function generationParams(array $params): array;

    /**
     * Media role under which the product image is passed to the model.
     */
    abstract protected function imageRole(): string;

    /**
     * Maximum prompt length the model accepts.
     */
    abstract protected function maxPromptLength(): int;

    /**
     * Refusal for parameters the chosen model does not support, or null.
     *
     * @param array<string,mixed> $params
     * @return array{error:string}|null
     */
    protected function findParamRefusal(array $params): ?array
    {
        return null;
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'request_id' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'kind' => [PiiClass::PUBLIC],
            'model' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'product_name' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
        ];
    }

    public function findRefusal(array $params): ?array
    {
        if (!$this->client->isConnected()) {
            return ['error' => self::NOT_CONNECTED];
        }

        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            return ['error' => 'prompt is required'];
        }
        if (mb_strlen($prompt) > $this->maxPromptLength()) {
            return ['error' => 'prompt is longer than ' . $this->maxPromptLength() . ' characters'];
        }

        $paramRefusal = $this->findParamRefusal($params);
        if ($paramRefusal !== null) {
            return $paramRefusal;
        }

        $product = $this->imageSource->find((string)($params['sku'] ?? ''));

        return isset($product['error']) ? ['error' => $product['error']] : null;
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $product = $this->imageSource->find((string)($params['sku'] ?? ''));
        $label = isset($product['error'])
            ? 'the product'
            : (string)$product['sku'] . ' (' . (string)$product['product_name'] . ')';

        $credits = $this->client->cost($this->tool(), $this->generationParams($params));
        $cost = $credits !== null
            ? sprintf('%s credits', $this->formatCredits($credits))
            : 'credits (no estimate available)';

        return [
            sprintf(
                'Higgsfield generates %s of %s with %s. This spends %s that cannot be refunded.',
                $this->kind() === 'image' ? 'an image' : 'a video',
                $label,
                (string)$this->generationParams($params)['model'],
                $cost
            ),
            'The main product image is uploaded to Higgsfield to generate from.',
            'Nothing in the catalog changes; the result is saved under pub/media/mago/higgsfield once it is ready.',
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        $product = $this->imageSource->find((string)$params['sku']);
        $contents = $this->imageSource->read((string)$product['file']);
        if ($contents === null) {
            return ['error' => 'The main image file of "' . (string)$product['sku'] . '" could not be read.'];
        }

        $upload = $this->client->uploadImage(
            $contents,
            (string)$product['content_type'],
            substr((string)strrchr('/' . (string)$product['file'], '/'), 1)
        );
        if (isset($upload['error'])) {
            return ['error' => $upload['error']];
        }

        $generation = $this->generationParams($params);
        $generation['medias'] = [['value' => (string)$upload['media_id'], 'role' => $this->imageRole()]];

        $result = $this->client->submit($this->tool(), $generation);
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'request_id' => (string)$result['job_id'],
            'status' => (string)$result['status'],
            'kind' => $this->kind(),
            'model' => (string)$generation['model'],
            'sku' => (string)$product['sku'],
            'product_name' => (string)$product['product_name'],
            'message' => 'Generation started. Check the result with action "check_status" and this request_id.',
        ];
    }

    /**
     * The value when it is one of the allowed strings, otherwise the default.
     *
     * @param mixed $value
     * @param list<string> $allowed
     * @param string $default
     * @return string
     */
    protected function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function formatCredits(float $credits): string
    {
        return rtrim(rtrim(number_format($credits, 2, '.', ''), '0'), '.');
    }
}
