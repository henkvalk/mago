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
 * so the admin confirms each call with the estimated cost in view.
 */
abstract class AbstractGenerateAction implements IrreversibleActionInterface, ValidatingActionInterface
{
    public const NOT_CONFIGURED = 'Higgsfield is not configured. Add the API key ID and secret under Stores > '
        . 'Configuration > Mago Assistant > General > Higgsfield Media Generation.';

    public function __construct(
        protected readonly HiggsfieldClient $client,
        protected readonly ProductImageSource $imageSource
    ) {
    }

    /**
     * Model endpoint, e.g. "marketing-studio/image".
     */
    abstract protected function endpoint(): string;

    /**
     * "image" or "video".
     */
    abstract protected function kind(): string;

    /**
     * Request body for the model.
     *
     * @param array<string,mixed> $params
     * @param string $imageUrl Public URL of the product image
     * @return array<string,mixed>
     */
    abstract protected function body(array $params, string $imageUrl): array;

    /**
     * Maximum prompt length the model accepts.
     */
    abstract protected function maxPromptLength(): int;

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
            'sku' => [PiiClass::PUBLIC],
            'product_name' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
        ];
    }

    public function findRefusal(array $params): ?array
    {
        if (!$this->client->isConfigured()) {
            return ['error' => self::NOT_CONFIGURED];
        }

        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            return ['error' => 'prompt is required'];
        }
        if (mb_strlen($prompt) > $this->maxPromptLength()) {
            return ['error' => 'prompt is longer than ' . $this->maxPromptLength() . ' characters'];
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

        $estimate = isset($product['public_url'])
            ? $this->client->estimate($this->endpoint(), $this->body($params, (string)$product['public_url']))
            : null;
        $cost = $estimate !== null
            ? sprintf('%s credits (about $%s)', $estimate['credits'], $estimate['usd'])
            : 'credits (no estimate available)';

        return [
            sprintf(
                'Higgsfield generates a %s of %s. This spends %s that cannot be refunded.',
                $this->kind(),
                $label,
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

        $upload = $this->client->uploadImage($contents, (string)$product['content_type']);
        if (isset($upload['error'])) {
            return ['error' => $upload['error']];
        }

        $result = $this->client->submit($this->endpoint(), $this->body($params, (string)$upload['public_url']));
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'request_id' => (string)($result['request_id'] ?? ''),
            'status' => (string)($result['status'] ?? 'queued'),
            'kind' => $this->kind(),
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
}
