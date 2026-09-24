define([
    'MagoAssistant_Mago/js/chat/text',
    'MagoAssistant_Mago/js/chat/i18n',
    'MagoAssistant_Mago/js/chat/navigate-intent',
    'MagoAssistant_Mago/js/chat/session-log',
    'MagoAssistant_Mago/js/chat/confirm-text'
], function (text, createTranslator, navigateIntent, createSessionLog, createConfirmText) {
    'use strict';

    var isPlainObject = text.isPlainObject;
    var esc = text.esc;
    var skillTitle = text.skillTitle;
    var toolLabel = text.toolLabel;
    var summarizeInput = text.summarizeInput;
    var formatDate = text.formatDate;
    var formatTime = text.formatTime;
    var escapeForMarkdown = text.escapeForMarkdown;
    var codeSpan = text.codeSpan;
    var previewValue = text.previewValue;

    var config = window.MAGO_CONFIG;
    var storeNavigateIntent = navigateIntent.store;
    var takeStoredNavigateIntent = navigateIntent.take;
    var isExpiredNavigateIntent = navigateIntent.isExpired;
    var translator = createTranslator(config);
    var t = translator.t;
    var entityLabel = translator.entityLabel;
    var describeEntity = translator.describeEntity;
    var fieldCountText = translator.fieldCountText;
    var confirmText = createConfirmText(function () { return formBridge; }, translator, text);
    var findLiveField = confirmText.findLiveField;
    var liveForm = confirmText.liveForm;
    var isNavigatingWrite = confirmText.isNavigatingWrite;
    var describeLiveForm = confirmText.describeLiveForm;
    var formatFieldChangeLine = confirmText.formatFieldChangeLine;
    var formatWriteFieldsHeading = confirmText.formatWriteFieldsHeading;
    var formatWriteFieldsConfirmMessage = confirmText.formatWriteFieldsConfirmMessage;
    var formatToolConfirmMessage = confirmText.formatToolConfirmMessage;
    var formatConfirmMessage = confirmText.formatConfirmMessage;
    var formatTargetDescription = confirmText.formatTargetDescription;
    var formatRefusalMessage = confirmText.formatRefusalMessage;
    var failedLabels = confirmText.failedLabels;
    var formatApplyOutcomeMessage = confirmText.formatApplyOutcomeMessage;
    var formKey = config.formKey;
    var skills = config.skills;
    var commands = config.commands || [];
    // Widget and skill-card builders (js/mago-ui.js); loaded before this file by panel.phtml.
    var UI = window.MagoUI;
    var conversationId = null;
    var busy = false;
    var showingHistory = false;
    var slashActive = false;
    var slashIndex = 0;
    var filteredItems = [];
    var SS_KEY_OPEN = 'mago_open';
    var SS_KEY_CONV = 'mago_conv';
    var SS_KEY_FULL = 'mago_fullsize';
    var RESUME_PARAM = 'mago_conv';
    var DIRECTIVE_TYPE_FORM_WRITE = 'form_write';
    var DIRECTIVE_TYPE_FORM_NAVIGATE = 'form_navigate';

    // A form_navigate directive (task 009) is only good for the one navigation it was issued for;
    // one minute is comfortably more than a real page load takes and short enough that nothing
    // left behind by a crashed tab or an abandoned confirmation can plausibly fire later. The form
    // itself is waited for separately, bounded by NAVIGATE_INTENT_FORM_TIMEOUT_MS below, since a UI
    // component form registers well after the page's own load event.
    var NAVIGATE_INTENT_FORM_TIMEOUT_MS = 8000;

    /* Long enough for a Page Builder stage to register on a slow admin, short enough that a form
       which never settles still sends promptly rather than appearing to hang. */
    var FORM_SETTLE_TIMEOUT_MS = 3000;
    var NAVIGATE_STATUS_CLASS = 'mago-navigate-status';

    // send() is synchronous and cannot await a module load, so the bridge is requested once at
    // startup and held here; a reference that is still null when send() runs means "no form",
    // never an exception. A stored navigate intent (task 009) is only ever consumed here, once the
    // bridge that can actually wait for the target form exists.
    var formBridge = null;
    require(['MagoAssistant_Mago/js/form-bridge'], function(bridge) {
        formBridge = bridge;
        applyStoredNavigateIntent();
    });

    // Every request the panel posts carries what form-bridge saw at that moment, so the backend
    // can resolve "this page"/"this field" in the administrator's message. No detection happens
    // here: a page without a form (or before the bridge has loaded) simply omits page_context. A
    // denied form (task 003) is the one exception to "omit when there is nothing to report": its
    // snapshot still carries no field, namespace or entity data, but the denied flag itself is
    // still sent, which is what lets the backend explain the refusal instead of claiming no form
    // is open at all.
    function buildPageContext(settled) {
        if (!formBridge) return null;
        var snapshot = formBridge.snapshot();
        if (!snapshot.hasForm && !snapshot.denied) return null;
        snapshot.route = window.location.pathname;
        // whenFieldsSettled reports settled === false when the field count never stopped moving
        // within the timeout: the form was still registering fields, so this list may be short a
        // few that had not appeared yet. Mark it truncated so the backend warns the model it cannot
        // see the whole form, exactly as it does for a count-capped snapshot. Any other caller
        // passes nothing (settled === undefined) and the snapshot stands as taken.
        if (settled === false && snapshot.hasForm && snapshot.truncated) {
            snapshot.truncated.fields = true;
        }
        return snapshot;
    }


    // apply() itself waits on a real signal (its form's provider component, task 009) before
    // writing a single field, so it reports its outcome through a callback rather than a return
    // value; onOutcome is always invoked exactly once, with null for a directive this dispatches on
    // nothing (an unknown type, or a form_navigate, whose outcome belongs to the page it navigates
    // to rather than this one).
    function applyFormDirective(directive, onOutcome) {
        if (!isPlainObject(directive) || !formBridge) {
            onOutcome(null);

            return;
        }

        if (directive.type === DIRECTIVE_TYPE_FORM_WRITE) {
            if (typeof formBridge.apply === 'function') {
                formBridge.apply(directive, onOutcome);
            } else {
                onOutcome(null);
            }

            return;
        }

        if (directive.type === DIRECTIVE_TYPE_FORM_NAVIGATE && directive.url) {
            storeNavigateIntent(directive);
            showNavigateStatus(t('Opening %1...', formatTargetDescription(directive.target)));
            window.location.href = directive.url;
        }

        onOutcome(null);
    }

    // The browser leaves for the target page the moment form_navigate arrives, but a product edit
    // page takes a few seconds to answer, and the target page then waits for its form to register
    // before anything is staged. Without this the panel simply goes quiet for that whole stretch.
    // The status is a message of its own, with the same spinner a running tool shows, and locks
    // the input for as long as it is up; on the page being left it also stays the last thing in
    // the panel when the model's reply and the "done" event land after it.
    function showNavigateStatus(text) {
        hideNavigateStatus();
        var el = document.createElement('div');
        el.className = 'mago-message is-assistant ' + NAVIGATE_STATUS_CLASS;
        el.innerHTML = '<div class="mago-tool-status"><span class="mago-tool-status-spinner"></span>'
            + '<span class="mago-tool-status-text">' + esc(text) + '</span></div>';
        msgs.insertBefore(el, loading);
        msgs.scrollTop = msgs.scrollHeight;
        lockInput();
    }

    function hideNavigateStatus() {
        var el = navigateStatusElement();
        if (!el) return;
        el.remove();
        unlockInput();
    }

    function navigateStatusElement() {
        return msgs.querySelector('.' + NAVIGATE_STATUS_CLASS);
    }

    function keepNavigateStatusLast() {
        var el = navigateStatusElement();
        if (!el) return;
        msgs.insertBefore(el, loading);
        msgs.scrollTop = msgs.scrollHeight;
        lockInput();
    }

    // The navigate status shows a spinner of its own, so the panel's own one stays hidden for as
    // long as the status holds the lock.
    function lockInput() {
        setBusy(true);
        loading.style.display = 'none';
    }

    // Every place a turn ends releases the input through here, so the end of the turn that started
    // a navigation does not re-enable it underneath the status the navigation is still showing.
    // The status itself is what releases the input, once the target page has staged its fields or
    // given up waiting for the form.
    function releaseInput() {
        loading.style.display = 'none';
        if (navigateStatusElement()) return;
        unlockInput();
    }

    function unlockInput() {
        setBusy(false);
    }









    function formatNavigateTimeoutMessage(target) {
        return t('Navigated to %1, but its form did not load in time. Nothing was changed. Ask me again now that the page is open.', formatTargetDescription(target));
    }

    // Runs once per page load (from the require() callback above, once form-bridge itself is
    // ready). A stored intent only ever names the page the assistant sent the administrator to; it
    // is proven against whatever form actually shows up here, not trusted, by replaying it as an
    // ordinary form_write directive through the exact same apply()/isSameTarget guard task 008
    // already built for a directive that arrives while its form is already open. A wrong-entity
    // landing is refused the same way, not applied and then explained away.
    function applyStoredNavigateIntent() {
        var intent = takeStoredNavigateIntent();
        if (!intent || !intent.target || !intent.target.entity_type) return;
        if (isExpiredNavigateIntent(intent)) return;
        if (!formBridge || typeof formBridge.whenFormReady !== 'function') return;

        var entityType = intent.target.entity_type;

        showNavigateStatus(t('Waiting for the form on %1...', formatTargetDescription(intent.target)));

        formBridge.whenFormReady(entityType, NAVIGATE_INTENT_FORM_TIMEOUT_MS, function (found) {
            if (!found) {
                hideNavigateStatus();
                addMsg('assistant', renderMd(formatNavigateTimeoutMessage(intent.target)));
                return;
            }

            formBridge.apply({
                type: DIRECTIVE_TYPE_FORM_WRITE,
                target: {
                    namespace: entityType + '_form',
                    entity_type: entityType,
                    entity_id: intent.target.entity_id || '',
                    store_id: intent.target.store_id || '',
                    is_new: intent.target.is_new === true
                },
                changes: intent.changes
            }, function (result) {
                hideNavigateStatus();
                reportApplyOutcome(result);
            });
        });
    }





    function reportApplyOutcome(result) {
        if (!result) return;
        var text = formatApplyOutcomeMessage(result);
        if (!text) return;
        addMsg('assistant', renderMd(text));
    }

    function saveState() {
        try {
            sessionStorage.setItem(SS_KEY_OPEN, chat.classList.contains('is-open') ? '1' : '0');
            sessionStorage.setItem(SS_KEY_CONV, conversationId ? String(conversationId) : '');
            sessionStorage.setItem(SS_KEY_FULL, chat.classList.contains('is-fullsize') ? '1' : '0');
        } catch(e) {}
    }

    function qs(s) { return document.querySelector(s); }
    var toggle = qs('#mago-toggle');
    var chat = qs('#mago-chat');
    var msgs = qs('#mago-messages');
    var input = qs('#mago-input');
    var loading = qs('#mago-loading');
    var sendBtn = qs('#mago-send');
    var histList = qs('#mago-history-list');
    var inputArea = qs('#mago-input-area');
    var slashMenu = qs('#mago-slash-menu');

    // A theme without the header container renders the panel without its
    // toggle, so bail out before anything binds to a missing element.
    if (!toggle || !chat) {
        return;
    }

    function clearMsgs() {
        var hadNavigateStatus = !!navigateStatusElement();
        var nodes = msgs.querySelectorAll('.mago-message, .mago-date-sep');
        for (var i = 0; i < nodes.length; i++) nodes[i].remove();
        loading.style.display = 'none';

        // Starting a new chat or loading another conversation throws the navigate status away with
        // everything else, and the lock it holds on the input has to go with it, or the panel is
        // left unable to send anything.
        if (hadNavigateStatus) unlockInput();
    }

    // The header subtitle names the conversation being viewed; empty on a fresh chat.
    function setSubtitle(text) {
        var el = qs('#mago-subtitle');
        if (el) el.textContent = text || '';
    }

    // Busy drives the "Working" pill in the header and the send button state.
    function setBusy(state) {
        busy = state;
        chat.classList.toggle('is-busy', state);
        loading.style.display = state ? '' : 'none';
        sendBtn.disabled = state;
    }

    // The header icon is the only way in or out of the panel, so its tooltip,
    // aria state and active style all have to follow whatever it will do next.
    function syncToggleLabel() {
        var open = chat.classList.contains('is-open');
        var label = open ? toggle.getAttribute('data-label-close') : toggle.getAttribute('data-label-open');
        if (label) {
            toggle.title = label;
        }
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('is-active', open);
    }

    function openPanel() {
        chat.classList.add('is-open');
        document.body.classList.add('mago-active');
        syncToggleLabel();
        if (msgs.children.length <= 1 && !conversationId) {
            showGreeting();
        }
        saveState();
        input.focus();
    }

    function closePanel() {
        chat.classList.remove('is-open', 'is-fullsize');
        document.body.classList.remove('mago-active', 'mago-fullsize');
        var expandBtn = qs('#mago-expand');
        expandBtn.title = 'Full size';
        expandBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
        syncToggleLabel();
        if (showingHistory) hideHistory();
        if (showingLog) hideLog();
        hideSlashMenu();
        saveState();
    }

    toggle.onclick = function() {
        if (chat.classList.contains('is-open')) {
            closePanel();
        } else {
            openPanel();
        }
    };
    qs('#mago-close').onclick = closePanel;
    qs('#mago-expand').onclick = function() {
        var isFullsize = chat.classList.toggle('is-fullsize');
        document.body.classList.toggle('mago-fullsize', isFullsize);
        var expandBtn = qs('#mago-expand');
        if (isFullsize) {
            expandBtn.title = 'Side panel';
            expandBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
        } else {
            expandBtn.title = 'Full size';
            expandBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
        }
        saveState();
    };
    qs('#mago-new').onclick = function() {
        if (showingHistory) hideHistory();
        if (showingLog) hideLog();
        clearMsgs();
        conversationId = null;
        showGreeting();
        saveState();
    };
    qs('#mago-history').onclick = function() {
        if (showingHistory) {
            hideHistory();
        } else {
            loadHistory();
        }
    };

    // Slash menu: two kinds of entries. Commands ("/cache flush") run directly against
    // Magento and are sent as typed; skills expand to a prompt for the assistant.
    var slashItems = [];
    commands.forEach(function(c) {
        c.subcommands.forEach(function(sub) {
            slashItems.push({
                type: 'command',
                command: c.name,
                full: c.name + ' ' + sub.name,
                label: '/' + c.name + ' ' + sub.name + (sub.args ? ' ' + sub.args : ''),
                description: sub.description,
                readOnly: sub.readOnly
            });
        });
    });
    skills.forEach(function(s) {
        slashItems.push({
            type: 'skill',
            full: s.name,
            label: '/' + s.name,
            description: s.description,
            readOnly: s.readOnly,
            skill: s
        });
    });

    function matchesSlashItem(item, q) {
        if (!q) return true;
        if (item.type === 'command') {
            // Once arguments follow the command the entry drops out, so Enter sends instead of completing.
            return item.full.indexOf(q) !== -1;
        }
        return item.full.indexOf(q) !== -1 || item.description.toLowerCase().indexOf(q) !== -1;
    }

    function showSlashMenu(filter) {
        var q = (filter || '').toLowerCase();
        filteredItems = slashItems.filter(function(item) { return matchesSlashItem(item, q); });
        if (!filteredItems.length) {
            hideSlashMenu();
            return;
        }
        slashIndex = 0;
        slashActive = true;
        slashMenu.innerHTML = '';
        // S14 skill menu: one row per entry with its risk colour. Commands and skills
        // get a group heading inside the one menu when both kinds survive the filter.
        var hasCommands = filteredItems.some(function(i) { return i.type === 'command'; });
        var hasSkills = filteredItems.some(function(i) { return i.type === 'skill'; });
        slashMenu.appendChild(UI.skillMenu({
            itemClass: 'mago-slash-item',
            title: hasCommands ? (hasSkills ? 'Commands and skills' : 'Commands') : 'Skills',
            skills: filteredItems.map(function(item) {
                return {
                    name: item.full,
                    title: item.label,
                    description: item.description,
                    risk: item.readOnly ? 'read' : 'write',
                    group: hasCommands && hasSkills ? (item.type === 'command' ? 'Commands' : 'Skills') : null
                };
            }),
            onSelect: function(entry, i) { selectSlashItem(filteredItems[i]); }
        }));
        slashMenu.classList.add('is-visible');
    }




    // S12: every write the assistant ran (or skipped, or that failed) in this browser session,
    // kept in sessionStorage so it survives the page loads an admin makes between questions.
    var logBtn = qs('#mago-log');
    var logView = qs('#mago-log-view');
    var sessionLog = createSessionLog(logBtn, UI);
    var showingLog = false;



    function showLog() {
        if (!logView) return;
        if (showingHistory) hideHistory();
        showingLog = true;
        var entries = sessionLog.read();
        logView.innerHTML = '';
        logView.appendChild(sessionLog.render(entries));
        msgs.style.display = 'none';
        loading.style.display = 'none';
        inputArea.style.display = 'none';
        logView.style.display = '';
        if (logBtn) logBtn.classList.add('is-active');
    }

    function hideLog() {
        if (!logView) return;
        showingLog = false;
        logView.style.display = 'none';
        msgs.style.display = '';
        inputArea.style.display = '';
        if (logBtn) logBtn.classList.remove('is-active');
    }

    if (logBtn) {
        logBtn.onclick = function() { if (showingLog) hideLog(); else showLog(); };
        logBtn.classList.toggle('has-entries', sessionLog.hasEntries());
    }

    function hideSlashMenu() {
        slashActive = false;
        slashMenu.classList.remove('is-visible');
        slashMenu.innerHTML = '';
    }

    function selectSlashItem(item) {
        input.value = item.type === 'command'
            ? '/' + item.full + ' '
            : 'Use the ' + item.skill.name + ' skill to ';
        autoGrow();
        hideSlashMenu();
        input.focus();
    }

    // The input already holds a complete command ("/cache flush") or a bare command name
    // ("/cache", which the backend answers with its usage), so Enter should send, not complete.
    function isTypedCommand(item) {
        if (!item || item.type !== 'command') return false;
        var typed = input.value.trim().toLowerCase();
        return typed === '/' + item.full || typed === '/' + item.command;
    }

    function updateSlashHighlight() {
        var items = slashMenu.querySelectorAll('.mago-slash-item');
        items.forEach(function(el, i) {
            el.classList.toggle('is-active', i === slashIndex);
        });
        if (items[slashIndex]) items[slashIndex].scrollIntoView({block:'nearest'});
    }

    function autoGrow() {
        var MAX = 240;
        // border-box: scrollHeight excludes the border, so add it back or the
        // field ends up 2px short and shows a scrollbar on a single line.
        var border = input.offsetHeight - input.clientHeight;
        input.style.height = 'auto';
        var full = input.scrollHeight + border;
        input.style.height = Math.min(full, MAX) + 'px';
        input.style.overflowY = full > MAX ? 'auto' : 'hidden';
    }

    // Privacy mode (#97 decision 3): non-blocking hint for FP-prone patterns (postcode, loose digit
    // runs) that the server-side scrub deliberately leaves alone; email/IBAN/BSN/VAT/phone are
    // tokenised server-side regardless. Never blocks sending.
    var privacyHint = qs('#mago-privacy-hint');
    var privacyHintPatterns = [
        /\b\d{4}\s?[A-Za-z]{2}\b/,
        /(?:\d[\s\-]?){10,}/,
        /[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i
    ];
    function updatePrivacyHint(v) {
        if (!privacyHint) return;
        var hit = v.length > 5 && privacyHintPatterns.some(function(p) { return p.test(v); });
        privacyHint.hidden = !hit;
    }

    input.addEventListener('input', function() {
        autoGrow();
        var v = input.value;
        sendBtn.classList.toggle('is-idle', !v.trim());
        updatePrivacyHint(v);
        if (v.charAt(0) === '/') {
            var filter = v.substring(1);
            showSlashMenu(filter);
        } else {
            hideSlashMenu();
        }
    });

    input.onkeydown = function(e) {
        if (slashActive) {
            if (e.keyCode === 38) { // up
                e.preventDefault();
                slashIndex = Math.max(0, slashIndex - 1);
                updateSlashHighlight();
                return;
            }
            if (e.keyCode === 40) { // down
                e.preventDefault();
                slashIndex = Math.min(filteredItems.length - 1, slashIndex + 1);
                updateSlashHighlight();
                return;
            }
            if (e.keyCode === 13 && isTypedCommand(filteredItems[slashIndex])) { // enter on a complete command runs it
                e.preventDefault();
                hideSlashMenu();
                send();
                return;
            }
            if (e.keyCode === 13 || e.keyCode === 9) { // enter or tab
                e.preventDefault();
                if (filteredItems[slashIndex]) selectSlashItem(filteredItems[slashIndex]);
                return;
            }
            if (e.keyCode === 27) { // escape
                e.preventDefault();
                hideSlashMenu();
                return;
            }
        }
        if (e.keyCode === 13 && !e.shiftKey) { e.preventDefault(); send(); }
    };
    sendBtn.onclick = send;



    function loadHistory() {
        if (showingLog) hideLog();
        showingHistory = true;
        msgs.style.display = 'none';
        loading.style.display = 'none';
        inputArea.style.display = 'none';
        histList.style.display = '';
        histList.innerHTML = '<div style="padding:16px;color:#999;font-size:13px;">Loading...</div>';

        fetch(config.historyUrl, {
            headers: {'X-Requested-With':'XMLHttpRequest'},
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            histList.innerHTML = '';
            if (!data.conversations || !data.conversations.length) {
                histList.innerHTML = '<div style="padding:16px;color:#999;font-size:13px;">No previous chats</div>';
                return;
            }
            data.conversations.forEach(function(conv) {
                var item = document.createElement('div');
                item.className = 'mago-history-item';
                item.innerHTML = '<span class="mago-history-item-title">' + esc(conv.title || 'Untitled') + '</span>'
                    + '<span class="mago-history-item-date">' + formatDate(conv.updated_at) + '</span>'
                    + '<button type="button" class="mago-history-item-delete" title="Delete">'
                    + '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
                    + '</button>';

                item.querySelector('.mago-history-item-title').onclick = function() {
                    loadConversation(conv.entity_id, conv.title);
                };
                item.querySelector('.mago-history-item-delete').onclick = function(e) {
                    e.stopPropagation();
                    deleteConversation(conv.entity_id, item);
                };
                histList.appendChild(item);
            });
        })
        .catch(function() {
            histList.innerHTML = '<div style="padding:16px;color:#e22626;font-size:13px;">Failed to load history</div>';
        });
    }

    function hideHistory() {
        showingHistory = false;
        histList.style.display = 'none';
        msgs.style.display = '';
        inputArea.style.display = '';
    }

    function loadConversation(id, title) {
        hideHistory();
        clearMsgs();
        lastDateLabel = '';
        conversationId = id;
        chat.classList.remove('is-empty');
        setSubtitle(title || '');
        loading.style.display = '';

        var fd = new FormData();
        fd.append('form_key', formKey);
        fd.append('conversation_id', id);

        fetch(config.loadUrl, {
            method: 'POST',
            headers: {'X-Requested-With':'XMLHttpRequest'},
            body: fd,
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            var loaded = data.messages || [];
            if (!loaded.length) {
                showGreeting();
                return;
            }
            // Tool calls live on the assistant row that ran them, but the answer they produced is
            // the next row, so they are carried forward until there is a bubble to hang them on.
            var pendingTools = [];
            loaded.forEach(function(m) {
                var tools = parseToolCalls(m);
                if (m.role === 'assistant' && tools.length) {
                    pendingTools = pendingTools.concat(tools);
                }

                var isConfirmRow = m.role === 'assistant' && !(m.content || '').trim() && tools.length;
                if (isConfirmRow) {
                    addDateSep(m.created_at);
                    // No timestamp: live this bubble only ever holds the card, and the time of the
                    // turn is printed under the answer that follows it.
                    var confirmEl = addMsg('assistant', '');
                    replayTools(confirmEl, pendingTools, tools);
                    pendingTools = [];
                    if (parseInt(m.pending_confirmation, 10) === 1) {
                        showConfirmButtons(confirmEl, {messageId: m.entity_id}, tools);
                    } else {
                        restoreWriteResult(confirmEl, tools);
                    }
                } else if ((m.role === 'user' || m.role === 'assistant') && (m.content || '').trim()) {
                    addDateSep(m.created_at);
                    // No timestamp: a live bubble never carries one, and a reloaded conversation
                    // that grows them is not the same conversation the admin was just looking at.
                    var msgEl = addMsg(m.role, renderMd(m.content));
                    if (m.role === 'assistant' && pendingTools.length) {
                        replayTools(msgEl, pendingTools);
                        pendingTools = [];
                    }
                }
            });
            saveState();
        })
        .catch(function() {
            loading.style.display = 'none';
            conversationId = null;
            showGreeting();
            saveState();
        });
    }

    function deleteConversation(id, el) {
        var fd = new FormData();
        fd.append('form_key', formKey);
        fd.append('conversation_id', id);

        fetch(config.deleteUrl, {
            method: 'POST',
            headers: {'X-Requested-With':'XMLHttpRequest'},
            body: fd,
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function() {
            el.remove();
            if (conversationId === id) {
                conversationId = null;
                clearMsgs();
            }
        });
    }

    // Model output is attacker-influenceable, so a link target is only ever http(s) or a local
    // path, with quotes neutralised so it cannot break out of the href attribute.
    function safeHref(href) {
        href = String(href || '').replace(/[\n\r]/g, '');
        if (!/^(https?:\/\/|\/)/i.test(href)) return '#';
        return href.replace(/"/g, '%22');
    }

    // sessionStorage does not follow a noopener link into a new tab, so admin links carry the id.
    function withResumeParam(href) {
        var adminBase = String(config.adminBaseUrl || '');
        var adminPath = adminBase.replace(/^https?:\/\/[^/]+/i, '');
        if (!conversationId || href === '#') return href;
        var isAdmin = (adminBase && href.indexOf(adminBase) === 0)
            || (adminPath && href.indexOf(adminPath) === 0);
        if (!isAdmin || href.indexOf(RESUME_PARAM + '=') !== -1) return href;
        var hashIdx = href.indexOf('#');
        var hash = hashIdx === -1 ? '' : href.substring(hashIdx);
        var base = hashIdx === -1 ? href : href.substring(0, hashIdx);
        return base + (base.indexOf('?') === -1 ? '?' : '&') + RESUME_PARAM + '=' + conversationId + hash;
    }

    // Configure marked.js once if available
    if (window.marked) {
        var markedRenderer = new marked.Renderer();
        markedRenderer.link = function(href, title, text) {
            if (typeof href === 'object' && href !== null) { text = href.text; title = href.title; href = href.href; }
            href = withResumeParam(safeHref(href));
            var isAdmin = href.indexOf('/admin') !== -1 || href.charAt(0) === '/';
            var target = isAdmin ? '_self' : '_blank';
            var titleAttr = title ? ' title="' + String(title).replace(/"/g, '&quot;') + '"' : '';
            return '<a href="' + href + '" target="' + target + '" rel="noopener"' + titleAttr + '>' + text + '</a>';
        };
        markedRenderer.table = function(token) {
            // Render using the default logic but wrap in a scrollable div
            var html = marked.Renderer.prototype.table.call(this, token);
            return '<div class="mago-table-wrap">' + html + '</div>';
        };
        // A ```mago fenced block holds a widget spec ({"type": "stat", ...} or a
        // list of them) and renders as the matching widget. While the block is
        // still streaming in, the JSON is incomplete and a skeleton holds its place.
        markedRenderer.code = function(token) {
            var lang = typeof token === 'object' ? token.lang : arguments[1];
            if (lang === 'mago' && UI) {
                var code = typeof token === 'object' ? token.text : token;
                return UI.renderJson(code) || UI.skeleton().outerHTML;
            }
            return marked.Renderer.prototype.code.apply(this, arguments);
        };
        marked.use({ renderer: markedRenderer, gfm: true, breaks: true });
    }

    function renderMd(t) {
        if (!t) return '';
        // Model output is attacker-influenceable (tool results can carry injected instructions), so
        // raw HTML must never reach innerHTML: escape first, then let marked render markdown only.
        var safe = esc(t);
        if (window.marked) {
            return marked.parse(safe);
        }
        // Fallback: simple regex-based renderer
        var h = safe;
        h = h.replace(/```(\w*)\n([\s\S]*?)```/g, function(m,l,c){ return '<pre><code>'+c.trim()+'</code></pre>'; });
        h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
        h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        h = h.replace(/\*(.+?)\*/g, '<em>$1</em>');
        h = h.replace(/\[([^\]]+)\]\(((?:https?:\/\/[^ )]+|\/[^ )]+))\)/g, function(m, text, url) {
            url = withResumeParam(safeHref(url));
            var isAdmin = url.indexOf('/admin') !== -1 || url.charAt(0) === '/';
            var target = isAdmin ? '_self' : '_blank';
            return '<a href="' + url + '" target="' + target + '" rel="noopener">' + text + '</a>';
        });
        h = h.replace(/(https?:\/\/[^ <\n]+)/g, function(m, url, offset) {
            var before = h.substring(Math.max(0, offset - 6), offset);
            if (before.indexOf('href=') !== -1 || before.indexOf('">') !== -1) return m;
            var isAdmin = url.indexOf('/admin') !== -1;
            var target = isAdmin ? '_self' : '_blank';
            return '<a href="' + withResumeParam(safeHref(url)) + '" target="' + target + '" rel="noopener">' + url + '</a>';
        });
        h = h.replace(/\n\n/g, '</p><p>');
        h = h.replace(/\n/g, '<br>');
        return '<p>' + h + '</p>';
    }

    var lastDateLabel = '';

    function addDateSep(dateStr) {
        if (!dateStr) return;
        var d = new Date(dateStr.replace(' ', 'T') + 'Z');
        var label = d.toLocaleDateString([], {weekday:'short', month:'short', day:'numeric'});
        var today = new Date();
        if (d.toDateString() === today.toDateString()) label = 'Today';
        var yest = new Date(today); yest.setDate(yest.getDate()-1);
        if (d.toDateString() === yest.toDateString()) label = 'Yesterday';
        if (label !== lastDateLabel) {
            lastDateLabel = label;
            var sep = document.createElement('div');
            sep.className = 'mago-date-sep';
            sep.innerHTML = '<span>' + esc(label) + '</span>';
            msgs.insertBefore(sep, loading);
        }
    }


    function addMsg(role, html, timestamp) {
        var cls = role === 'user' ? 'is-user' : 'is-assistant';
        var timeHtml = timestamp ? '<div class="mago-msg-time">' + esc(formatTime(timestamp)) + '</div>' : '';
        var div = document.createElement('div');
        div.className = 'mago-message ' + cls;
        // Tool tags, then the read-only trace (S06), then the answer itself, as in
        // the design: what Mago looked up sits above what it concluded.
        div.innerHTML = '<div class="mago-tool-tags"></div>'
            + '<div class="mago-tool-trace mago-trace is-tight"></div>'
            + '<div class="mago-message-content">' + html + '</div>' + timeHtml;
        chat.classList.remove('is-empty');
        msgs.insertBefore(div, loading);
        msgs.scrollTop = msgs.scrollHeight;
        return div;
    }

    // S06: a read-only call is one quiet line above the answer — spinner while
    // it runs, a check when it is done. The lines stay for as long as the answer
    // is on screen, so it is always clear what Mago looked up.
    function updateToolStatus(msgEl, toolName, status, message) {
        if (status === 'running') {
            var el = UI.readLine({text: message || ('Running ' + toolName + '…'), tool: toolName, state: 'active'});
            el.classList.add('mago-tool-status');
            el.setAttribute('data-tool', toolName);
            var trace = msgEl.querySelector('.mago-tool-trace');
            if (trace) {
                trace.appendChild(el);
            } else {
                msgEl.querySelector('.mago-message-content').before(el);
            }
            msgs.scrollTop = msgs.scrollHeight;
        } else if (status === 'done' || status === 'failed') {
            var statusEl = msgEl.querySelector('.mago-tool-status[data-tool="' + toolName + '"]:not(.is-done)');
            if (statusEl) {
                statusEl.magoSetState(status);
                statusEl.classList.add('mago-tool-status', 'mago-readline', 'is-done');
                if (status === 'failed' && message) {
                    statusEl.title = message;
                }
            }
        }
    }

    // Answer widgets arrive as static HTML from the markdown renderer, so their
    // interactions are wired here once: a chip or suggestion card asks its label,
    // a value prompt (S10) sends the value the admin typed or picked.
    function askFromWidget(text) {
        if (!text || busy) return;
        input.value = text;
        input.dispatchEvent(new Event('input'));
        send();
    }

    msgs.addEventListener('click', function(e) {
        var scope = e.target.closest('.mago-message-content');
        if (!scope) return;
        var chip = e.target.closest('.mago-chip, .mago-suggestion:not([href])');
        if (chip) {
            e.preventDefault();
            askFromWidget(chip.textContent.trim());
            return;
        }
        var submit = e.target.closest('.mago-field-row .mago-btn');
        if (submit) {
            var field = submit.parentNode.querySelector('.mago-field-input');
            if (field && field.value.trim()) askFromWidget(field.value.trim());
        }
    });

    msgs.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.classList.contains('mago-field-input') && e.target.closest('.mago-message-content')) {
            e.preventDefault();
            if (e.target.value.trim()) askFromWidget(e.target.value.trim());
        }
    });

    // An answered write comes back as the card it ended on: the S03 line with its request and
    // duration, the S04 card when it failed, or a muted line when it was declined. Without the
    // stored outcome there is nothing to tell these apart, so the row stays as it was asked.
    function restoreWriteResult(msgEl, tools) {
        var first = tools[0] || null;
        if (!first || !first.status) return;

        var title = skillTitle(first.name);
        var failed = tools.filter(function(t) { return t.status === 'failed'; })[0];
        if (failed) {
            msgEl.appendChild(UI.skillFailed({
                title: title + ' failed',
                text: failed.status_error || 'The action did not complete.',
                code: tools.length === 1 ? failed.name : null
            }));
            return;
        }

        // The live card sums every tool in the run; the restored one has to add up the same way
        // or a bulk confirmation comes back showing only its first action's time.
        var total = tools.reduce(function(sum, t) { return sum + (parseInt(t.status_duration_ms, 10) || 0); }, 0);
        var single = tools.length === 1 ? first : null;
        msgEl.appendChild(UI.skillLine({
            title: title,
            action: single && single.input ? single.input.action : null,
            duration: formatDuration(total),
            state: first.status === 'skipped' ? 'skipped' : 'done',
            request: single ? single.input : undefined
        }));
    }

    // The live card measures in the browser and prints one decimal with a comma; a restored one
    // reads the server's milliseconds and has to land on the same shape.
    function formatDuration(ms) {
        var value = parseInt(ms, 10);
        if (!value) return undefined;

        return (value / 1000).toFixed(1).replace('.', ',') + 's';
    }

    // Draw a stored row's tools the way the live stream drew them: the tag, then the read-only
    // line with the outcome it ended on. A call that was denied or left unticked stored no status
    // and gets no line, exactly as it had none while the answer streamed.
    function replayTools(msgEl, tools, cardTools) {
        tools.forEach(function(t) {
            if (!t.name) return;
            addToolTag(msgEl, t.name);
            // A write is drawn as its own result card, which is what it collapsed into live; a
            // read line beside it would be a step the admin never saw.
            if (cardTools && cardTools.indexOf(t) !== -1) return;
            if (t.status !== 'done' && t.status !== 'failed') return;
            updateToolStatus(msgEl, t.name, 'running', t.status_message);
            updateToolStatus(msgEl, t.name, t.status, t.status_error);
        });
    }

    // Stored tool calls come back as a JSON string from the database and as an array from the
    // stream, and a half-written row can hold neither.
    function parseToolCalls(m) {
        if (!m || !m.tool_calls) return [];
        try {
            var tc = typeof m.tool_calls === 'string' ? JSON.parse(m.tool_calls) : m.tool_calls;
            return Array.isArray(tc) ? tc : [];
        } catch (e) {
            return [];
        }
    }

    function addToolTag(msgEl, toolName) {
        var tags = msgEl.querySelector('.mago-tool-tags');
        if (!tags) return;
        var tag = document.createElement('span');
        tag.className = 'mago-tool-tag';
        tag.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'
            + esc(toolName);
        tags.appendChild(tag);
    }

    // Errors land as a callout (W18) under whatever text already streamed.
    function showError(msgEl, text) {
        msgEl.appendChild(UI.callout({tone: 'danger', text: text || 'Unknown error'}));
        msgs.scrollTop = msgs.scrollHeight;
    }















    function showGreeting() {
        chat.classList.add('is-empty');
        setSubtitle('');
    }

    // Welcome starters drop the skill into the input so the slash menu takes over.
    var starters = chat.querySelectorAll('.mago-starter');
    for (var si = 0; si < starters.length; si++) {
        starters[si].onclick = function() {
            var name = this.getAttribute('data-skill');
            if (!name) return;
            input.value = '/' + name + ' ';
            input.dispatchEvent(new Event('input'));
            input.focus();
        };
    }

    function send() {
        var text = input.value.trim();
        if (!text || busy) return;
        hideSlashMenu();
        input.value = '';
        sendBtn.classList.add('is-idle');
        autoGrow();
        updatePrivacyHint('');
        setBusy(true);
        addMsg('user', renderMd(text));

        var msg = null;
        var content = null;
        var full = '';

        /* The form's fields register over several ticks, and a Page Builder field only once its
           stage has initialised. Sending straight away describes a form that is genuinely missing
           fields, and nothing downstream can tell that from a form that really has none, so the
           request waits for the count to stop moving. Bounded, because a form that never settles
           must not hold the message hostage: the snapshot is sent as-is and reports itself early. */
        if (!formBridge) {
            postMessage();

            return;
        }

        formBridge.whenFieldsSettled(FORM_SETTLE_TIMEOUT_MS, postMessage);

        function postMessage(settled) {
        fetch(config.streamUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({message:text, conversation_id:conversationId, form_key:formKey, page_context:buildPageContext(settled)}),
            credentials: 'same-origin'
        }).then(function(r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('text/event-stream') === -1) {
                return r.text().then(function(t) {
                    throw new Error('Expected SSE but got: ' + ct.substring(0, 50));
                });
            }
            var reader = r.body.getReader();
            var dec = new TextDecoder();
            var buf = '', evt = '';
            var gotDone = false;
            var writeToolDetected = false;
            var applyResult = null;
            var applyPending = false;
            var doneReached = false;

            // apply() now waits on a real signal (its form's provider component, task 009) before
            // writing anything, so its outcome can arrive after the "done" event that already ended
            // the turn. Whichever of the two happens second is what reports it, so the outcome
            // message still lands after the model's own reply either way, exactly as it did when
            // apply() was synchronous.
            function handleApplyOutcome(result) {
                applyPending = false;
                applyResult = result;

                if (doneReached) {
                    reportApplyOutcome(applyResult);
                    applyResult = null;
                }
            }

            function processLine(ln) {
                ln = ln.trim();
                if (ln.indexOf('event: ')===0) { evt=ln.substring(7); }
                else if (ln.indexOf('data: ')===0) {
                    try { var d=JSON.parse(ln.substring(6)); } catch(e){return;}
                    if (evt==='text'&&d.text) {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        full+=d.text; content.innerHTML=renderMd(full); msgs.scrollTop=msgs.scrollHeight;
                    }
                    else if (evt==='conversation') { conversationId=d.conversation_id; saveState(); }
                    else if (evt==='tool_call') {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        addToolTag(msg, d.name);
                    }
                    else if (evt==='tool_status') {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        updateToolStatus(msg, d.name, d.status, d.message);
                    }
                    else if (evt==='form_apply') { applyPending = true; applyFormDirective(d, handleApplyOutcome); }
                    else if (evt==='confirm') {
                        writeToolDetected = true;
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        releaseInput();
                        showConfirmButtons(msg, {conversationId: conversationId}, d.tools || []);
                    }
                    else if (evt==='done') {
                        gotDone = true;
                        if(d.conversation_id) conversationId=d.conversation_id;
                        saveState(); releaseInput();
                        if (d.pending_confirmation && msg && !writeToolDetected) {
                            showConfirmButtons(msg, {messageId: d.message_id, conversationId: conversationId}, []);
                        }
                        if (!d.pending_confirmation && writeToolDetected) {
                            writeToolDetected = false;
                        }
                        doneReached = true;
                        if (!applyPending) {
                            reportApplyOutcome(applyResult);
                            applyResult = null;
                        }
                        keepNavigateStatusLast();
                    }
                    else if (evt==='error') {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        showError(msg, d.error);
                    }
                }
            }

            function read(res) {
                buf += res.done ? dec.decode() : dec.decode(res.value, {stream:true});
                var lines = buf.split('\n'); buf = res.done ? '' : lines.pop();
                lines.forEach(processLine);
                if (res.done || gotDone) {
                    releaseInput();
                    return;
                }
                return reader.read().then(read);
            }
            return reader.read().then(read);
        }).catch(function(e) {
            releaseInput();
            if (!msg) { msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
            showError(msg, 'Connection error: ' + e.message);
        });
        }
    }

    // S01: a write action asks first. The card names the skill, lists the
    // parameters it will run with and offers Allow / Not now. Once allowed it
    // turns into the S02 progress card, and when the run finishes into the S03
    // collapsed line; "Not now" leaves a muted line and no call is made.
    function isFormWrite(tool) {
        return !!tool && tool.name === 'page_form' && !!tool.input && tool.input.action === 'write_fields';
    }

    // ids = {messageId, conversationId}: the confirm endpoints key on the message that asked,
    // so a caller that already knows it says so and the rest is looked up from the conversation.
    function showConfirmButtons(msgEl, ids, tools) {
        ids = ids || {};
        // Prevent duplicate confirm cards
        if (msgEl.querySelector('.mago-confirm-actions')) return;

        tools = tools || [];
        var first = tools[0] || null;
        var title = first ? skillTitle(first.name) : 'Confirm action';
        var text = first && first.description ? first.description : 'I want to perform an action. Allow this?';

        /* A form write is the one confirmation the tool's own description cannot describe: what
           matters is which fields change and from what, read off the form open right now. That
           sentence is built in chat/confirm-text.js and rendered as markdown here, so it replaces
           both the description and the parameter table, which would otherwise show the raw
           directive JSON. Every other tool keeps the generic card. */
        var formWriteMessage = isFormWrite(first)
            ? {html: renderMd(formatConfirmMessage(tools))}
            : null;
        var hooks = {actions: 'mago-confirm-actions', allow: 'mago-btn--confirm', confirm: 'mago-btn--confirm', later: 'mago-btn--reject', cancel: 'mago-btn--reject'};
        var irreversible = tools.filter(function(t) { return t.irreversible; });
        var card;

        if (tools.length > 1) {
            // S08: several writes in one turn become a tick list; only the ticked ones run.
            title = tools.length + ' actions';
            card = UI.skillBulk({
                title: title,
                text: 'Choose which of these to run.',
                notice: irreversible.length ? UI.callout({tone: 'danger', text: irreversible.length + ' of these cannot be undone: ' + irreversible.map(function(t) { return toolLabel(t); }).join(', ') + '.'}) : null,
                items: tools.map(function(t, i) {
                    return {id: t.id || String(i), label: toolLabel(t), meta: summarizeInput(t.input)};
                }),
                confirmLabel: function(n) { return 'Run ' + n; },
                classes: hooks,
                onConfirm: function(ids) { decide(true, ids); },
                onLater: function() { decide(false); }
            });
        } else if (first && first.irreversible) {
            // S07: the write has no undo, so it asks with its impact list and a ticked acknowledgement.
            card = UI.skillIrreversible({
                title: title,
                tool: first.name,
                text: text,
                params: UI.paramsFromInput(first.input),
                impacts: first.impacts || [],
                ackLabel: 'I understand this cannot be undone',
                confirmLabel: 'Allow',
                classes: hooks,
                onConfirm: function() { decide(true); },
                onCancel: function() { decide(false); }
            });
        } else {
            // S01: a reversible write asks with its parameters and a plain Allow.
            card = UI.skillAsk({
                title: title,
                tool: first ? first.name : null,
                text: formWriteMessage || text,
                params: formWriteMessage || !first ? [] : UI.paramsFromInput(first.input),
                classes: hooks,
                onAllow: function() { decide(true); },
                onLater: function() { decide(false); }
            });
        }
        var actions = card.querySelector('.mago-confirm-actions');
        msgEl.appendChild(card);
        msgs.scrollTop = msgs.scrollHeight;

        function decide(allowed, selectedIds) {
            actions.innerHTML = '';
            actions.appendChild(UI.spinner());
            getMessageId(function(mid) {
                if (allowed) {
                    var running = UI.skillRunning({title: title, progress: 30});
                    card.replaceWith(running);
                    var run = {card: running, title: title, tools: tools, startedAt: Date.now()};
                    if (selectedIds) {
                        run.selected = selectedIds;
                        run.skipped = tools.filter(function(t, i) { return selectedIds.indexOf(t.id || String(i)) === -1; });
                    }
                    handleConfirm(mid, run);
                } else {
                    card.replaceWith(UI.skillLine({title: title, action: first && first.input ? first.input.action : null, state: 'skipped'}));
                    sessionLog.write(title, first && first.input ? first.input.action : null, 'skipped');
                    handleReject(mid);
                }
            });
        }

        function getMessageId(callback) {
            var messageId = parseInt(ids.messageId, 10);
            if (messageId) {
                callback(messageId);
                return;
            }

            var conversationId = parseInt(ids.conversationId, 10);
            if (!conversationId) {
                failLookup('The action could not be linked to this conversation.');
                return;
            }

            fetch(config.statusUrl, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify({conversation_id: conversationId, form_key: formKey}),
                credentials: 'same-origin'
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (d.message_id) {
                    callback(d.message_id);
                } else {
                    // Retry after 1s — DB write may not be done yet
                    setTimeout(function() { getMessageId(callback); }, 1000);
                }
            }).catch(function() {
                setTimeout(function() { getMessageId(callback); }, 1000);
            });
        }

        // The spinner replaced the buttons, so a lookup that cannot resolve has to hand the card
        // back rather than sit there: the write never ran and the admin can still ask again.
        function failLookup(text) {
            card.replaceWith(UI.skillFailed({
                title: title + ' failed',
                text: text,
                code: first ? first.name : null
            }));
            setBusy(false);
        }
    }

    // run = {card, title, tools, startedAt}: the S02 card that replaced the
    // question; tool_status events feed its step list and "done" collapses it.
    function handleConfirm(messageId, run) {
        setBusy(true);
        var msg = null, content = null, full = '';

        var failureMessage = '';

        // The S02 card ends as the S03 line (done) or the S04 card (failed); the log gets a row either way.
        function finishRun(state) {
            if (!run || !run.card || !run.card.parentNode) return;
            var first = run.tools && run.tools[0];
            var action = run.tools && run.tools.length === 1 && first && first.input ? first.input.action : null;
            var seconds = run.durationMs
                ? formatDuration(run.durationMs)
                : ((Date.now() - run.startedAt) / 1000).toFixed(1).replace('.', ',') + 's';
            if (state === 'failed') {
                run.card.replaceWith(UI.skillFailed({
                    title: run.title + ' failed',
                    text: failureMessage || 'The action did not complete.',
                    code: first && run.tools.length === 1 ? first.name : null
                }));
            } else {
                run.card.replaceWith(UI.skillLine({
                    title: run.title,
                    action: action,
                    duration: seconds,
                    state: state,
                    request: run.tools && run.tools.length === 1 && first ? first.input : undefined
                }));
            }
            (run.tools || []).forEach(function(t, i) {
                var skipped = run.skipped && run.skipped.indexOf(t) !== -1;
                sessionLog.write(skillTitle(t.name), t.input ? t.input.action : null, skipped ? 'skipped' : state);
            });
            run.card = null;
        }

        var body = {message_id: messageId, form_key: formKey, page_context: buildPageContext()};
        if (run && run.selected) {
            body.tool_call_ids = run.selected;
        }

        fetch(config.confirmUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify(body),
            credentials: 'same-origin'
        }).then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('text/event-stream') === -1) {
                return r.json().then(function(d) {
                    releaseInput();
                    finishRun(d.error ? 'failed' : 'done');
                    if (d.error) showError(addMsg('assistant', ''), d.error);
                });
            }
            var reader = r.body.getReader();
            var dec = new TextDecoder();
            var buf = '', evt = '';
            var failed = false;
            var activeStep = null;

            var applyResult = null;
            var applyPending = false;
            var doneReached = false;

            // See send()'s own copy of this same helper: apply() waits on a real signal (its form's
            // provider component, task 009) before writing anything, so its outcome can arrive after
            // the "done" event that already ended the turn.
            function handleApplyOutcome(result) {
                applyPending = false;
                applyResult = result;

                if (doneReached) {
                    reportApplyOutcome(applyResult);
                    applyResult = null;
                }
            }

            function processLine(ln) {
                ln = ln.trim();
                if (ln.indexOf('event: ')===0) { evt=ln.substring(7); }
                else if (ln.indexOf('data: ')===0) {
                    try { var d=JSON.parse(ln.substring(6)); } catch(e){return;}
                    if (evt==='text'&&d.text) {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        full+=d.text; content.innerHTML=renderMd(full); msgs.scrollTop=msgs.scrollHeight;
                    }
                    else if (evt==='tool_call') {
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        addToolTag(msg, d.name);
                    }
                    else if (evt==='tool_status') {
                        // The confirmed write reports into the progress card; anything
                        // after it (follow-up reads) goes under the new answer.
                        if (run && run.card) {
                            if (d.status === 'running') {
                                activeStep = run.card.magoAddStep({label: d.message || d.name, state: 'active'});
                            } else if (d.status === 'failed') {
                                failed = true;
                                failureMessage = d.message || failureMessage;
                                if (activeStep) { activeStep.magoSetState('failed'); activeStep = null; }
                            } else if (d.status === 'done' && activeStep) {
                                activeStep.magoSetState('done');
                                activeStep = null;
                                run.card.magoUpdate({progress: 90});
                            }
                            // The server timed the write itself. Printing its number instead of
                            // the round trip keeps the card the same after a reload.
                            if (d.duration_ms) { run.durationMs = (run.durationMs || 0) + d.duration_ms; }
                        } else {
                            if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                            updateToolStatus(msg, d.name, d.status, d.message);
                        }
                    }
                    else if (evt==='form_apply') { applyPending = true; applyFormDirective(d, handleApplyOutcome); }
                    else if (evt==='done') {
                        if (d.conversation_id) conversationId=d.conversation_id;
                        saveState(); releaseInput();
                        finishRun(failed ? 'failed' : 'done');
                        doneReached = true;
                        if (!applyPending) {
                            reportApplyOutcome(applyResult);
                            applyResult = null;
                        }
                        if (d.pending_confirmation && msg) {
                            showConfirmButtons(msg, {messageId: d.message_id, conversationId: conversationId}, []);
                        }
                        keepNavigateStatusLast();
                    }
                    else if (evt==='error') {
                        failed = true;
                        failureMessage = failureMessage || d.error || '';
                        if (!msg) { loading.style.display='none'; msg=addMsg('assistant',''); content=msg.querySelector('.mago-message-content'); }
                        showError(msg, d.error);
                    }
                }
            }

            function read(res) {
                if (res.done) {
                    buf += dec.decode();
                    if (buf.trim()) {
                        var lines = buf.split('\n');
                        lines.forEach(processLine);
                    }
                    releaseInput();
                    finishRun(failed ? 'failed' : 'done');
                    return;
                }
                buf += dec.decode(res.value, {stream:true});
                var lines = buf.split('\n'); buf = lines.pop();
                lines.forEach(processLine);
                return reader.read().then(read);
            }
            return reader.read().then(read);
        }).catch(function(e) {
            releaseInput();
            finishRun('failed');
            showError(addMsg('assistant', ''), 'Error confirming action: ' + e.message);
        });
    }

    function handleReject(messageId) {
        fetch(config.rejectUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({message_id: messageId, form_key: formKey}),
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(d) {
            addMsg('assistant', renderMd(t('Action rejected. No changes were made.')));
        }).catch(function(e) {
            showError(addMsg('assistant', ''), e.message);
        });
    }

    function offerResume(id) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete(RESUME_PARAM);
            window.history.replaceState(window.history.state, '', url.toString());
        } catch(e) {}

        clearMsgs();
        conversationId = null;
        chat.classList.remove('is-empty');
        setSubtitle('');
        var msgEl = addMsg('assistant', '');
        var card = UI.skillAsk({
            icon: 'arrowRight',
            title: t('Continue conversation?'),
            text: t('You opened this page from a %1 chat. Continue that conversation here, or start a new one.', config.assistantName || 'Mago'),
            allowLabel: t('Continue'),
            laterLabel: t('New chat'),
            onAllow: function() { loadConversation(id); },
            onLater: function() {
                clearMsgs();
                conversationId = null;
                showGreeting();
                saveState();
            }
        });
        msgEl.querySelector('.mago-message-content').appendChild(card);
        openPanel();
    }

    var resumeId = null;
    try {
        resumeId = parseInt(new URLSearchParams(window.location.search).get(RESUME_PARAM), 10) || null;
    } catch(e) {}

    // Restore state from sessionStorage on page load
    if (resumeId) {
        offerResume(resumeId);
    } else try {
        var wasOpen = sessionStorage.getItem(SS_KEY_OPEN) === '1';
        var savedConv = sessionStorage.getItem(SS_KEY_CONV);
        var wasFullsize = sessionStorage.getItem(SS_KEY_FULL) === '1';
        if (wasOpen) {
            if (savedConv) {
                conversationId = parseInt(savedConv, 10) || null;
                if (conversationId) {
                    loadConversation(conversationId);
                }
            }
            if (wasFullsize) {
                chat.classList.add('is-fullsize');
                document.body.classList.add('mago-fullsize');
                var expandBtn = qs('#mago-expand');
                expandBtn.title = 'Side panel';
                expandBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
            }
            openPanel();
        }
    } catch(e) {}
});
