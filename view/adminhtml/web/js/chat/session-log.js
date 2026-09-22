/*
 * Copyright © Mago Assistant
 */

/**
 * The "changes this session" log: what the assistant actually wrote, kept in session storage so it
 * survives a panel close but never outlives the tab.
 *
 * Only the log's own storage and rendering live here. Showing and hiding it is the panel's job,
 * because that means swapping the whole message area and coordinating with the history view, which
 * is panel state this module has no business reaching into.
 */
define([], function () {
    'use strict';

    var SS_KEY_LOG = 'mago_log';

    /* A session's worth of writes, not an audit trail; the panel shows them newest first. */
    var MAX_ENTRIES = 50;

    return function createSessionLog(logBtn, UI) {
        function read() {
            try {
                return JSON.parse(sessionStorage.getItem(SS_KEY_LOG) || '[]');
            } catch (e) {
                return [];
            }
        }

        function suffix(state) {
            if (state === 'skipped') return ' (not run)';

            return state === 'failed' ? ' (failed)' : '';
        }

        function tone(state) {
            if (state === 'failed') return 'danger';

            return state === 'skipped' ? 'muted' : undefined;
        }

        function write(title, action, state) {
            var entries = read();
            entries.unshift({
                text: title + (action ? ' · ' + action : '') + suffix(state),
                time: new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}),
                tone: tone(state)
            });

            try {
                sessionStorage.setItem(SS_KEY_LOG, JSON.stringify(entries.slice(0, MAX_ENTRIES)));
            } catch (e) {}

            if (logBtn) logBtn.classList.toggle('has-entries', entries.length > 0);
        }

        function render(entries) {
            return entries.length
                ? UI.sessionLog({title: 'Changes this session', entries: entries})
                : UI.empty({
                    title: 'No changes yet',
                    text: 'Every write the assistant runs in this session is listed here.',
                    icon: 'list'
                });
        }

        function hasEntries() {
            return read().length > 0;
        }

        return {read: read, write: write, render: render, hasEntries: hasEntries};
    };
});
