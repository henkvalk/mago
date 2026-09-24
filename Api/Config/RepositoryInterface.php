<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Config;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Config repository interface
 * @api
 */
interface RepositoryInterface
{
    public const EXTENSION_CODE = 'MagoAssistant_Mago';
    public const XML_PATH_EXTENSION_ENABLE = 'mago/general/enabled';
    public const XML_PATH_DEBUG = 'mago/debug/debug';
    public const XML_PATH_PAYLOAD_RETENTION_DAYS = 'mago/debug/payload_retention_days';
    public const XML_PATH_AI_SERVICE = 'mago/api/ai_service';
    public const XML_PATH_MAX_TOKENS = 'mago/api/max_tokens';
    public const XML_PATH_STREAMING = 'mago/api/streaming';
    public const XML_PATH_SYSTEM_PROMPT = 'mago/chat/system_prompt';
    public const XML_PATH_MAX_TOOL_ITERATIONS = 'mago/chat/max_tool_iterations';
    public const XML_PATH_MAX_RESPONSE_TOKENS = 'mago/tools/max_response_tokens';
    public const XML_PATH_ACCENT_COLOR = 'mago/chat/accent_color';
    public const XML_PATH_TEXT_COLOR = 'mago/chat/text_color';
    public const XML_PATH_ASSISTANT_NAME = 'mago/chat/assistant_name';
    public const XML_PATH_INTERNAL_URL = 'mago/api/internal_url';
    public const XML_PATH_INTERNAL_SSL_VERIFY = 'mago/api/internal_ssl_verify';
    public const XML_PATH_LANGUAGE = 'mago/chat/language';
    public const XML_PATH_ANSWER_WIDGETS = 'mago/chat/answer_widgets';
    public const XML_PATH_ADDONS_ENABLED = 'mago/addons/enabled';
    public const XML_PATH_DOCS_ENABLED = 'mago/docs/enabled';
    public const XML_PATH_DOCS_SOURCE_REPO = 'mago/docs/source_repo';
    public const XML_PATH_DOCS_REF = 'mago/docs/ref';
    public const XML_PATH_DOCS_TOP_K = 'mago/docs/top_k';
    /**
     * @return string
     */
    public function getExtensionVersion(): string;

    /**
     * @return string
     */
    public function getExtensionCode(): string;

    /**
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * @param int|null $storeId
     * @return StoreInterface
     */
    public function getStore(?int $storeId = null): StoreInterface;

    /**
     * @return string
     */
    public function getSupportLink(): string;

    /**
     * @return bool
     */
    public function isDebugEnabled(): bool;

    /**
     * @return int
     */
    public function getPayloadRetentionDays(): int;

    /**
     * Row id of the MageOS_AiBase service the assistant runs on.
     *
     * Empty means "whichever service is usable first", which is what a single-provider store wants
     * and what a fresh install has. Credentials, model and endpoint all live on that row, under
     * Stores > Configuration > Mage-OS > AI Configuration.
     *
     * @return string
     */
    public function getAiServiceId(): string;

    /**
     * @return int
     */
    public function getMaxTokens(): int;

    /**
     * @return bool
     */
    public function isStreamingEnabled(): bool;

    /**
     * @return string
     */
    public function getSystemPrompt(): string;

    /**
     * @return int
     */
    public function getMaxToolIterations(): int;

    /**
     * @return int
     */
    public function getMaxResponseTokens(): int;

    /**
     * @return string
     */
    public function getAccentColor(): string;

    /**
     * @return string
     */
    public function getTextColor(): string;

    /**
     * @return string
     */
    public function getAssistantName(): string;

    /**
     * @return string
     */
    public function getLanguage(): string;

    /**
     * Whether the assistant may answer with the chat panel's widgets (```mago blocks)
     *
     * @return bool
     */
    public function isAnswerWidgetsEnabled(): bool;

    /**
     * @return string
     */
    public function getInternalUrl(): string;

    /**
     * @return bool
     */
    public function isInternalSslVerifyEnabled(): bool;

    /**
     * Whether the dashboard may show the add-on feed
     *
     * @return bool
     */
    public function isAddonFeedEnabled(): bool;

    /**
     * @return bool
     */
    public function isDocsEnabled(): bool;

    /**
     * @return string
     */
    public function getDocsSourceRepo(): string;

    /**
     * @return string
     */
    public function getDocsRef(): string;

    /**
     * @return int
     */
    public function getDocsTopK(): int;
}
