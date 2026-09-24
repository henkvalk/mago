<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config;

use Magento\Config\Model\ResourceModel\Config as ConfigData;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;

class Repository extends System\BaseRepository implements ConfigRepositoryInterface
{
    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        ConfigDataCollectionFactory $configDataCollectionFactory,
        ConfigData $config,
        Json $json,
        ProductMetadataInterface $metadata,
        EncryptorInterface $encryptor,
        ResourceConnection $resourceConnection,
        ScopeCodeResolver $scopeCodeResolver,
        DateTime $dateTime,
        ComponentRegistrarInterface $componentRegistrar,
        FileDriver $fileDriver,
        private readonly SystemPromptBuilder $systemPromptBuilder
    ) {
        parent::__construct(
            $storeManager,
            $scopeConfig,
            $configDataCollectionFactory,
            $config,
            $json,
            $metadata,
            $encryptor,
            $resourceConnection,
            $scopeCodeResolver,
            $dateTime,
            $componentRegistrar,
            $fileDriver
        );
    }

    public function getExtensionVersion(): string
    {
        return 'v' . ($this->getComposerData()['version'] ?? '0.0.0');
    }

    public function getMagentoVersion(): string
    {
        return $this->metadata->getVersion();
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_EXTENSION_ENABLE, $storeId);
    }

    public function getSupportLink(): string
    {
        return '';
    }

    public function getExtensionCode(): string
    {
        return self::EXTENSION_CODE;
    }

    public function isDebugEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_DEBUG);
    }

    public function getPayloadRetentionDays(): int
    {
        // Non-numeric/negative config falls back to 30 rather than failing open to keep-forever
        $value = $this->getStoreValue(self::XML_PATH_PAYLOAD_RETENTION_DAYS);
        return ctype_digit($value) ? (int)$value : 30;
    }

    public function getAiServiceId(): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_AI_SERVICE));
    }

    public function getMaxTokens(): int
    {
        return (int)($this->getStoreValue(self::XML_PATH_MAX_TOKENS) ?: 4096);
    }

    public function isStreamingEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_STREAMING);
    }

    public function getSystemPrompt(): string
    {
        $custom = $this->getStoreValue(self::XML_PATH_SYSTEM_PROMPT);
        $base = $this->systemPromptBuilder->build($this->getLanguage(), date('Y-m-d'));

        return $custom ? $base . "\n\n" . $custom : $base;
    }

    public function getMaxToolIterations(): int
    {
        return (int)($this->getStoreValue(self::XML_PATH_MAX_TOOL_ITERATIONS) ?: 10);
    }

    public function getMaxResponseTokens(): int
    {
        return (int)($this->getStoreValue(self::XML_PATH_MAX_RESPONSE_TOKENS) ?: 4000);
    }

    public function getAccentColor(): string
    {
        return $this->getStoreValue(self::XML_PATH_ACCENT_COLOR) ?: '#F26322';
    }

    public function getTextColor(): string
    {
        return $this->getStoreValue(self::XML_PATH_TEXT_COLOR) ?: '#FFFFFF';
    }

    public function getAssistantName(): string
    {
        return $this->getStoreValue(self::XML_PATH_ASSISTANT_NAME) ?: 'Mago';
    }

    public function getLanguage(): string
    {
        return $this->getStoreValue(self::XML_PATH_LANGUAGE) ?: 'auto';
    }

    public function isAnswerWidgetsEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_ANSWER_WIDGETS);
    }

    public function getInternalUrl(): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_INTERNAL_URL));
    }

    public function isInternalSslVerifyEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_INTERNAL_SSL_VERIFY);
    }

    public function isAddonFeedEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_ADDONS_ENABLED, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function isDocsEnabled(): bool
    {
        return $this->isSetFlag(self::XML_PATH_DOCS_ENABLED, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function getDocsSourceRepo(): string
    {
        $value = trim($this->getStoreValue(self::XML_PATH_DOCS_SOURCE_REPO, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
        return $value !== '' ? $value : 'mage-os/mirror-commerce-admin.en';
    }

    public function getDocsRef(): string
    {
        $value = trim($this->getStoreValue(self::XML_PATH_DOCS_REF, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
        return $value !== '' ? $value : 'main';
    }

    public function getDocsTopK(): int
    {
        return (int)($this->getStoreValue(self::XML_PATH_DOCS_TOP_K, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT) ?: 5);
    }
}
