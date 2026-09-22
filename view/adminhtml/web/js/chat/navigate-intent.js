/*
 * Copyright © Mago Assistant
 */

/**
 * A form_navigate directive outlives the page that issued it: the panel stores what it intended
 * to do, the browser navigates, and the next page load claims it. Session storage is the only
 * thing that survives that, so the read is destructive - an intent is claimed once and is gone
 * whether or not it turns out to be usable - and every access is guarded, because a browser with
 * storage disabled must degrade to "no intent" rather than throw.
 */
define([], function () {
    'use strict';

    var SS_KEY_NAVIGATE_INTENT = 'mago_navigate_intent';
    var NAVIGATE_INTENT_TTL_MS = 60000;

    // Only the target and the approved changes are worth carrying across the navigation this
    // directive is about to trigger; everything else (the field labels, the previous values) is
    // re-derived live from whatever form actually loads, the same way an ordinary confirmation
    // prompt already does, rather than trusted from before the navigation happened.
    function storeNavigateIntent(directive) {
        try {
            sessionStorage.setItem(SS_KEY_NAVIGATE_INTENT, JSON.stringify({
                target: directive.target,
                changes: directive.changes || [],
                expiresAt: Date.now() + NAVIGATE_INTENT_TTL_MS
            }));
        } catch (e) {}
    }

    // Reading and clearing happen together, deliberately: whatever this returns is the only chance
    // the intent ever gets. A page that finds one here has already consumed it, so a later reload,
    // a back button, or simply not having a matching form never hands the same intent out twice.
    function takeStoredNavigateIntent() {
        var raw;
        try {
            raw = sessionStorage.getItem(SS_KEY_NAVIGATE_INTENT);
            sessionStorage.removeItem(SS_KEY_NAVIGATE_INTENT);
        } catch (e) {
            return null;
        }
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function isExpiredNavigateIntent(intent) {
        return typeof intent.expiresAt !== 'number' || Date.now() > intent.expiresAt;
    }

    return {
        store: storeNavigateIntent,
        take: takeStoredNavigateIntent,
        isExpired: isExpiredNavigateIntent
    };
});
