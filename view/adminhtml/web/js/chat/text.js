/*
 * Copyright © Mago Assistant
 */

/**
 * Text helpers the chat panel formats with. Everything here is a pure function of its arguments:
 * nothing reads the panel's state, the DOM or config, which is what makes it safe to share
 * between the panel and the pieces split out of it.
 */
define([], function () {
    'use strict';

    // A tool result can carry a client_directive payload that the server forwards untouched as a
    // form_apply event; this is the only place that dispatches on its shape. An unknown type or a
    // non-object payload is dropped rather than applied, and never breaks the surrounding message.
    function isPlainObject(value) {
        return !!value && typeof value === 'object' && !Array.isArray(value);
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // "cms_data" reads as "Cms data" in a card title; the mono badge keeps the real name.
    function skillTitle(toolName) {
        var t = String(toolName || '').replace(/_/g, ' ');
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    // "Cms data · create_page": how one tool call is named in a list.
    function toolLabel(tool) {
        var action = tool.input && tool.input.action ? ' · ' + tool.input.action : '';
        return skillTitle(tool.name) + action;
    }

    // The first few parameters on one line, for a bulk row: "identifier: summer-sale, title: Summer Sale".
    function summarizeInput(input) {
        var parts = [];
        Object.keys(input || {}).forEach(function(k) {
            if (k === 'action' || parts.length >= 3) return;
            var v = input[k];
            if (v === null || v === undefined || v === '') return;
            v = typeof v === 'object' ? JSON.stringify(v) : String(v);
            parts.push(k + ': ' + (v.length > 40 ? v.slice(0, 37) + '…' : v));
        });
        return parts.join(', ');
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T') + 'Z');
        var now = new Date();
        var diff = now - d;
        if (diff < 86400000) {
            return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        }
        if (diff < 604800000) {
            var days = Math.floor(diff / 86400000);
            return days + 'd ago';
        }
        return d.toLocaleDateString([], {month:'short', day:'numeric'});
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T') + 'Z');
        return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }

    // Field labels and values come straight out of the open form, which means straight out of the
    // database: a product description an import wrote is rendered here through marked, into
    // innerHTML. Backticks are not an escape (a value containing one closes the code span and the
    // rest is raw HTML), so everything markdown or HTML could act on is turned into an entity, and
    // the value is shown in a <code> element marked passes through untouched.
    function escapeForMarkdown(text) {
        return String(text === null || typeof text === 'undefined' ? '' : text).replace(/[&<>"'`*_\[\]~\\]/g, function (ch) {
            return '&#' + ch.charCodeAt(0) + ';';
        });
    }

    function codeSpan(text) {
        return '<code>' + escapeForMarkdown(text) + '</code>';
    }

    // A description can be hundreds of characters; the card is a review, not the value itself.
    var MAX_CONFIRM_VALUE_PREVIEW = 80;

    function previewValue(value) {
        var text = value === null || typeof value === 'undefined' ? '' : String(value);
        return text.length > MAX_CONFIRM_VALUE_PREVIEW ? text.slice(0, MAX_CONFIRM_VALUE_PREVIEW) + '...' : text;
    }

    return {
        isPlainObject: isPlainObject,
        esc: esc,
        skillTitle: skillTitle,
        toolLabel: toolLabel,
        summarizeInput: summarizeInput,
        formatDate: formatDate,
        formatTime: formatTime,
        escapeForMarkdown: escapeForMarkdown,
        codeSpan: codeSpan,
        previewValue: previewValue
    };
});
