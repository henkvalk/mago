<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

/**
 * The one place that knows which admin forms are off limits to the assistant because they carry
 * personal data: customer, customer address, order and admin user forms. PageContextNormalizer
 * enforces this on the server, form-bridge.js enforces the same list in the browser (published
 * through Block\Adminhtml\ChatPanel::getJsConfig()), and neither keeps its own copy of the
 * patterns - this is the only list.
 *
 * A form is denied by namespace or by route, because either alone has gaps: a namespace catches a
 * form wherever it is reached from, a route catches a form this list has not learned the namespace
 * of yet. Either pattern is a substring match rather than an exact one, so a route pattern like
 * "customer/" also denies "customer/address/edit", and a future sub-form under an existing
 * namespace stays denied without a new entry.
 *
 * $additionalDeniedNamespacePatterns and $additionalDeniedRoutePatterns extend, rather than
 * replace, the defaults below - the same shape PageRegistry's additionalPages argument already
 * uses - so an integrator can widen the deny list from di.xml without touching this class.
 */
class FormPolicy
{
    private const DENIED_NAMESPACE_PATTERNS = [
        'customer_form',
        'customer_address_form',
        'sales_order_view',
        'sales_order_create',
        'admin_user_form',
        'newsletter_subscriber_form',
    ];

    private const DENIED_ROUTE_PATTERNS = [
        'customer/',
        'sales/order',
        'admin/user',
    ];

    /**
     * @var array<int,string>
     */
    private readonly array $deniedNamespacePatterns;

    /**
     * @var array<int,string>
     */
    private readonly array $deniedRoutePatterns;

    /**
     * @param array<int,string> $additionalDeniedNamespacePatterns
     * @param array<int,string> $additionalDeniedRoutePatterns
     */
    public function __construct(
        array $additionalDeniedNamespacePatterns = [],
        array $additionalDeniedRoutePatterns = []
    ) {
        // array_values() re-indexes as a plain list: di.xml array items carry their own string
        // keys (e.g. "cms_block_form"), and array_merge() alone would keep those keys, turning
        // the JSON Block\Adminhtml\ChatPanel::getJsConfig() publishes into an object instead of
        // the array form-bridge.js expects to call .some()/.indexOf() on.
        $this->deniedNamespacePatterns = array_values(array_merge(self::DENIED_NAMESPACE_PATTERNS, $additionalDeniedNamespacePatterns));
        $this->deniedRoutePatterns = array_values(array_merge(self::DENIED_ROUTE_PATTERNS, $additionalDeniedRoutePatterns));
    }

    /**
     * Denies by default: an unknown, customer-shaped namespace or route (one neither list happens
     * to name) is treated as allowed here on purpose, since this method only ever sees a match
     * against the two explicit lists above - the "default to denying an unknown customer-shaped
     * form" rule is a data problem (keeping the lists complete), not a decision this method makes.
     */
    public function isDenied(string $namespace, string $route): bool
    {
        return $this->matchesAnyPattern($namespace, $this->deniedNamespacePatterns)
            || $this->matchesAnyPattern($route, $this->deniedRoutePatterns);
    }

    /**
     * @return array<int,string>
     */
    public function getDeniedNamespacePatterns(): array
    {
        return $this->deniedNamespacePatterns;
    }

    /**
     * @return array<int,string>
     */
    public function getDeniedRoutePatterns(): array
    {
        return $this->deniedRoutePatterns;
    }

    /**
     * @param array<int,string> $patterns
     */
    private function matchesAnyPattern(string $value, array $patterns): bool
    {
        if ($value === '') {
            return false;
        }

        return array_filter($patterns, static fn (string $pattern): bool => str_contains($value, $pattern)) !== [];
    }
}
