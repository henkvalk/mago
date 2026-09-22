/*
 * Copyright © Mago Assistant
 */

/**
 * Every sentence the panel puts on a confirmation card, and the lookups behind them.
 *
 * These read the live form rather than the tool input on purpose: showing what is on the form
 * right now is what makes the card a review rather than a replay of a value the model may have
 * seen several turns ago. Values reach innerHTML through marked, so everything that came from the
 * store or the model is escaped here first.
 *
 * The bridge is passed as a getter, not a value: the panel loads form-bridge asynchronously, so a
 * reference captured at construction time would still be null when these run.
 */
define([], function () {
    'use strict';

    return function createConfirmText(getFormBridge, translator, text) {
        var t = translator.t;
        var entityLabel = translator.entityLabel;
        var describeEntity = translator.describeEntity;
        var fieldCountText = translator.fieldCountText;
        var escapeForMarkdown = text.escapeForMarkdown;
        var codeSpan = text.codeSpan;
        var previewValue = text.previewValue;

    // A translation pass can touch ten to thirty fields (task 010); thirty full "Label: old → new"
    // lines is a wall of text, not a review. Showing the first few and summarizing the rest keeps
    // the prompt something an administrator can actually read before confirming.
    var MAX_CONFIRM_FIELD_LINES = 10;

    // The old value is deliberately not part of the tool input (task 006): looking it up here,
    // through form-bridge, shows what is on the form right now rather than replaying a value the
    // model may have seen several turns ago. A path form-bridge cannot find is shown as-is, which
    // is itself informative: it means the target has moved since the model proposed the write.
    // live: a snapshot the caller already took, so a multi-line card looks up once instead of
    // per line. Omitted, it takes its own.
    function findLiveField(path, live) {
        var snapshot = live || liveForm();
        if (!snapshot) return null;
        var match = null;
        (snapshot.fields || []).forEach(function(field) {
            if (field.path === path) match = field;
        });
        return match;
    }

    function liveForm() {
        var bridge = getFormBridge();
        var snapshot = bridge ? bridge.snapshot() : null;
        return snapshot && snapshot.hasForm ? snapshot : null;
    }

    function isNavigatingWrite(input, live) {
        if (!input.entity_type) return false;
        if (!live) return true;
        return input.entity_type !== live.entityType || (input.entity_id || '') !== live.entityId;
    }

    function describeLiveForm(live) {
        var text = describeEntity(live.entityType, live.entityId);
        if (live.storeId) text += ' (' + t('store view %1', escapeForMarkdown(live.storeId)) + ')';
        return text;
    }

    function formatFieldChangeLine(change, live) {
        var field = findLiveField(change.path, live);
        if (!field) return escapeForMarkdown(change.path) + ': ' + codeSpan(previewValue(change.value));
        var previous = field.redacted ? t('(hidden)') : codeSpan(previewValue(field.value));
        return escapeForMarkdown(field.label) + ': ' + previous + ' → ' + codeSpan(previewValue(change.value));
    }

    // The heading says where the values go: the form on screen, another entity, or a New form,
    // in which case the administrator is also told the browser will leave this page, and that
    // unsaved edits here will be lost when the open form has any.
    function formatWriteFieldsHeading(input, changes, live) {
        if (typeof live === 'undefined') live = liveForm();
        var count = fieldCountText(changes.length);
        var notes;

        if (!isNavigatingWrite(input, live)) {
            return t('Stage %1 on %2:', count, live ? describeLiveForm(live) : t('the form on screen'));
        }

        notes = [t('You will leave this page.')];
        var bridge = getFormBridge();
        if (live && bridge && typeof bridge.hasUnsavedChanges === 'function' && bridge.hasUnsavedChanges()) {
            notes.push(t('Unsaved edits on %1 will be lost.', describeLiveForm(live)));
        }

        return t('Open %1 and stage %2 there:', describeEntity(input.entity_type, input.entity_id), count)
            + '\n\n**' + notes.join(' ') + '**\n';
    }

    function formatWriteFieldsConfirmMessage(tool) {
        var input = tool.input || {};
        var changes = input.changes || [];
        // One snapshot for the whole card; the heading and every line share it.
        var live = liveForm();
        var visibleChanges = changes.slice(0, MAX_CONFIRM_FIELD_LINES);
        var remaining = changes.length - visibleChanges.length;
        var lines = visibleChanges.map(function(change) { return formatFieldChangeLine(change, live); })
            .map(function(l) { return '- ' + l; });

        if (remaining > 0) {
            lines.push('- ' + (remaining === 1 ? t('...and %1 more field.', remaining) : t('...and %1 more fields.', remaining)));
        }

        return formatWriteFieldsHeading(input, changes, live) + '\n' + lines.join('\n')
            + '\n\n' + t('Nothing is saved until you click Save on the page.');
    }

    function formatToolConfirmMessage(tool) {
        if (tool.name === 'page_form' && tool.input && tool.input.action === 'write_fields') {
            return formatWriteFieldsConfirmMessage(tool);
        }
        var line = '**' + escapeForMarkdown(tool.name) + '**';
        if (tool.input) {
            var params = Object.keys(tool.input).map(function(k) {
                var value = tool.input[k];
                if (value !== null && typeof value === 'object') { value = JSON.stringify(value); }
                return escapeForMarkdown(k) + ': ' + codeSpan(value);
            });
            if (params.length) line += ': ' + params.join(', ');
        }
        return line;
    }

    function formatConfirmMessage(tools) {
        if (!tools || !tools.length) return t('I want to perform an action. Allow this?');
        var parts = tools.map(formatToolConfirmMessage);
        return t('I want to perform the following action:') + '\n\n' + parts.join('\n') + '\n\n' + t('Allow this?');
    }

    // The model's own reply is generated before the browser has applied anything (Confirm.php
    // streams the follow-up turn as soon as the tool result exists, not after form_apply runs), so
    // it can never know which fields actually took the value. This is the panel's own report,
    // appended as a separate message once the bridge has finished, never a second server round trip.
    // A directive whose target no longer matches the form now open (task 008: the administrator
    // navigated to a different entity, store view or form between proposal and confirmation) is
    // named by what it was meant for, so the administrator understands why nothing happened rather
    // than assuming the assistant silently did nothing.
    function formatTargetDescription(target) {
        var entityType = target && target.entity_type;
        if (!target || !target.entity_id) return t('a new, unsaved %1', entityLabel(entityType));
        return describeEntity(entityType, target.entity_id);
    }

    function formatRefusalMessage(target) {
        return t('That change was meant for %1, but a different form is open now. Nothing was changed. Go back to that page and ask me again.', formatTargetDescription(target));
    }

    function failedLabels(result) {
        return result.failed.map(function (f) { return escapeForMarkdown(f.label); }).join(', ');
    }

    function formatApplyOutcomeMessage(result) {
        if (result.refused) return formatRefusalMessage(result.target);

        var total = result.applied.length + result.failed.length;
        var text;

        if (!total) return null;
        if (!result.applied.length) return t('Could not stage %1. Nothing was changed.', failedLabels(result));

        text = t('Staged %1 of %2 %3. Not saved yet: click Save on the page to keep %4.',
            result.applied.length, total, fieldCountText(total).replace(/^\d+ /, ''),
            total === 1 ? t('this change') : t('these changes'));

        if (result.failed.length) {
            text += ' ' + t('Could not set: %1.', failedLabels(result));
        }

        return text;
    }

        return {
            findLiveField: findLiveField,
            liveForm: liveForm,
            isNavigatingWrite: isNavigatingWrite,
            describeLiveForm: describeLiveForm,
            formatFieldChangeLine: formatFieldChangeLine,
            formatWriteFieldsHeading: formatWriteFieldsHeading,
            formatWriteFieldsConfirmMessage: formatWriteFieldsConfirmMessage,
            formatToolConfirmMessage: formatToolConfirmMessage,
            formatConfirmMessage: formatConfirmMessage,
            formatTargetDescription: formatTargetDescription,
            formatRefusalMessage: formatRefusalMessage,
            failedLabels: failedLabels,
            formatApplyOutcomeMessage: formatApplyOutcomeMessage
        };
    };
});
