<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Higgsfield;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;

/**
 * Stores the OAuth client and tokens of the Higgsfield connection in the flag table, so a refreshed
 * token is visible at once without a config cache flush. Secrets and tokens are encrypted.
 */
class TokenStore
{
    private const FLAG_CODE = 'mago_higgsfield_oauth';

    /** Keys whose values are encrypted at rest. */
    private const ENCRYPTED = ['client_secret', 'access_token', 'refresh_token'];

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Stored connection data with secrets decrypted.
     *
     * @return array<string,mixed>
     */
    public function load(): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        if (!is_array($data)) {
            return [];
        }

        foreach (self::ENCRYPTED as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $data[$key] = $this->encryptor->decrypt($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Merges values into the stored data; a null value removes the key.
     *
     * @param array<string,mixed> $values
     * @return void
     */
    public function save(array $values): void
    {
        $data = $this->load();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
                continue;
            }
            $data[$key] = $value;
        }

        foreach (self::ENCRYPTED as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $data[$key] = $this->encryptor->encrypt($data[$key]);
            }
        }

        $this->flagManager->saveFlag(self::FLAG_CODE, $data);
    }

    public function clear(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_CODE);
    }
}
