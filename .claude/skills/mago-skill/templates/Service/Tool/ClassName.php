<?php
declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Service\Tool;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

class {{ClassName}} implements ToolInterface
{
    public function __construct()
    {
    }

    public function getName(): string
    {
        return '{{tool_name}}';
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    /**
     * An empty input resolves to the most restrictive resource the tool can reach.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return '';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    /**
     * Fields missing from this map are stripped before the model sees the result.
     */
    public function getFieldClassification(string $action = ''): array
    {
        return [];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params): array
    {
        return [];
    }
}
