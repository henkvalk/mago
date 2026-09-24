<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Service\Command\CommandRegistry;
use MagoAssistant\Mago\Service\Command\CommandRunner;
use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

class ChatPanel extends Template
{
    /**
     * Maximum number of fields the form bridge includes in a single page snapshot.
     *
     * A stock Magento product form registers 204 field components, so the previous 200 truncated
     * every product page by a handful of fields and dropped whichever happened to register last.
     * FORM_BYTE_CAP is what actually bounds the payload; this only needs enough headroom that a
     * normal form is described in full.
     */
    public const FORM_FIELD_CAP = 600;

    /**
     * Maximum character length of a single field's value in a page snapshot.
     */
    public const FORM_VALUE_LENGTH_CAP = 500;

    /**
     * Maximum number of options a single select/multiselect field contributes to a snapshot.
     */
    public const FORM_OPTION_CAP = 50;

    /**
     * Maximum serialized byte size of a page snapshot, kept well under typical proxy POST limits.
     */
    public const FORM_BYTE_CAP = 200000;

    protected $_template = 'MagoAssistant_Mago::chat/panel.phtml';

    public function __construct(
        Context $context,
        private readonly ConfigRepository $configRepository,
        private readonly AdminSession $adminSession,
        private readonly Json $json,
        private readonly ToolRegistry $toolRegistry,
        private readonly CommandRegistry $commandRegistry,
        private readonly CommandRunner $commandRunner,
        private readonly FormPolicy $formPolicy,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isVisible(): bool
    {
        if (!$this->configRepository->isEnabled()) {
            return false;
        }

        // Only show when admin user is logged in
        return $this->adminSession->isLoggedIn();
    }

    /**
     * Admin root (scheme, host and area front name, no route or secret key); the panel uses it to
     * tell admin links apart from storefront links when it tags them with the conversation id.
     */
    private function getAdminBaseUrl(): string
    {
        return rtrim($this->_urlBuilder->getBaseUrl(), '/') . '/' . $this->_urlBuilder->getAreaFrontName() . '/';
    }

    public function getJsConfig(): string
    {
        return (string)$this->json->serialize([
            'streamUrl' => $this->getUrl('mago/chat/stream'),
            'historyUrl' => $this->getUrl('mago/chat/history'),
            'loadUrl' => $this->getUrl('mago/chat/load'),
            'deleteUrl' => $this->getUrl('mago/chat/delete'),
            'confirmUrl' => $this->getUrl('mago/chat/confirm'),
            'rejectUrl' => $this->getUrl('mago/chat/reject'),
            'statusUrl' => $this->getUrl('mago/chat/status'),
            'apiBaseUrl' => $this->getUrl('rest/V1/assistant'),
            'adminBaseUrl' => $this->getAdminBaseUrl(),
            'isStreamingEnabled' => $this->configRepository->isStreamingEnabled(),
            'formFieldCap' => self::FORM_FIELD_CAP,
            'formValueLengthCap' => self::FORM_VALUE_LENGTH_CAP,
            'formOptionCap' => self::FORM_OPTION_CAP,
            'formByteCap' => self::FORM_BYTE_CAP,
            'formDenyNamespaces' => $this->formPolicy->getDeniedNamespacePatterns(),
            'formDenyRoutes' => $this->formPolicy->getDeniedRoutePatterns(),
            'i18n' => $this->getPanelTranslations(),
        ]);
    }

    /**
     * Every sentence chat-panel.js shows the administrator, keyed by its English source so the
     * script reads naturally and a translation pack can supply the rest through the usual
     * i18n csv files. %1, %2 are the placeholders chat-panel.js substitutes.
     *
     * @return array<string,string>
     */
    private function getPanelTranslations(): array
    {
        $sentences = [
            'I want to perform the following action:',
            'I want to perform an action. Allow this?',
            'Allow this?',
            'Confirm',
            'Reject',
            '%1 field',
            '%1 fields',
            'Stage %1 on %2:',
            'Open %1 and stage %2 there:',
            'the form on screen',
            'store view %1',
            'a new %1',
            'a new, unsaved %1',
            '%1 #%2',
            'You will leave this page.',
            'Unsaved edits on %1 will be lost.',
            'Nothing is saved until you click Save on the page.',
            '...and %1 more field.',
            '...and %1 more fields.',
            '(hidden)',
            'Staged %1 of %2 %3. Not saved yet: click Save on the page to keep %4.',
            'this change',
            'these changes',
            'Could not set: %1.',
            'Could not stage %1. Nothing was changed.',
            'That change was meant for %1, but a different form is open now. Nothing was changed. Go back to that page and ask me again.',
            'Navigated to %1, but its form did not load in time. Nothing was changed. Ask me again now that the page is open.',
            'Opening %1...',
            'Waiting for the form on %1...',
            'Action rejected. No changes were made.',
            'CMS page',
            'CMS block',
        ];

        return array_combine($sentences, array_map(static fn (string $sentence): string => (string)__($sentence), $sentences));
    }

    public function getStreamUrl(): string
    {
        return $this->getUrl('mago/chat/stream');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Skills for the slash-command legend: only those the current admin may invoke,
     * described as that admin sees them (write actions omitted for a read-only grant).
     */
    public function getSkillsJson(): string
    {
        $adminUserId = $this->getAdminUserId();
        $skills = [];
        foreach ($this->toolRegistry->getEnabledTools($adminUserId) as $tool) {
            $definition = $this->toolRegistry->getToolDefinition($tool, $adminUserId);
            $skills[] = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'readOnly' => $tool->isReadOnly() || !$this->toolRegistry->hasWriteAccess($tool, $adminUserId),
            ];
        }
        return (string)$this->json->serialize($skills);
    }

    /**
     * Slash commands for the menu: only the subcommands the current admin may run.
     */
    public function getCommandsJson(): string
    {
        $adminUserId = $this->getAdminUserId();
        $commands = [];
        foreach ($this->commandRegistry->getAvailable($adminUserId) as $command) {
            $subcommands = [];
            foreach ($this->commandRunner->getAvailableSubcommands($command, $adminUserId) as $name => $definition) {
                $subcommands[] = [
                    'name' => $name,
                    'args' => $definition['args'],
                    'description' => $definition['description'],
                    'readOnly' => $definition['readOnly'],
                ];
            }
            if ($subcommands === []) {
                continue;
            }
            $commands[] = [
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'subcommands' => $subcommands,
            ];
        }
        return (string)$this->json->serialize($commands);
    }

    private function getAdminUserId(): ?int
    {
        $user = $this->adminSession->getUser();
        return $user && $user->getId() ? (int)$user->getId() : null;
    }

    public function getAdminFirstName(): string
    {
        $user = $this->adminSession->getUser();
        if (!$user) {
            return '';
        }
        return $user->getFirstName() ?: $user->getUserName();
    }

    public function getAccentColor(): string
    {
        return $this->configRepository->getAccentColor();
    }

    public function getTextColor(): string
    {
        return $this->configRepository->getTextColor();
    }

    public function getAssistantName(): string
    {
        return $this->configRepository->getAssistantName();
    }
}
