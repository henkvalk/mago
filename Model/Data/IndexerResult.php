<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use MagoAssistant\Mago\Api\Data\IndexerResultInterface;

class IndexerResult extends AbstractSimpleObject implements IndexerResultInterface
{
    public function getId(): string
    {
        return $this->_get(self::ID);
    }

    public function setId(string $id): void
    {
        $this->setData(self::ID, $id);
    }

    public function getTitle(): string
    {
        return $this->_get(self::TITLE);
    }

    public function setTitle(string $title): void
    {
        $this->setData(self::TITLE, $title);
    }

    public function getResult(): string
    {
        return $this->_get(self::RESULT);
    }

    public function setResult(string $result): void
    {
        $this->setData(self::RESULT, $result);
    }
}
