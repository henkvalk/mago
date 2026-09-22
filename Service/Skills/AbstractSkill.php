<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;

abstract class AbstractSkill implements ActionScopedToolInterface, IrreversibleToolInterface, ValidatingToolInterface
{
    /** @var ActionInterface[] */
    private readonly array $actions;

    /**
     * @param ActionInterface[] $actions
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        array $actions = []
    ) {
        $this->actions = $actions;
    }

    abstract public function getName(): string;

    abstract protected function getBaseDescription(): string;

    public function getDescription(): string
    {
        return $this->buildDescription($this->actions);
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        return $this->buildDescription($this->selectActions($actionNames));
    }

    public function getParameterSchema(): array
    {
        return $this->buildParameterSchema($this->actions);
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        return $this->buildParameterSchema($this->selectActions($actionNames));
    }

    /**
     * @param ActionInterface[] $actions
     */
    private function buildDescription(array $actions): string
    {
        $parts = [];
        foreach ($actions as $action) {
            $parts[] = '"' . $action->getName() . '" (' . $action->getDescription() . ')';
        }

        return $this->getBaseDescription() . ' Actions: ' . implode(', ', $parts) . '.';
    }

    /**
     * @param ActionInterface[] $actions
     * @return array<string, mixed>
     */
    private function buildParameterSchema(array $actions): array
    {
        $actionNames = [];
        $properties = [];

        foreach ($actions as $action) {
            $actionNames[] = $action->getName();
            foreach ($action->getParameterSchema() as $paramName => $paramSchema) {
                if (!isset($properties[$paramName])) {
                    $properties[$paramName] = $paramSchema;
                }
            }
        }

        $properties = array_merge(
            [
                'action' => [
                    'type' => 'string',
                    'enum' => $actionNames,
                    'description' => 'The action to perform',
                ],
            ],
            $properties
        );

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['action'],
        ];
    }

    /**
     * Registered actions whose name is in the given list, in registration order
     *
     * @param string[] $actionNames
     * @return ActionInterface[]
     */
    private function selectActions(array $actionNames): array
    {
        $selected = [];
        foreach ($this->actions as $action) {
            if (in_array($action->getName(), $actionNames, true)) {
                $selected[] = $action;
            }
        }

        return $selected;
    }

    public function execute(array $params): array
    {
        $actionName = $params['action'] ?? '';
        $action = $this->actions[$actionName] ?? null;

        if (!$action) {
            return ['error' => 'Unknown action: ' . $actionName];
        }

        $acl = $action->getAclResource();
        if ($acl && !$this->authorization->isAllowed($acl)) {
            return ['error' => 'You do not have permission to access this data'];
        }

        return $action->execute($params, (int)($params['_admin_user_id'] ?? 0));
    }

    public function findRefusal(array $input): ?array
    {
        $action = $this->actions[$input['action'] ?? ''] ?? null;

        return $action instanceof ValidatingActionInterface ? $action->findRefusal($input) : null;
    }

    public function isReadOnly(): bool
    {
        foreach ($this->actions as $action) {
            if (!$action->isReadOnly()) {
                return false;
            }
        }

        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        $actionName = $input['action'] ?? '';
        if ($actionName && isset($this->actions[$actionName])) {
            return $this->actions[$actionName]->isReadOnly();
        }

        return $this->isReadOnly();
    }

    public function isIrreversibleAction(array $input): bool
    {
        return $this->irreversibleAction($input) !== null;
    }

    public function getImpacts(array $input, int $adminUserId): array
    {
        $action = $this->irreversibleAction($input);

        return $action ? array_values($action->getImpacts($input, $adminUserId)) : [];
    }

    /**
     * The action named in $input, when it declares itself irreversible
     */
    private function irreversibleAction(array $input): ?IrreversibleActionInterface
    {
        $action = $this->actions[$input['action'] ?? ''] ?? null;

        return $action instanceof IrreversibleActionInterface ? $action : null;
    }

    public function getInstructions(): string
    {
        $parts = [];
        $base = $this->getBaseInstructions();
        if ($base) {
            $parts[] = $base;
        }
        foreach ($this->actions as $action) {
            $inst = $action->getInstructions();
            if ($inst) {
                $parts[] = '## ' . $action->getName() . "\n" . $inst;
            }
        }
        return implode("\n\n", $parts);
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '';
    }

    protected function getBaseInstructions(): string
    {
        return '';
    }
}
