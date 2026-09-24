<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Hand-written mixed tool (not action-scoped) whose read actions are those listed in $readActions.
 * Counts getParameterSchema() calls so registry memoization can be asserted.
 */
final class FakeTool implements ToolInterface
{
    private int $schemaCalls = 0;

    /** @var array<string, mixed> */
    private array $result = [];

    /** @var array<string,array{0:string,1?:string}> */
    private array $fieldClassification = [PiiClass::ANY => [PiiClass::PUBLIC]];

    /**
     * @param string[] $actions
     * @param string[] $readActions
     */
    public function __construct(
        private readonly string $name,
        private readonly array $actions,
        private readonly array $readActions,
        private readonly string $magentoAcl = ''
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function withResult(array $result): self
    {
        $this->result = $result;

        return $this;
    }

    /**
     * @param array<string,array{0:string,1?:string}> $fieldClassification
     */
    public function withFieldClassification(array $fieldClassification): self
    {
        $this->fieldClassification = $fieldClassification;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Fake tool with actions ' . implode(', ', $this->actions);
    }

    public function getParameterSchema(): array
    {
        $this->schemaCalls++;

        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => $this->actions],
                'target' => ['type' => 'string'],
            ],
            'required' => ['action'],
        ];
    }

    public function getSchemaCalls(): int
    {
        return $this->schemaCalls;
    }

    public function execute(array $params): array
    {
        return $this->result === [] ? ['executed' => $params['action'] ?? ''] : $this->result;
    }

    public function isReadOnly(): bool
    {
        return $this->readActions === $this->actions;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return in_array($input['action'] ?? '', $this->readActions, true);
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        // Wildcard-public by default so non-privacy tests see the fake's output unfiltered.
        return $this->fieldClassification;
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->magentoAcl;
    }
}
