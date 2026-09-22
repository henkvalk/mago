/**
 * Copyright © Mago Assistant
 *
 * Finds the UI component form on the current admin page, if any, and describes it: the entity it
 * edits and every field with its path, label, type, current value and options. This is the only
 * place in the codebase that detects a form's namespace, entity type, entity id or store scope -
 * every other piece of the assistant is handed the result of snapshot() rather than looking again.
 *
 * apply() is the write side: it stages a form_write directive's changes onto the same fields
 * snapshot() described, through their own UI components, and never saves anything itself.
 */
define(['uiRegistry'], function (registry) {
    'use strict';

    var DEFAULT_CAPS = {
        maxFields: 600,
        maxFieldValueLength: 500,
        maxFieldOptions: 50,
        maxPayloadBytes: 200000
    };

    var ENTITY_ID_PARAMS = ['id', 'page_id', 'block_id', 'entity_id', 'category_id'];

    /**
     * Admin URLs carry their parameters as path segments (".../edit/id/42/store/1/key/<hash>/"),
     * not as a query string, so both the entity id and the store scope have to be read from
     * location.pathname rather than location.search.
     *
     * @returns {Object}
     */
    function getPathParams() {
        var segments = window.location.pathname.split('/').filter(function (segment) {
            return segment !== '';
        });
        var params = {};
        var i;

        for (i = firstParamIndex(segments); i + 1 < segments.length; i += 2) {
            params[segments[i]] = decodeURIComponent(segments[i + 1]);
        }

        return params;
    }

    /**
     * The key/value pairs only line up when reading starts on a key, and how many segments precede
     * the first one depends on the install: "/admin/catalog/product/edit" is four, but
     * "/index.php/admin/..." (web/seo/use_rewrites off) or a base path make it five, and reading
     * from index 0 then pairs every value with the next key. The secret key segment Magento appends
     * to every admin URL ("key/<hash>") is always a key, so its parity is the alignment; failing
     * that, the first known parameter name is.
     *
     * @param {Array} segments
     * @returns {Number}
     */
    function firstParamIndex(segments) {
        var knownKeys = ['key', 'store'].concat(ENTITY_ID_PARAMS);
        var i;

        for (i = segments.length - 2; i >= 0; i--) {
            if (segments[i] === 'key') {
                return i % 2;
            }
        }

        for (i = 0; i + 1 < segments.length; i++) {
            if (knownKeys.indexOf(segments[i]) !== -1) {
                return i % 2;
            }
        }

        return 0;
    }

    /**
     * Caps are published by the server (Block\Adminhtml\ChatPanel::getJsConfig) so the client and
     * the server agree on one set of limits. Reading them on every call, rather than once at
     * module-load time, lets tests override window.MAGO_CONFIG between calls.
     *
     * @returns {Object}
     */
    function getCaps() {
        var config = window.MAGO_CONFIG || {};

        return {
            maxFields: config.formFieldCap || DEFAULT_CAPS.maxFields,
            maxFieldValueLength: config.formValueLengthCap || DEFAULT_CAPS.maxFieldValueLength,
            maxFieldOptions: config.formOptionCap || DEFAULT_CAPS.maxFieldOptions,
            maxPayloadBytes: config.formByteCap || DEFAULT_CAPS.maxPayloadBytes
        };
    }

    /**
     * The top-level form component is registered under "<namespace>.<namespace>" and is the only
     * component whose own index equals its namespace, regardless of what the provider happens to
     * be named (it is "product_form_data_source" for the product form and "page_form_data_source"
     * for the CMS page form, so the provider name itself cannot be used to find the form).
     *
     * @returns {Object|null}
     */
    function findFormComponent() {
        var matches = registry.filter(function (component) {
            return !!component
                && typeof component.namespace === 'string'
                && component.namespace !== ''
                && component.index === component.namespace
                && typeof component.provider === 'string'
                && component.provider !== '';
        });

        return matches.length ? matches[0] : null;
    }

    /**
     * A genuine data field observes a "value"; fieldsets, containers, buttons and modals do not.
     * Reading through the component rather than the DOM is what lets an edited-but-unsaved value
     * show up, since a field the administrator has touched has its new value on the component
     * while the markup may still show the old one.
     *
     * @param {Object} component
     * @returns {Boolean}
     */
    function isFieldComponent(component) {
        return !!component
            && typeof component.value === 'function'
            && typeof component.dataScope === 'string'
            && component.dataScope !== '';
    }

    /**
     * @param {Array} options
     * @returns {Array}
     */
    function flattenOptions(options) {
        var flat = [];

        (options || []).forEach(function (option) {
            if (option && Array.isArray(option.optgroup)) {
                flat = flat.concat(flattenOptions(option.optgroup));

                return;
            }

            if (option && typeof option.value !== 'undefined') {
                flat.push({value: option.value, label: option.label});
            }
        });

        return flat;
    }

    /**
     * @param {Object} component
     * @returns {Array|null}
     */
    function readOptions(component) {
        if (typeof component.options === 'function') {
            return flattenOptions(component.options());
        }

        if (Array.isArray(component.options)) {
            return flattenOptions(component.options);
        }

        return null;
    }

    /**
     * @param {*} value
     * @param {Number} maxLength
     * @param {Object} truncated
     * @returns {*}
     */
    function capValue(value, maxLength, truncated) {
        if (typeof value !== 'string' || value.length <= maxLength) {
            return value;
        }

        truncated.values = true;

        return value.substring(0, maxLength);
    }

    /**
     * @param {Array|null} options
     * @param {Number} maxOptions
     * @param {Object} truncated
     * @returns {Array|null}
     */
    function capOptions(options, maxOptions, truncated) {
        if (!options || options.length <= maxOptions) {
            return options;
        }

        truncated.options = true;

        return options.slice(0, maxOptions);
    }

    /**
     * A per-store form carries a "Use Default Value" checkbox for every field whose attribute
     * scope is not global; the checkbox's state lives on the field itself as "service" (its
     * presence) and "disabled" (its current state).
     *
     * @param {Object} component
     * @returns {Boolean}
     */
    function usesDefaultValue(component) {
        return !!component.service
            && typeof component.isUseDefault === 'function'
            && !!component.isUseDefault();
    }

    var SECRET_PATH_PATTERN = /password|secret|token|api_key|apikey|private_key/i;

    /**
     * A secret's value never leaves the browser: it is not the assistant's to read, and the
     * snapshot travels to the provider and into the conversation history. The field itself is
     * still listed (path, label, type) so it can be described and written to. Magento has no
     * single marker for a password field across UI components, so the element's own hints and
     * the field name are both checked. PageContextNormalizer applies the same rule server-side.
     *
     * @param {Object} component
     * @returns {Boolean}
     */
    function isSecretField(component) {
        return component.formElement === 'password'
            || component.inputType === 'password'
            || (typeof component.elementTmpl === 'string' && component.elementTmpl.indexOf('password') !== -1)
            || SECRET_PATH_PATTERN.test(component.dataScope || '');
    }

    /**
     * @param {String} namespace
     * @param {Object} caps
     * @param {Object} truncated
     * @returns {Array}
     */
    function collectFields(namespace, caps, truncated) {
        var prefix = namespace + '.';
        var components = registry.filter(function (component) {
            return !!component
                && typeof component.name === 'string'
                && component.name.indexOf(prefix) === 0
                && isFieldComponent(component);
        });

        if (components.length > caps.maxFields) {
            truncated.fields = true;
        }

        return components.slice(0, caps.maxFields).map(function (component) {
            var isSecret = isSecretField(component);

            return {
                path: component.dataScope,
                label: typeof component.label === 'function' ? component.label() : (component.label || ''),
                type: isSecret ? 'password' : (component.formElement || ''),
                value: isSecret ? null : capValue(component.value(), caps.maxFieldValueLength, truncated),
                redacted: isSecret,
                options: isSecret ? [] : capOptions(readOptions(component), caps.maxFieldOptions, truncated),
                required: typeof component.required === 'function' ? !!component.required() : false,
                disabled: typeof component.disabled === 'function' ? !!component.disabled() : false,
                usesDefaultValue: usesDefaultValue(component)
            };
        });
    }

    /**
     * The URL is authoritative when it names the entity; the provider's initial data is only a
     * fallback because the shape of that data differs per form (nested under "product" for the
     * product form, flat for the CMS page form). A new-entity form has no id anywhere, which is
     * reported as an empty string rather than guessed at.
     *
     * @param {Object} providerData
     * @returns {String}
     */
    function resolveEntityId(providerData) {
        var params = getPathParams();
        var dataRoots = [providerData, providerData ? providerData.product : null];
        var i;
        var j;
        var value;

        for (i = 0; i < ENTITY_ID_PARAMS.length; i++) {
            value = params[ENTITY_ID_PARAMS[i]];

            if (value) {
                return String(value);
            }
        }

        for (i = 0; i < dataRoots.length; i++) {
            if (!dataRoots[i]) {
                continue;
            }

            for (j = 0; j < ENTITY_ID_PARAMS.length; j++) {
                value = dataRoots[i][ENTITY_ID_PARAMS[j]];

                if (value !== undefined && value !== null && value !== '') {
                    return String(value);
                }
            }
        }

        return '';
    }

    /**
     * Absent means default scope, which is correct and not an error: the CMS page form has no
     * store parameter at all.
     *
     * @returns {String|null}
     */
    function resolveStoreId() {
        return getPathParams().store || null;
    }

    /**
     * The namespace, entity id and store scope a form_write directive's target is checked against
     * (task 008) - cheap to resolve on its own, unlike the full snapshot(), which also walks and
     * caps every field. apply() needs only this identity to refuse a stale directive, so it is kept
     * separate rather than making every confirm click pay for a full field collection it discards.
     *
     * @returns {{namespace: String|null, entityId: String, storeId: String|null}}
     */
    function resolveFormIdentity() {
        var formComponent = findFormComponent();
        var provider;
        var providerData;

        if (!formComponent) {
            return {namespace: null, entityId: '', storeId: resolveStoreId()};
        }

        provider = registry.get(formComponent.provider);
        providerData = provider ? provider.data : {};

        return {
            namespace: formComponent.namespace,
            entityId: resolveEntityId(providerData),
            storeId: resolveStoreId()
        };
    }

    /**
     * Reads the deny patterns FormPolicy publishes through Block\Adminhtml\ChatPanel::getJsConfig
     * (task 003) - the one list, so this never keeps its own copy of what customer, order and
     * admin user forms look like.
     *
     * @returns {{namespaces: Array, routes: Array}}
     */
    function getDenyPatterns() {
        var config = window.MAGO_CONFIG || {};

        return {
            namespaces: config.formDenyNamespaces || [],
            routes: config.formDenyRoutes || []
        };
    }

    /**
     * @param {String} value
     * @param {Array} patterns
     * @returns {Boolean}
     */
    function matchesAnyPattern(value, patterns) {
        return !!value && patterns.some(function (pattern) {
            return value.indexOf(pattern) !== -1;
        });
    }

    /**
     * The deny check every snapshot goes through before a single field is read (task 003): a form
     * is denied by its own namespace or by the current admin route, because either alone has gaps.
     * This runs before collectFields() ever walks the registry, so a denied form's field values
     * are never read into memory here, let alone sent anywhere.
     *
     * @param {String} namespace
     * @returns {Boolean}
     */
    function isDeniedForm(namespace) {
        var deny = getDenyPatterns();

        return matchesAnyPattern(namespace, deny.namespaces) || matchesAnyPattern(window.location.pathname, deny.routes);
    }

    /**
     * @returns {Object}
     */
    function emptySnapshot() {
        return {
            hasForm: false,
            namespace: null,
            entityType: null,
            entityId: '',
            isNewEntity: false,
            storeId: null,
            fields: [],
            fieldCount: 0,
            truncated: {fields: false, values: false, options: false, bytes: false}
        };
    }

    /**
     * What a denied form (task 003) reports instead of a real snapshot: the same shape
     * emptySnapshot() already reports for "nothing here at all", plus a denied flag the server
     * reads to explain the refusal rather than claiming no form is open. Nothing that names or
     * identifies the form - namespace, entity id, route - is included, since none of it is needed
     * for that explanation and every byte left out here is a byte that can never reach the server.
     *
     * @returns {Object}
     */
    function deniedSnapshot() {
        var snapshot = emptySnapshot();

        snapshot.denied = true;

        return snapshot;
    }

    /**
     * Drops trailing fields until the serialized snapshot fits the byte cap. The per-field and
     * per-option caps already bound most of the growth; this is the backstop that keeps a form
     * with many labels and option lists from producing an oversized POST body.
     *
     * @param {Object} snapshotResult
     * @param {Number} maxBytes
     * @param {Object} truncated
     * @returns {Object}
     */
    function withinByteCap(snapshotResult, maxBytes, truncated) {
        var json = JSON.stringify(snapshotResult);
        var index;

        while (new Blob([json]).size > maxBytes && snapshotResult.fields.length > 0) {
            index = indexOfLargestOptionList(snapshotResult.fields);

            if (index === -1) {
                snapshotResult.fields.pop();
                truncated.bytes = true;
                snapshotResult.fieldCount = snapshotResult.fields.length;
            } else {
                snapshotResult.fields[index].options = [];
                truncated.options = true;
            }

            json = JSON.stringify(snapshotResult);
        }

        return snapshotResult;
    }

    /**
     * Option lists are what make a snapshot large, while the field paths are what every consumer
     * needs, so an over-sized snapshot sheds option lists (largest first) before it drops a single
     * field; see PageContextNormalizer::capToByteSize() for the same rule server-side.
     *
     * @param {Array} fields
     * @returns {Number} index of the field with the most options, or -1 when none has any
     */
    function indexOfLargestOptionList(fields) {
        var largestIndex = -1;
        var largestCount = 0;

        fields.forEach(function (field, index) {
            var count = Array.isArray(field.options) ? field.options.length : 0;

            if (count > largestCount) {
                largestCount = count;
                largestIndex = index;
            }
        });

        return largestIndex;
    }

    /**
     * @returns {Object}
     */
    function snapshot() {
        var identity = resolveFormIdentity();
        var caps;
        var truncated;
        var fields;
        var result;

        if (!identity.namespace) {
            return emptySnapshot();
        }

        if (isDeniedForm(identity.namespace)) {
            return deniedSnapshot();
        }

        caps = getCaps();
        truncated = {fields: false, values: false, options: false, bytes: false};
        fields = collectFields(identity.namespace, caps, truncated);

        result = {
            hasForm: true,
            namespace: identity.namespace,
            entityType: identity.namespace.replace(/_form$/, ''),
            entityId: identity.entityId,
            isNewEntity: identity.entityId === '',
            storeId: identity.storeId,
            fields: fields,
            fieldCount: fields.length,
            truncated: truncated
        };

        return withinByteCap(result, caps.maxPayloadBytes, truncated);
    }

    /**
     * @param {String} namespace
     * @param {String} path
     * @returns {Object|null}
     */
    function findFieldByPath(namespace, path) {
        var prefix = namespace + '.';
        var matches = registry.filter(function (component) {
            return !!component
                && typeof component.name === 'string'
                && component.name.indexOf(prefix) === 0
                && isFieldComponent(component)
                && component.dataScope === path;
        });

        return matches.length ? matches[0] : null;
    }

    /**
     * A WYSIWYG field's "value" observable drives what gets saved, but a TinyMCE editor that is
     * already open reads and renders from its own in-memory buffer, not from that observable, so
     * setting it alone can appear to do nothing to the administrator. Pushing the value into the
     * live editor too, when one exists for this field, is what makes the change visible immediately.
     *
     * @param {Object} component
     * @param {*} value
     */
    function syncWysiwygBuffer(component, value) {
        var editor;

        if (!component.wysiwygId || typeof window.tinymce === 'undefined') {
            return;
        }

        editor = window.tinymce.get(component.wysiwygId);

        if (editor) {
            editor.setContent(value);
        }
    }

    /**
     * Walks up from a field to the nearest ancestor that is itself a collapsible section, since a
     * field's immediate parent is usually just a layout container, not the fieldset that owns the
     * "opened" state an administrator sees.
     *
     * @param {Object} component
     * @returns {Object|null}
     */
    function findCollapsibleAncestor(component) {
        var current = component.parentName ? registry.get(component.parentName) : null;

        while (current) {
            if (current.collapsible && typeof current.opened === 'function') {
                return current;
            }

            current = current.parentName ? registry.get(current.parentName) : null;
        }

        return null;
    }

    /**
     * A focus or scroll call on a field inside a still-collapsed fieldset does nothing useful, since
     * the field has no rendered position on the page yet.
     *
     * @param {Object} component
     */
    function expandContainingFieldset(component) {
        var fieldset = findCollapsibleAncestor(component);

        if (fieldset && !fieldset.opened()) {
            fieldset.opened(true);
        }
    }

    /**
     * The outer field wrapper is what should be highlighted and scrolled to: it exists for every
     * field type, including a WYSIWYG whose own element only renders once its section is open. A
     * highlight the administrator can never see because its section stayed collapsed would defeat
     * the point, so every changed field's section is expanded here, not only the one that is focused.
     *
     * @param {Object} component
     * @returns {Element|null}
     */
    function findFieldContainer(component) {
        expandContainingFieldset(component);

        return document.querySelector('.admin__field[data-index="' + component.index + '"]');
    }

    /**
     * Prefers the live TinyMCE editor's own focus method when one is open, since that focuses
     * inside the visible iframe rather than the hidden textarea TinyMCE replaces. Falls back to the
     * first native control in the field, which degrades to doing nothing rather than throwing when
     * that control cannot take focus (a hidden textarea, an uninitialised WYSIWYG).
     *
     * @param {Object} component
     * @param {Element} container
     * @returns {{focus: Function}|Element|null}
     */
    function findFocusTarget(component, container) {
        var editor;

        if (component.wysiwygId) {
            editor = typeof window.tinymce !== 'undefined' ? window.tinymce.get(component.wysiwygId) : null;

            if (editor) {
                return {
                    focus: function () {
                        editor.focus();
                    }
                };
            }

            return document.getElementById(component.wysiwygId);
        }

        return container.querySelector('input, select, textarea');
    }

    /**
     * @param {Object} component
     */
    function highlightField(component) {
        var container = findFieldContainer(component);

        if (container) {
            container.classList.add('mago-field-changed');
        }
    }

    /**
     * @param {Object} component
     */
    function focusField(component) {
        var container = findFieldContainer(component);

        if (!container) {
            return;
        }

        container.scrollIntoView({block: 'center'});

        /* The admin theme's sticky page header repositions itself in response to the scroll this
         * just triggered, and that reflow can carry a focus straight back off again if it lands
         * while it is still settling. Focusing on the next tick, once the scroll has finished, is
         * what keeps the focus rather than losing it to that reflow. */
        setTimeout(function () {
            var focusTarget = findFocusTarget(component, container);

            if (focusTarget && typeof focusTarget.focus === 'function') {
                try {
                    focusTarget.focus({preventScroll: true});
                } catch (e) {
                    // A hidden input or an uninitialised WYSIWYG cannot take focus; the
                    // scrollIntoView above is the best effort this degrades to.
                }
            }
        }, 300);
    }

    /**
     * Bound for a whenRegistered() poll waiting on the form itself or its provider: comfortably
     * longer than a real component registration burst could plausibly stretch on even under heavy
     * parallel load, short enough that a component which genuinely never registers fails the caller
     * instead of hanging.
     */
    var REGISTRY_POLL_TIMEOUT_MS = 8000;

    /**
     * Bound for whenFieldReady()'s poll, deliberately shorter than REGISTRY_POLL_TIMEOUT_MS: by the
     * time apply() is waiting on an individual field, its form and provider have already resolved,
     * so the rest of that same registration burst is already well under way rather than starting
     * cold - a genuinely lagging field settles quickly behind them. Bounding it well below the
     * remainder of that time is deliberate too: a change naming a path that is not a field on this
     * form at all (an invalid path, not merely a slow one) is indistinguishable from a slow field
     * until this timeout gives up on it, and that outcome still has to reach the administrator
     * comfortably inside the round trip the rest of the batch already completed in.
     */
    var FIELD_REGISTRATION_TIMEOUT_MS = 2000;

    /**
     * How often whenRegistered() (and whenFieldReady()) re-check the registry. Tight enough that the
     * wait it adds is negligible next to a real page load; see whenRegistered() for why polling
     * rather than uiRegistry's own async lookup.
     */
    var REGISTRY_POLL_INTERVAL_MS = 25;

    /**
     * Waits, with a bounded timeout, for a named component to be present in uiRegistry - by polling
     * its plain, synchronous lookup (registry.get(name), no callback), not its asynchronous
     * registry.get(name, callback) form. That async form resolves a pending request only through
     * Registry.prototype._updateRequests(), which is debounced 10ms after the *last*
     * registry.set() call anywhere on the page (Magento_Ui/js/lib/registry/registry.js) - not 10ms
     * after this particular component registers. A page whose UI component tree keeps registering
     * steadily (a product form easily has hundreds of components) can keep resetting that timer for
     * seconds under real parallel load, holding every pending async request, including this one,
     * unresolved the whole time. The synchronous lookup reads the registry's current contents
     * directly and is not debounced at all, so a short, tight poll against it notices the component
     * within one poll interval of it actually being set, independent of whatever else is still
     * registering elsewhere on the page.
     *
     * @param {String} name
     * @param {Number} timeoutMs
     * @param {Function} callback invoked with the component, or null if it never appeared in time
     */
    function whenRegistered(name, timeoutMs, callback) {
        var existing = registry.get(name);

        if (existing) {
            callback(existing);

            return;
        }

        var elapsedMs = 0;
        var poller = setInterval(function () {
            var component = registry.get(name);

            elapsedMs += REGISTRY_POLL_INTERVAL_MS;

            if (component) {
                clearInterval(poller);
                callback(component);
            } else if (elapsedMs >= timeoutMs) {
                clearInterval(poller);
                callback(null);
            }
        }, REGISTRY_POLL_INTERVAL_MS);
    }

    /**
     * Waits for the given entity type's top-level form component to register itself in uiRegistry
     * (navigate-then-act, task 009): a UI component form registers well after DOMContentLoaded, so
     * waiting for the page to finish loading is not enough. Every entity type this codebase writes
     * to already follows the "<entityType>_form" namespace convention findFormComponent() itself
     * relies on (registered under "<namespace>.<namespace>"), so the caller can predict the name to
     * wait for without carrying it along as data.
     *
     * @param {String} entityType
     * @param {Number} timeoutMs
     * @param {Function} callback invoked with the component, or null if it never appeared in time
     */
    function whenFormReady(entityType, timeoutMs, callback) {
        var namespace = entityType + '_form';
        var componentName = namespace + '.' + namespace;

        whenRegistered(componentName, timeoutMs, callback);
    }

    /**
     * @param {*} a
     * @param {*} b
     * @returns {Boolean}
     */
    function isSameNonEmptyString(a, b) {
        return typeof a === 'string' && a !== '' && a === b;
    }

    /**
     * Refuses to treat a directive's target as current unless its namespace, entity id and store
     * scope all still match the form actually open in the browser (task 008): the administrator may
     * have navigated to a different entity, a different store view or a different form entirely
     * between the assistant proposing the change and the administrator confirming it. An empty
     * namespace or entity id never matches, not even another empty one, since that is exactly the
     * value every new, unsaved entity form reports - without this rule a directive proposed on one
     * New Product form would apply to the next New Product form the administrator happens to open.
     *
     * The one exception is a target flagged is_new, which only the server sets: on a form_write it
     * proposed against a new, unsaved form (WriteFieldsAction::stage(), after the confirm request's
     * own page context passed its target check), and on a navigate intent (chat-panel.js
     * applyStoredNavigateIntent()), which is consumed once, on the very page the assistant sent
     * the browser to, and expires within a minute. A New form with a matching namespace is then the
     * form the directive was issued for, not a coincidence; a directive without the flag, or one
     * carried over from some other page, is still refused exactly as before.
     *
     * @param {Object|null|undefined} target
     * @param {Object} live
     * @returns {Boolean}
     */
    function isSameTarget(target, live) {
        if (!target) {
            return false;
        }

        return isSameNonEmptyString(target.namespace, live.namespace)
            && (isSameNonEmptyString(target.entity_id, live.entityId) || isNewEntityMatch(target, live))
            && (target.store_id || '') === (live.storeId || '');
    }

    /**
     * @param {Object} target
     * @param {Object} live
     * @returns {Boolean}
     */
    function isNewEntityMatch(target, live) {
        return target.is_new === true && (target.entity_id || '') === '' && live.entityId === '';
    }

    /**
     * The "Use Default Value" checkbox only exists for a field whose attribute scope is not
     * global, published on the component as its "service" config; a global field (sku) simply has
     * none, and a directive is never refused for lacking one, so this is an existence check, not a
     * validation.
     *
     * @param {Object} component
     * @returns {Boolean}
     */
    function hasUseDefaultToggle(component) {
        return !!component.service && typeof component.isUseDefault === 'function';
    }

    /**
     * Unticks "Use Default Value" through the field component's own API rather than the checkbox
     * in the DOM (task 010): writing to the isUseDefault observable is what a real click does too,
     * and the component's own "isUseDefault" listener runs its toggleUseDefault(false) side effect
     * from that write, un-disabling the field and writing the use_default flag into the data
     * provider, so a value staged here does not silently revert to the default on Save.
     *
     * @param {Object} component
     * @param {Object} change
     */
    function clearUseDefaultIfRequested(component, change) {
        if (change.clear_use_default && hasUseDefaultToggle(component)) {
            component.isUseDefault(false);
        }
    }

    /**
     * A Page Builder field (Magento_PageBuilder/js/form/element/wysiwyg) renders its stage once, from
     * the value it had when the stage was created, and only writes back to the value observable
     * from the stage; setting the observable alone changes what gets saved but not what the
     * administrator sees until the page reloads after Save. Rebuilding the stage from the new
     * value, through the same stage-builder the stage itself uses at init, is what makes the
     * staged content visible immediately. The builder only ever adds to the root container, so
     * the current content is cleared first (the builder's own recovery path does the same) or the
     * old content would stay on the stage next to the new. A stage that has not been opened yet
     * (button mode) is built from initialValue when the administrator opens it, so that is
     * updated as well.
     *
     * @param {Object} component
     * @param {*} value
     */
    function syncPageBuilderStage(component, value) {
        var pageBuilder;

        if (typeof component.initPageBuilder !== 'function') {
            return;
        }

        component.initialValue = value;
        pageBuilder = component.pageBuilder;

        if (!pageBuilder || !pageBuilder.stage) {
            return;
        }

        pageBuilder.initialValue = value;

        require(['Magento_PageBuilder/js/stage-builder'], function (buildStage) {
            pageBuilder.stage.rootContainer.children([]);
            buildStage(pageBuilder.stage, value);
        });
    }

    /**
     * A field's "value" observable and the provider's own copy of the same path are kept in sync by
     * a link (Magento_Ui/js/lib/core/element/links.js) that pulls the provider's current value into
     * the field exactly once, at the field's own link setup - and uiRegistry resolves that setup
     * through its own request queue, which is debounced (10ms of registration activity fully
     * quieting down), not delivered the instant the field itself becomes queryable. So a field can
     * already be findable here while its own one-time pull from the provider is still pending. Value
     * alone (below) sets what the administrator sees immediately, but leaves the provider still
     * holding whatever it had before; if that pending pull then fires, it reads the provider's own
     * (still unchanged) copy and silently reverts the field. Writing the same value into the
     * provider here as well closes that race regardless of which order the two happen to land in:
     * whenever the deferred pull does fire, it reads back the value already staged, not something
     * older, so there is nothing left for it to overwrite.
     *
     * component and provider are both preconditions here, not optional extras: a caller that cannot
     * supply either (see whenFieldReady() and whenProviderReady()) must not call this at all, rather
     * than have it silently fall back to setting the observable alone, because that is the exact
     * race this function exists to close.
     *
     * @param {Object} component
     * @param {Object} provider
     * @param {Object} change
     * @returns {Object}
     */
    function writeChange(component, provider, change) {
        var value = toComponentValue(component, change.value);

        clearUseDefaultIfRequested(component, change);
        component.value(value);
        provider.set(component.dataScope, value);
        syncWysiwygBuffer(component, value);
        syncPageBuilderStage(component, value);

        return {ok: true, path: change.path, label: change.label || change.path, component: component};
    }

    /**
     * A directive carries every value as one string, but a multi-value field (the product form's
     * category_ids and website_ids ui-selects, any multiselect) holds an array, and handing it a
     * string leaves it rendering nothing while the provider carries a value Save cannot process.
     * The field itself says which it is: a ui-select flags "multiple", and a multiselect already
     * holds an array. A comma-separated list is the natural way to write several ids into one
     * string, so that is what is split here.
     *
     * @param {Object} component
     * @param {*} value
     * @returns {*}
     */
    function toComponentValue(component, value) {
        if (!isMultiValueField(component) || typeof value !== 'string') {
            return value;
        }

        return value.split(',').map(function (part) {
            return part.trim();
        }).filter(function (part) {
            return part !== '';
        });
    }

    /**
     * @param {Object} component
     * @returns {Boolean}
     */
    function isMultiValueField(component) {
        return component.multiple === true || Array.isArray(component.value());
    }

    /**
     * Waits for a form's provider component to register in uiRegistry. Mirrors whenFormReady()'s
     * own reasoning, one level down: the top-level form component being queryable does not
     * guarantee its provider is too, because the two register independently, and (see
     * whenRegistered()) uiRegistry's own async lookup is exactly the mechanism that can leave that
     * gap open far longer than it looks like it should. apply() waits on this before writing a
     * single field, rather than only benefiting from it when the provider happens to already be
     * there.
     *
     * @param {String} providerName
     * @param {Number} timeoutMs
     * @param {Function} callback invoked with the provider component, or null if it never appeared
     */
    function whenProviderReady(providerName, timeoutMs, callback) {
        whenRegistered(providerName, timeoutMs, callback);
    }

    /**
     * Waits for one field's own component to register in uiRegistry, the same way whenFormReady()
     * and whenProviderReady() wait for the form and its provider - and for the same reason: the
     * top-level form component and its provider both being ready does not mean every individual
     * field is too. A product form builds hundreds of field components as a burst, each gated behind
     * its own nested UI component construction (template fetch and parse included), and navigate-
     * then-act (task 009) can reach here well before that burst has finished, closer to the start of
     * it than an administrator who has had the page open for a while ever manages to be. findFieldByPath()
     * is a plain, synchronous registry.filter() underneath (see whenRegistered() for why that
     * matters), so this polls it the same way.
     *
     * @param {String} namespace
     * @param {String} path
     * @param {Number} timeoutMs
     * @param {Function} callback invoked with the field component, or null if it never appeared
     */
    function whenFieldReady(namespace, path, timeoutMs, callback) {
        var existing = namespace ? findFieldByPath(namespace, path) : null;

        if (existing) {
            callback(existing);

            return;
        }

        var elapsedMs = 0;
        var poller = setInterval(function () {
            var component = namespace ? findFieldByPath(namespace, path) : null;

            elapsedMs += REGISTRY_POLL_INTERVAL_MS;

            if (component) {
                clearInterval(poller);
                callback(component);
            } else if (elapsedMs >= timeoutMs) {
                clearInterval(poller);
                callback(null);
            }
        }, REGISTRY_POLL_INTERVAL_MS);
    }

    /**
     * Highlighting and focusing read the DOM, but expanding a collapsed fieldset (findFieldContainer)
     * only renders its contents once Knockout's own deferred update queue has had a turn to run, so
     * this happens a tick after every value has already been set, never before. On an ordinary form
     * already open when the directive arrives, one tick is always enough, since the whole page's own
     * component tree finished rendering well before the administrator could have asked anything. Right
     * after navigate-then-act's own navigation (task 009), apply() can run the moment the top-level
     * form component itself registers, which is earlier than every field's own wrapper has necessarily
     * rendered, so this polls a short, bounded window instead of trusting a single tick.
     *
     * @param {Array} appliedComponents
     */
    function revealAppliedFields(appliedComponents) {
        var maxAttempts = 20;
        var attempt = 0;

        function everyContainerRendered() {
            return appliedComponents.every(function (component) {
                return !!document.querySelector('.admin__field[data-index="' + component.index + '"]');
            });
        }

        function attemptReveal() {
            if (!everyContainerRendered() && attempt < maxAttempts) {
                attempt++;
                setTimeout(attemptReveal, 100);

                return;
            }

            appliedComponents.forEach(highlightField);
            focusField(appliedComponents[0]);
        }

        setTimeout(attemptReveal, 0);
    }

    /**
     * A change whose provider never became available would silently fall back to setting the
     * observable alone - exactly the race writeChange() exists to close - so it is reported as
     * failed rather than attempted, with a label an administrator can actually read in the chat, the
     * same shape a disabled or unknown field path already reports.
     *
     * @param {Object} change
     * @returns {Object}
     */
    function unwritableChangeResult(change) {
        return {ok: false, path: change.path, label: change.label || change.path};
    }

    /**
     * Waits for one change's own field (whenFieldReady()) and then writes it (writeChange()), or
     * reports it unwritable if the field never appeared. Every change in a batch waits independently
     * and in parallel, rather than one at a time, so one slow-to-register field does not hold up
     * every change queued behind it in the directive.
     *
     * @param {String} namespace
     * @param {Object} provider
     * @param {Object} change
     * @param {Function} callback invoked with the change's result
     */
    function settleChange(namespace, provider, change, callback) {
        whenFieldReady(namespace, change.path, FIELD_REGISTRATION_TIMEOUT_MS, function (component) {
            callback(component ? writeChange(component, provider, change) : unwritableChangeResult(change));
        });
    }

    /**
     * @param {Array} results
     * @param {Function} callback invoked with {applied: Array, failed: Array}
     */
    function reportChangeResults(results, callback) {
        var applied = [];
        var failed = [];
        var appliedComponents = [];

        results.forEach(function (result) {
            if (result.ok) {
                applied.push({path: result.path, label: result.label});
                appliedComponents.push(result.component);
            } else {
                failed.push({path: result.path, label: result.label});
            }
        });

        if (appliedComponents.length) {
            revealAppliedFields(appliedComponents);
        }

        callback({applied: applied, failed: failed});
    }

    /**
     * Enacts a form_write directive (task 005/006) on whichever form is currently open. The server
     * has already matched the directive's target once, against the page context resent on the
     * confirm request, but confirming is a second request: the browser re-checks the target against
     * the form actually on screen right now (task 008) rather than trusting that nothing changed in
     * between. Nothing here saves the form; it only sets field values, so the administrator's own
     * Save click is what persists them.
     *
     * The provider every field writes through (writeChange()) is waited for here, bounded, before a
     * single field is touched: on a form fresh off navigate-then-act's own navigation, the top-level
     * form component being registered does not by itself guarantee the provider is too (see
     * whenProviderReady()). Each field is then waited for independently (whenFieldReady()) for the
     * same reason, one level further down - the form and its provider both being ready does not mean
     * any particular field is. A change whose field or provider never appears in time fails rather
     * than writing the observable alone and reporting success for a write that never landed.
     *
     * @param {Object} directive
     * @param {Function} callback invoked with
     *      {applied: Array, failed: Array, refused: Boolean}|{applied: Array, failed: Array}
     */
    function apply(directive, callback) {
        var identity = resolveFormIdentity();
        var target = directive ? directive.target : null;

        if (!isSameTarget(target, identity)) {
            callback({applied: [], failed: [], refused: true, target: target || null});

            return;
        }

        var namespace = identity.namespace;
        var formComponent = findFormComponent();
        var changes = (directive && directive.changes) || [];

        whenProviderReady(formComponent.provider, REGISTRY_POLL_TIMEOUT_MS, function (provider) {
            if (!provider) {
                reportChangeResults(changes.map(unwritableChangeResult), callback);

                return;
            }

            if (!changes.length) {
                reportChangeResults([], callback);

                return;
            }

            var results = new Array(changes.length);
            var remaining = changes.length;

            changes.forEach(function (change, index) {
                settleChange(namespace, provider, change, function (result) {
                    results[index] = result;
                    remaining--;

                    if (remaining === 0) {
                        reportChangeResults(results, callback);
                    }
                });
            });
        });
    }

    /**
     * True when any field of the open form holds a value other than the one it loaded with, by
     * the field component's own hasChanged(): the same test Magento's form uses. Values this
     * bridge staged count too, since they are equally unsaved.
     *
     * @returns {Boolean}
     */
    function hasUnsavedChanges() {
        var formComponent = findFormComponent();
        var prefix;

        if (!formComponent) {
            return false;
        }

        prefix = formComponent.namespace + '.';

        return registry.filter(function (component) {
            return !!component
                && typeof component.name === 'string'
                && component.name.indexOf(prefix) === 0
                && isFieldComponent(component)
                && typeof component.hasChanged === 'function';
        }).some(function (component) {
            return component.hasChanged();
        });
    }

    /* Two consecutive polls with the same count is what "settled" means here. A UI component form
       registers its fields over several ticks, and a Page Builder field
       (Magento_PageBuilder/js/form/element/wysiwyg) registers only once its stage has initialised,
       well after the plain inputs around it. A snapshot taken before that reports a form that is
       genuinely missing fields, with nothing to say it was early. */
    var SETTLE_POLL_INTERVAL_MS = 100;
    var SETTLE_STABLE_POLLS = 2;

    /* How long after this module loads a page is given to produce a form at all. Past that, a page
       without one has genuinely not got one, and waiting again on every message would put the delay
       on every dashboard question forever to catch a race that only exists just after a load. */
    var FORM_APPEARANCE_GRACE_MS = 750;
    var loadedAt = Date.now();

    /**
     * Number of field components currently registered for the open form, or null when no form is
     * open. Counting is deliberately cheaper than building a snapshot: this runs on a poll.
     *
     * @returns {?Number}
     */
    function registeredFieldCount() {
        var formComponent = findFormComponent();

        if (!formComponent) {
            return null;
        }

        var prefix = formComponent.name + '.';

        return registry.filter(function (component) {
            return !!component
                && typeof component.name === 'string'
                && component.name.indexOf(prefix) === 0
                && isFieldComponent(component);
        }).length;
    }

    /**
     * Calls back once the form has stopped registering fields, or once timeoutMs has passed,
     * whichever comes first. The callback is told which of the two happened so a caller can report
     * an early snapshot rather than present it as complete. On a page with no form it calls back
     * immediately: there is nothing to wait for.
     *
     * @param {Number} timeoutMs
     * @param {Function} callback
     */
    function whenFieldsSettled(timeoutMs, callback) {
        var lastCount = -1;
        var stablePolls = 0;

        /* Real elapsed time, not a tick count: a background tab has its timers throttled to about
           one a second, so counting intervals would call a form absent after a fraction of the
           time it was given. */
        var startedAt = Date.now();
        var poller = setInterval(function () {
            var count = registeredFieldCount();
            var elapsedMs = Date.now() - startedAt;

            /* No form component yet means either a page without one or a form that has not got
               there. They are indistinguishable this early, so a short grace period decides: long
               enough for a form to appear, short enough that the dashboard does not pay for it on
               every message. */
            if (count === null) {
                if (Date.now() - loadedAt >= FORM_APPEARANCE_GRACE_MS) {
                    clearInterval(poller);
                    callback(true);
                }

                return;
            }

            stablePolls = count === lastCount ? stablePolls + 1 : 0;
            lastCount = count;

            if (stablePolls >= SETTLE_STABLE_POLLS) {
                clearInterval(poller);
                callback(true);
            } else if (elapsedMs >= timeoutMs) {
                clearInterval(poller);
                callback(false);
            }
        }, SETTLE_POLL_INTERVAL_MS);
    }

    var bridge = {
        snapshot: snapshot,
        apply: apply,
        whenFormReady: whenFormReady,
        whenFieldsSettled: whenFieldsSettled,
        hasUnsavedChanges: hasUnsavedChanges
    };

    /**
     * chat-panel.js is a plain IIFE, not an AMD module, and Playwright needs a handle it can reach
     * through page.evaluate. Publishing on window is how both get one.
     */
    window.magoFormBridge = bridge;

    return bridge;
});
