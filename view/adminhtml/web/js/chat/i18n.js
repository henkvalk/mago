/*
 * Copyright © Mago Assistant
 */

/**
 * The panel's own translations and the entity wording built on top of them. A factory rather than
 * a plain object because the phrase table comes from MAGO_CONFIG, and the entity helpers escape
 * through the shared text helpers before anything reaches innerHTML.
 */
define(['MagoAssistant_Mago/js/chat/text'], function (text) {
    'use strict';

    var escapeForMarkdown = text.escapeForMarkdown;

    var ENTITY_LABELS = {cms_page: 'CMS page', cms_block: 'CMS block'};

    return function createTranslator(config) {
    // Every sentence shown to the administrator goes through here. The English source is the key,
    // Block\Adminhtml\ChatPanel publishes the translations, and %1, %2 are substituted in order.
    function t(sentence) {
        var translations = (config && config.i18n) || {};
        var text = translations[sentence] || sentence;
        var values = Array.prototype.slice.call(arguments, 1);

        return text.replace(/%(\d+)/g, function (match, index) {
            var value = values[parseInt(index, 10) - 1];
            return typeof value === 'undefined' ? match : String(value);
        });
    }

    // entityType and entityId come from the model's own tool input (or from the URL), not from
    // anything this panel controls, and the result is rendered through marked into innerHTML, so
    // they are escaped here like every other value on the card.
    function entityLabel(entityType) {
        if (!entityType) return 'item';
        return ENTITY_LABELS[entityType] ? t(ENTITY_LABELS[entityType]) : escapeForMarkdown(String(entityType).replace(/_/g, ' '));
    }

    function describeEntity(entityType, entityId) {
        return entityId
            ? t('%1 #%2', entityLabel(entityType), escapeForMarkdown(entityId))
            : t('a new %1', entityLabel(entityType));
    }

    function fieldCountText(count) {
        return count === 1 ? t('%1 field', count) : t('%1 fields', count);
    }

        return {
            t: t,
            entityLabel: entityLabel,
            describeEntity: describeEntity,
            fieldCountText: fieldCountText
        };
    };
});
