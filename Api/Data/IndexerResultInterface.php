<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Data;

/**
 * @api
 */
interface IndexerResultInterface
{
    public const ID = 'id';
    public const TITLE = 'title';
    public const RESULT = 'result';

    /**
     * @return string
     */
    public function getId(): string;

    /**
     * @param string $id
     * @return void
     */
    public function setId(string $id): void;

    /**
     * @return string
     */
    public function getTitle(): string;

    /**
     * @param string $title
     * @return void
     */
    public function setTitle(string $title): void;

    /**
     * @return string
     */
    public function getResult(): string;

    /**
     * @param string $result
     * @return void
     */
    public function setResult(string $result): void;
}
