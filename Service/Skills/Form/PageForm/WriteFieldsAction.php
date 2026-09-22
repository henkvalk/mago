<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Block\Adminhtml\ChatPanel;
use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\NewEntityUrlBuilder;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Stages one or more field changes on the admin form currently open in the browser and hands the
 * browser a form_write directive (the client_directive channel from task 005) so it can apply them
 * once the administrator confirms. This action never saves, persists or reloads anything itself: it
 * only validates the requested changes against the snapshot of the open form and describes them.
 * Saving remains the administrator's own action, through the form's normal Save button.
 *
 * isReadOnly() is false, so AbstractSkill routes every call through the confirmation flow before
 * execute() ever runs; the moment it runs is the moment the administrator has already agreed.
 *
 * form_namespace, entity_id and store_id are required parameters rather than values this action
 * derives itself, because on the confirm request the page context holder holds whatever the browser
 * resent for the page the administrator is on right now, which is not necessarily the page the model
 * had in mind when it proposed the write. Requiring the model to copy them from a prior describe_form
 * call, and refusing when they no longer match, is what catches a navigation between proposal and
 * confirmation instead of silently writing to the wrong page.
 *
 * When no form is open at all, an entity_type naming a known, navigable entity (see EntityRouteMap)
 * turns this into navigate-then-act (task 009) instead of a plain refusal: the browser is handed a
 * form_navigate directive carrying the entity's own admin URL (built through SecureAdminUrl, so the
 * admin secret key is respected) and the raw changes, and stages them itself once it has navigated
 * there and the target form has loaded. The changes cannot be validated against a snapshot here,
 * because no form describing that entity is open yet; the browser re-validates the target the same
 * way it does for an ordinary form_write directive (task 008's guard) once it arrives.
 *
 * The same navigate-then-act path also covers two more cases. An empty entity_id with a known,
 * creatable entity_type means "create one": the browser is sent to that entity's own New form
 * (NewEntityUrlBuilder) and stages the values there, so "add a product to this category" works
 * from the category form instead of ending in a list of manual steps. And a form being open does
 * not pin the assistant to it: an entity_type naming a different entity (or a different id of the
 * same type) than the form on screen navigates there too, rather than refusing as a stale target.
 * The stale-target refusal (findTargetMismatch) is kept for a call that names no entity_type at
 * all, since that is the shape of a write the model proposed against a form that has since changed.
 *
 * FormPolicy is checked against that same navigation target, before a directive is ever built, so
 * navigate-then-act cannot send the administrator's browser to a form the feature excludes wholesale
 * (customer, order, admin user, ...) just because no form happened to be open yet to deny. Denying
 * only once a form_write directive's target no longer matches an open, already-denied form would
 * leave this path uncovered entirely, since there is no open form here to check in the first place.
 */
class WriteFieldsAction extends AbstractPageFormAction implements ValidatingActionInterface
{
    private const MAX_CHANGES = 50;
    private const DIRECTIVE_TYPE_FORM_NAVIGATE = 'form_navigate';
    private const PARAM_ENTITY_TYPE = 'entity_type';
    private const PARAM_ENTITY_ID = 'entity_id';
    private const PARAM_STORE_ID = 'store_id';

    public function __construct(
        PageContextHolder $pageContextHolder,
        private readonly EntityRouteMap $entityRouteMap,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly NewEntityUrlBuilder $newEntityUrlBuilder,
        private readonly FormPolicy $formPolicy
    ) {
        parent::__construct($pageContextHolder);
    }

    public function getName(): string
    {
        return 'write_fields';
    }

    public function getDescription(): string
    {
        return 'Stage one or more field changes on an admin form, for the administrator to review and '
            . 'confirm before anything is written. For the form currently open: copy form_namespace, '
            . 'entity_id and store_id from a prior describe_form call; the write is refused if they no '
            . 'longer match the form now open. For a different entity, or one that does not exist yet: '
            . 'pass entity_type (and entity_id, or an empty entity_id to create a new one) and the '
            . 'browser navigates to that form, the New form for a new entity, and stages the values '
            . 'there. Use this to create a product, category, CMS page or CMS block from the chat. '
            . 'Does not save the form.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getParameterSchema(): array
    {
        return [
            'form_namespace' => [
                'type' => 'string',
                'description' => 'The "namespace" value returned by describe_form for this form.',
            ],
            'entity_id' => [
                'type' => 'string',
                'description' => 'The "entity_id" value returned by describe_form for this form '
                    . '(an empty string for a new, unsaved entity). Together with entity_type, an '
                    . 'empty entity_id means: navigate to the New form for that entity type and '
                    . 'create one.',
            ],
            'store_id' => [
                'type' => 'string',
                'description' => 'The "store_id" value returned by describe_form for this form '
                    . '(an empty string for the default scope).',
            ],
            'changes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                    ],
                    'required' => ['path', 'value'],
                ],
                'description' => 'One or more field changes to stage, each naming a field path '
                    . 'returned by describe_form and the new value for it. Pass select and toggle values '
                    . 'as the option value, not the label; dates as YYYY-MM-DD; multi-select fields '
                    . '(category_ids, website_ids) as comma-separated ids. On a New Product form '
                    . 'the main paths are data.product.name, data.product.sku, data.product.price, '
                    . 'data.product.quantity_and_stock_status.qty, data.product.category_ids, '
                    . 'data.product.url_key and data.product.visibility; call describe_form once the '
                    . 'form has opened for the rest.',
            ],
            'entity_type' => [
                'type' => 'string',
                'description' => 'The type of entity to write to (e.g. "product", "category", '
                    . '"cms_page", "cms_block") when it is not the form open right now, or when no '
                    . 'form is open. The browser navigates to that entity (or to its New form when '
                    . 'entity_id is empty) and stages the changes once it has loaded.',
            ],
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        $pageContext = $this->getPageContext();
        if ($pageContext === null || $this->isRequestForAnotherForm($params, $pageContext)) {
            return $this->navigate($params);
        }

        return $this->stage($this->normalizeChanges($params['changes'] ?? null), $pageContext);
    }

    /**
     * Every reason this action would refuse, decided from the proposed input and the page context
     * alone, so ChatService can answer with it before asking the administrator to confirm.
     */
    public function findRefusal(array $params): ?array
    {
        $pageContext = $this->getPageContext();
        if ($pageContext === null) {
            return $this->findNavigationRefusal($params, $this->routeFor($params) === null);
        }

        if ($this->isRequestForAnotherForm($params, $pageContext)) {
            return $this->findNavigationRefusal($params, false);
        }

        $targetMismatch = $this->findTargetMismatch($params, $pageContext);
        if ($targetMismatch !== null) {
            return ['error' => $targetMismatch];
        }

        $changes = $this->normalizeChanges($params['changes'] ?? null);
        if ($changes === []) {
            return $this->missingChangesResult();
        }

        $refusal = $this->findRefusedChange($changes, $pageContext);

        return $refusal !== null ? ['error' => $refusal] : null;
    }

    private function findNavigationRefusal(array $params, bool $hasNoRoute): ?array
    {
        if ($hasNoRoute) {
            return $this->noFormOpenResult();
        }

        $route = $this->routeFor($params);
        if ($route === null || $this->isDeniedNavigationTarget($this->entityTypeParam($params), $route)) {
            return $this->deniedFormResult();
        }

        return $this->normalizeChanges($params['changes'] ?? null) === [] ? $this->missingChangesResult() : null;
    }

    private function missingChangesResult(): array
    {
        return ['error' => 'changes parameter is required, with at least one {path, value} entry'];
    }

    /**
     * True when the call names a navigable entity other than the one on screen: another type, another
     * id of the same type, or (an empty id) a new one while an existing one is open. A call that
     * names no entity_type, or an unknown one, is judged against the open form as before.
     */
    private function isRequestForAnotherForm(array $params, PageContext $pageContext): bool
    {
        if ($this->routeFor($params) === null) {
            return false;
        }

        return $this->entityTypeParam($params) !== $pageContext->entityType
            || $this->entityIdParam($params) !== $pageContext->entityId;
    }

    private function routeFor(array $params): ?string
    {
        $entityType = $this->entityTypeParam($params);
        if ($entityType === '') {
            return null;
        }

        return $this->entityIdParam($params) === ''
            ? $this->entityRouteMap->getNewRoute($entityType)
            : $this->entityRouteMap->getRoute($entityType);
    }

    private function entityTypeParam(array $params): string
    {
        return trim((string)($params[self::PARAM_ENTITY_TYPE] ?? ''));
    }

    private function entityIdParam(array $params): string
    {
        return trim((string)($params[self::PARAM_ENTITY_ID] ?? ''));
    }

    private function storeIdParam(array $params): string
    {
        return trim((string)($params[self::PARAM_STORE_ID] ?? ''));
    }

    /**
     * Only reached once findRefusal() found nothing, so the route exists and is allowed.
     */
    private function navigate(array $params): array
    {
        $changes = $this->normalizeChanges($params['changes'] ?? null);
        $isNew = $this->entityIdParam($params) === '';

        return [
            'navigating' => true,
            'creating' => $isNew,
            'field_count' => count($changes),
            'client_directive' => $this->buildNavigateDirective(
                $this->entityTypeParam($params),
                (string)$this->routeFor($params),
                $params,
                $changes
            ),
        ];
    }

    /**
     * Checked before a form_navigate directive is ever built, against the same FormPolicy the
     * open-form path already enforces (PageContextNormalizer, form-bridge.js), rather than a second
     * list or a second notion of what "denied" means. Two independent inputs are checked, because
     * either alone misses one of today's two denied, navigable entity types:
     *
     * - The route ("customer/index/edit", "sales/order/view", ...) catches "customer" and "order"
     *   directly: both already match a DENIED_ROUTE_PATTERNS substring today.
     * - The entity type's own form namespace, by the "<entity_type>_form" convention every navigable
     *   form already registers under (see form-bridge.js's applyStoredNavigateIntent(), which builds
     *   the same string to replay a stored navigate intent), catches a namespace-only denial that no
     *   route pattern would. "cms_block" is a navigable entity type whose route ("cms/block/edit")
     *   matches no denied route pattern, so if an integrator denies "cms_block_form" through di.xml's
     *   additionalDeniedNamespacePatterns (the commented example there shows the shape), checking the
     *   route alone would silently let navigate-then-act bypass that denial.
     *
     * Checking both, through FormPolicy::isDenied() itself, is what keeps this correct without
     * editing whenever a pattern is added via di.xml's additionalDeniedNamespacePatterns or
     * additionalDeniedRoutePatterns - a new pattern is denied here the same instant it is denied
     * everywhere else, because there is only ever the one list.
     */
    private function isDeniedNavigationTarget(string $entityType, string $route): bool
    {
        return $this->formPolicy->isDenied($entityType . '_form', $route);
    }

    /**
     * Builds the form_navigate directive (task 009): the entity's own admin URL (or its New form's,
     * for an empty entity id), built through SecureAdminUrl so the admin secret key is respected,
     * plus the raw path/value changes for the browser to stage once it has navigated there and the
     * target form has loaded. is_new is what lets the browser accept the landed New form as the
     * intended target, which an empty entity id alone never does (form-bridge.js isSameTarget()).
     *
     * @param array<int,array{path:string,value:string}> $changes
     * @return array<string,mixed>
     */
    private function buildNavigateDirective(string $entityType, string $route, array $params, array $changes): array
    {
        $entityId = $this->entityIdParam($params);
        $storeId = $this->storeIdParam($params);

        return [
            'type' => self::DIRECTIVE_TYPE_FORM_NAVIGATE,
            'target' => [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'is_new' => $entityId === '',
            ],
            'url' => $entityId === ''
                ? $this->newEntityUrlBuilder->build($entityType, $storeId)
                : $this->secureAdminUrl->getUrl($route, $this->existingEntityUrlParams($entityType, $entityId, $storeId)),
            'changes' => array_map(
                static fn (array $change): array => ['path' => $change['path'], 'value' => $change['value']],
                $changes
            ),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function existingEntityUrlParams(string $entityType, string $entityId, string $storeId): array
    {
        $urlParams = [(string)$this->entityRouteMap->getParamKey($entityType) => $entityId];
        if ($storeId !== '') {
            $urlParams['store'] = $storeId;
        }

        return $urlParams;
    }

    private function findTargetMismatch(array $params, PageContext $pageContext): ?string
    {
        $namespace = (string)($params['form_namespace'] ?? '');
        $entityId = (string)($params['entity_id'] ?? '');
        $storeId = (string)($params['store_id'] ?? '');
        $currentStoreId = $pageContext->storeId ?? '';

        $matches = $namespace === $pageContext->namespace
            && $entityId === $pageContext->entityId
            && $storeId === $currentStoreId;

        return $matches
            ? null
            : 'form_namespace, entity_id or store_id no longer matches the form currently open in the '
                . 'browser. Call describe_form again to get the current target before retrying.';
    }

    /**
     * @return array<int,array{path:string,value:string}>
     */
    private function normalizeChanges(mixed $rawChanges): array
    {
        if (!is_array($rawChanges)) {
            return [];
        }

        $changes = [];
        foreach (array_slice($rawChanges, 0, self::MAX_CHANGES) as $rawChange) {
            $change = $this->normalizeChange($rawChange);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * @return array{path:string,value:string}|null
     */
    private function normalizeChange(mixed $rawChange): ?array
    {
        if (!is_array($rawChange)
            || !isset($rawChange['path'], $rawChange['value'])
            || !is_string($rawChange['path'])
            || !is_scalar($rawChange['value'])
        ) {
            return null;
        }

        return ['path' => $rawChange['path'], 'value' => $this->capValue((string)$rawChange['value'])];
    }

    /**
     * A value longer than the field snapshot itself is allowed to carry is a sign something went
     * wrong upstream (a hallucinated value, or a model echoing back far more than it was asked for),
     * so it is truncated to the same cap the snapshot already enforces rather than passed through.
     */
    private function capValue(string $value): string
    {
        return mb_strlen($value) > ChatPanel::FORM_VALUE_LENGTH_CAP
            ? mb_substr($value, 0, ChatPanel::FORM_VALUE_LENGTH_CAP)
            : $value;
    }

    /**
     * @param array<int,array{path:string,value:string}> $changes
     */
    private function findRefusedChange(array $changes, PageContext $pageContext): ?string
    {
        foreach ($changes as $change) {
            $field = $this->findField($pageContext, $change['path']);
            if ($field === null) {
                return '"' . $change['path'] . '" is not a field on this form. Call describe_form to '
                    . 'see the available field paths.';
            }
            if ($field['disabled']) {
                return '"' . $change['path'] . '" is disabled on this form and cannot be written to.';
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findField(PageContext $pageContext, string $path): ?array
    {
        foreach ($pageContext->fields as $field) {
            if ($field['path'] === $path) {
                return $field;
            }
        }

        return null;
    }

    /**
     * is_new is what lets the browser accept the directive on a new, unsaved form, whose empty
     * entity id its target check otherwise never matches (form-bridge.js isSameTarget()). The
     * confirm request has already re-sent the page context and passed findTargetMismatch() by the
     * time this runs, so the form on screen is the one the write was proposed against as far as
     * the server can tell; the browser applies its own check once more on arrival.
     *
     * @param array<int,array{path:string,value:string}> $changes
     */
    private function stage(array $changes, PageContext $pageContext): array
    {
        return [
            'staged' => true,
            'field_count' => count($changes),
            'client_directive' => [
                'type' => 'form_write',
                'target' => [
                    'namespace' => $pageContext->namespace,
                    'entity_type' => $pageContext->entityType,
                    'entity_id' => $pageContext->entityId,
                    'store_id' => $pageContext->storeId ?? '',
                    'is_new' => $pageContext->isNewEntity,
                ],
                'changes' => array_map(
                    fn (array $change): array => $this->toDirectiveChange($change, $pageContext),
                    $changes
                ),
            ],
        ];
    }

    /**
     * A field only carries "Use Default Value" ticked when the snapshot it was described from
     * reported it (task 002's usesDefaultValue), which itself is only ever true for a field whose
     * attribute scope is not global. Staging a new value there without clearing that box would
     * silently revert to the default again on Save, which is the whole reason task 010 exists.
     *
     * @param array{path:string,value:string} $change
     */
    private function toDirectiveChange(array $change, PageContext $pageContext): array
    {
        $field = $this->findField($pageContext, $change['path']);

        return [
            'path' => $change['path'],
            'label' => $field['label'] ?? '',
            'previous_value' => $field['value'] ?? null,
            'value' => $change['value'],
            'clear_use_default' => (bool)($field['usesDefaultValue'] ?? false),
        ];
    }
}
