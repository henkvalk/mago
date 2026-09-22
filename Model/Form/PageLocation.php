<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Form;

/**
 * The identity of an admin page as far as the conversation is concerned: which form, which
 * entity, which store scope. Stored next to every user message so a later turn can tell whether
 * the administrator is still looking at the same page the earlier turns were about.
 */
final readonly class PageLocation
{
    private const KEY_ROUTE = 'route';
    private const KEY_NAMESPACE = 'namespace';
    private const KEY_ENTITY_TYPE = 'entity_type';
    private const KEY_ENTITY_ID = 'entity_id';
    private const KEY_IS_NEW_ENTITY = 'is_new_entity';
    private const KEY_STORE_ID = 'store_id';

    public function __construct(
        public string $route,
        public string $namespace,
        public string $entityType,
        public string $entityId,
        public bool $isNewEntity,
        public ?string $storeId
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $route = $data[self::KEY_ROUTE] ?? null;
        $namespace = $data[self::KEY_NAMESPACE] ?? null;
        $entityType = $data[self::KEY_ENTITY_TYPE] ?? null;

        if (!is_string($route) || !is_string($namespace) || !is_string($entityType)) {
            return null;
        }

        $storeId = $data[self::KEY_STORE_ID] ?? null;

        return new self(
            route: $route,
            namespace: $namespace,
            entityType: $entityType,
            entityId: (string)($data[self::KEY_ENTITY_ID] ?? ''),
            isNewEntity: (bool)($data[self::KEY_IS_NEW_ENTITY] ?? false),
            storeId: is_scalar($storeId) ? (string)$storeId : null
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            self::KEY_ROUTE => $this->route,
            self::KEY_NAMESPACE => $this->namespace,
            self::KEY_ENTITY_TYPE => $this->entityType,
            self::KEY_ENTITY_ID => $this->entityId,
            self::KEY_IS_NEW_ENTITY => $this->isNewEntity,
            self::KEY_STORE_ID => $this->storeId,
        ];
    }

    /**
     * Two locations are the same page when they name the same form, entity and store scope. The
     * route is deliberately left out: it is the raw pathname, which Magento decorates differently
     * on the same page (the secret key, and set/type/store/back after Save & Continue), and a
     * Save must not read as a navigation.
     */
    public function equals(?self $other): bool
    {
        return $other !== null && $this->identity() === $other->identity();
    }

    /**
     * @return array<string,mixed>
     */
    private function identity(): array
    {
        return [
            self::KEY_NAMESPACE => $this->namespace,
            self::KEY_ENTITY_TYPE => $this->entityType,
            self::KEY_ENTITY_ID => $this->entityId,
            self::KEY_IS_NEW_ENTITY => $this->isNewEntity,
            self::KEY_STORE_ID => $this->storeId,
        ];
    }

    public function entityDescriptor(): string
    {
        return $this->isNewEntity
            ? 'a new ' . $this->entityType
            : sprintf('%s #%s', $this->entityType, $this->entityId);
    }

    public function storeDescriptor(): string
    {
        return $this->storeId !== null ? sprintf(' in store scope %s', $this->storeId) : '';
    }

    public function describe(): string
    {
        return sprintf('%s (%s)%s', $this->entityDescriptor(), $this->route, $this->storeDescriptor());
    }
}
