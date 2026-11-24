/* AutoSouq - the "Ask AutoSouq" assistant window.
 *
 * footer.php prints an empty <div id="chat-root"> on every shop page with
 * the endpoint (assistant_api.php) and the CSRF token as data attributes. This
 * script builds a launcher button and a chat panel inside it and posts each
 * question to assistant_api.php as JSON. The server finds the closest cars and
 * FAQ entries and asks the AI to answer from them (RAG); this file only
 * deals with the window.
 *
 * Decisions worth explaining:
 *   - The panel is a non-modal dialog. Visitors keep scrolling the
 *     showroom while it is open, so focus is not trapped; Escape closes it
 *     and focus goes back to the launcher.
 *   - Server text is only ever written with textContent, never innerHTML,
 *     so an answer cannot inject markup. Source links are only made for
 *     the exact pattern product_details.php?id=N, anything else is text.
 *   - Each message has dir="auto", so an Arabic question or answer reads
 *     right to left inside an English page.
 *   - The visible conversation is kept in sessionStorage, so it survives
 *     moving between pages but not closing the browser. The real history
 *     the AI sees stays in the PHP session.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/assistant.js
 * @task    Extra: AI assistant (RAG chat)
 */

(function () {
    'use strict';

    var STORAGE_KEY = 'autosouq_chat';
    var MAX_STORED = 20;
    var MAX_LENGTH = 500;
    // Longer than the server's own worst case (query embedding plus the
    // Claude call), so a slow answer that still arrives is shown instead of
    // an "unavailable" error for a question the server did answer.
    var TIMEOUT_MS = 45000;
    var SOURCE_URL_RE = /^product_details\.php\?id=\d+$/;
    var SVG_NS = 'http://www.w3.org/2000/svg';

    var MSG_UNAVAILABLE = 'The assistant is unavailable right now. Please try again or call the showroom.';
    var MSG_EXPIRED = 'Your session has expired. Please reload the page and try again.';

    var SUGGESTIONS = [
        'Cheapest SUV in stock?',
        'ما هي خيارات التمويل؟',
        'Which cars have the longest warranty?'
    ];

    /* Saved state: { open: bool, messages: [{ role, text, sources, mode }] } */

    function loadState() {
        try {
            var raw = window.sessionStorage.getItem(STORAGE_KEY);
            var data = raw ? JSON.parse(raw) : null;
            if (data && Array.isArray(data.messages)) {
                return {
                    open: data.open === true,
                    messages: data.messages.filter(isValidMessage).slice(-MAX_STORED)
                };
            }
        } catch (error) {
            // Storage blocked or the saved value is broken: start afresh.
        }
        return { open: false, messages: [] };
    }

    function saveState(state) {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                open: state.open,
                messages: state.messages.slice(-MAX_STORED)
            }));
        } catch (error) {
            // Nothing to do; the chat still works for this page.
        }
    }

    function isValidMessage(message) {
        return message
            && (message.role === 'user' || message.role === 'bot')
            && typeof message.text === 'string';
    }

    /* Small DOM helpers */

    function make(tag, className, text) {
        var element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    }

    function chatIcon() {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '22');
        svg.setAttribute('height', '22');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('class', 'chat-launcher-icon');

        var path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', 'M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4h0A2.5 2.5 0 0 1 4 13.5z');
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', 'currentColor');
        path.setAttribute('stroke-width', '1.8');
        path.setAttribute('stroke-linejoin', 'round');
        svg.appendChild(path);

        [9, 12, 15].forEach(function (x) {
            var dot = document.createElementNS(SVG_NS, 'circle');
            dot.setAttribute('cx', String(x));
            dot.setAttribute('cy', '9.5');
            dot.setAttribute('r', '1.1');
            dot.setAttribute('fill', 'currentColor');
            svg.appendChild(dot);
        });
        return svg;
    }

    function init() {
        var root = document.getElementById('chat-root');
        if (!root) {
            return;                        // admin pages have no assistant
        }

        var endpoint = root.getAttribute('data-endpoint') || 'assistant_api.php';
        var csrf = root.getAttribute('data-csrf') || '';
        var state = loadState();
        var busy = false;

        /* Launcher */
        var launcher = make('button', 'chat-launcher');
        launcher.type = 'button';
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-controls', 'chat-panel');
        launcher.appendChild(chatIcon());
        launcher.appendChild(make('span', 'chat-launcher-text', 'Ask AutoSouq'));

        /* Panel */
        var panel = make('section', 'chat-panel');
        panel.id = 'chat-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-labelledby', 'chat-title');
        panel.hidden = true;

        var head = make('div', 'chat-head');
        var title = make('h2', 'chat-title', 'AutoSouq assistant');
        title.id = 'chat-title';

        var newButton = make('button', 'chat-new', 'New chat');
        newButton.type = 'button';

        var closeButton = make('button', 'chat-close');
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', 'Close the assistant');
        closeButton.appendChild(make('span', '', '×')).setAttribute('aria-hidden', 'true');

        head.appendChild(title);
        head.appendChild(newButton);
        head.appendChild(closeButton);

        var intro = make('p', 'chat-intro',
            'Ask about our cars, prices, financing or delivery. Arabic or English.');
        intro.id = 'chat-intro';
        panel.setAttribute('aria-describedby', 'chat-intro');

        var log = make('div', 'chat-log');
        log.setAttribute('role', 'log');
        log.setAttribute('aria-live', 'polite');
        log.setAttribute('aria-label', 'Conversation');
        // Focusable, so a keyboard user can scroll back through long answers.
        log.tabIndex = 0;

        var suggestions = make('div', 'chat-suggestions');
        suggestions.setAttribute('role', 'group');
        suggestions.setAttribute('aria-label', 'Example questions');
        SUGGESTIONS.forEach(function (text) {
            var chip = make('button', 'chat-chip', text);
            chip.type = 'button';
            chip.setAttribute('dir', 'auto');
            chip.addEventListener('click', function () {
                ask(text);
            });
            suggestions.appendChild(chip);
        });

        var form = make('form', 'chat-form');
        form.setAttribute('novalidate', '');

        var label = make('label', 'chat-label', 'Your question');
        label.setAttribute('for', 'chat-input');

        var input = make('textarea', 'chat-input');
        input.id = 'chat-input';
        input.name = 'message';
        input.rows = 2;
        input.maxLength = MAX_LENGTH;
        input.setAttribute('dir', 'auto');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-describedby', 'chat-count chat-keys');

        var formRow = make('div', 'chat-form-row');
        var count = make('span', 'chat-count', '0 / ' + MAX_LENGTH);
        count.id = 'chat-count';
        var keys = make('span', 'sr-only', 'Press Enter to send, Shift and Enter for a new line.');
        keys.id = 'chat-keys';

        var sendButton = make('button', 'btn btn-primary btn-sm chat-send', 'Send');
        sendButton.type = 'submit';

        formRow.appendChild(count);
        formRow.appendChild(keys);
        formRow.appendChild(sendButton);
        form.appendChild(label);
        form.appendChild(input);
        form.appendChild(formRow);

        var note = make('p', 'chat-note',
            'Answers come from our catalogue and may be incomplete. Please confirm with the showroom.');

        // Short announcements that are not part of the conversation.
        var status = make('p', 'sr-only');
        status.setAttribute('role', 'status');

        panel.appendChild(head);
        panel.appendChild(intro);
        panel.appendChild(log);
        panel.appendChild(suggestions);
        panel.appendChild(form);
        panel.appendChild(note);
        panel.appendChild(status);

        /* Rendering */

        function renderMessage(message) {
            var isUser = message.role === 'user';
            var item = make('div', 'chat-msg ' + (isUser ? 'chat-msg-user' : 'chat-msg-bot')
                + (message.error ? ' chat-msg-error' : ''));

            // Spoken prefix kept outside the bubble, so dir="auto" on the
            // bubble is decided by the message itself, not by this label.
            item.appendChild(make('span', 'sr-only', isUser ? 'You said:' : 'Assistant:'));

            var bubble = make('p', 'chat-bubble', message.text);
            bubble.setAttribute('dir', 'auto');
            item.appendChild(bubble);

            if (!isUser && message.mode === 'search') {
                item.appendChild(make('p', 'chat-mode', 'Quick search result (AI unavailable)'));
            }

            var sources = Array.isArray(message.sources) ? message.sources : [];
            if (!isUser && sources.length > 0) {
                var box = make('div', 'chat-sources');
                box.appendChild(make('p', 'chat-sources-title', 'Sources'));
                var list = make('ul', 'chat-sources-list');
                sources.slice(0, 8).forEach(function (source) {
                    if (!source || typeof source.title !== 'string') {
                        return;
                    }
                    var li = make('li');
                    var url = typeof source.url === 'string' ? source.url : '';
                    var node;
                    if (SOURCE_URL_RE.test(url)) {
                        node = make('a', '', source.title);
                        node.href = url;
                    } else {
                        node = make('span', '', source.title);
                    }
                    node.setAttribute('dir', 'auto');
                    li.appendChild(node);
                    list.appendChild(li);
                });
                if (list.firstChild) {
                    box.appendChild(list);
                    item.appendChild(box);
                }
            }

            log.appendChild(item);
            return item;
        }

        function scrollToEnd() {
            log.scrollTop = log.scrollHeight;
        }

        function updateSuggestions() {
            suggestions.hidden = state.messages.length > 0;
        }

        function updateCount() {
            var length = input.value.length;
            count.textContent = length + ' / ' + MAX_LENGTH;
            count.classList.toggle('is-near-limit', length >= MAX_LENGTH - 50);
        }

        function setBusy(value) {
            busy = value;
            sendButton.disabled = value;
            newButton.disabled = value;
            Array.prototype.forEach.call(suggestions.querySelectorAll('button'), function (chip) {
                chip.disabled = value;
            });
        }

        var typing = null;
        function showTyping() {
            typing = make('div', 'chat-msg chat-msg-bot chat-typing');
            typing.setAttribute('role', 'img');
            typing.setAttribute('aria-label', 'Assistant is typing');
            for (var i = 0; i < 3; i++) {
                typing.appendChild(make('span', 'chat-typing-dot')).setAttribute('aria-hidden', 'true');
            }
            log.appendChild(typing);
            scrollToEnd();
        }
        function hideTyping() {
            if (typing && typing.parentNode) {
                typing.parentNode.removeChild(typing);
            }
            typing = null;
        }

        function addMessage(message, keep) {
            renderMessage(message);
            if (keep) {
                state.messages.push(message);
                state.messages = state.messages.slice(-MAX_STORED);
                saveState(state);
            }
            updateSuggestions();
            scrollToEnd();
        }

        function announce(text) {
            status.textContent = '';
            window.setTimeout(function () {
                status.textContent = text;
            }, 50);
        }

        /* Talking to assistant_api.php */

        function post(body) {
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var timer = controller ? window.setTimeout(function () {
                controller.abort();
            }, TIMEOUT_MS) : null;

            return fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(body),
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                return response.json().catch(function () {
                    return null;           // not JSON, e.g. a PHP fatal error page
                }).then(function (data) {
                    return { status: response.status, ok: response.ok, data: data };
                });
            }).finally(function () {
                if (timer) {
                    window.clearTimeout(timer);
                }
            });
        }

        function errorText(result) {
            var serverText = result && result.data && typeof result.data.error === 'string'
                ? result.data.error : '';
            if (result && (result.status === 429 || result.status === 400) && serverText) {
                return serverText;
            }
            if (result && result.status === 403) {
                return MSG_EXPIRED;
            }
            return MSG_UNAVAILABLE;
        }

        function ask(text) {
            var question = String(text || '').trim();
            if (busy) {
                return;
            }
            if (question === '') {
                input.focus();
                return;
            }
            if (question.length > MAX_LENGTH) {
                question = question.slice(0, MAX_LENGTH);
            }

            // The panel is non-modal, so the visitor may go back to the page
            // while waiting. Note where focus is now, and watch for focus or
            // a click landing outside the panel until the answer arrives.
            // ask() is only called from the input, Send or a chip, so focus
            // on <body> here means Safari did not focus the clicked button.
            var active = document.activeElement;
            var focusWasInside = !active || active === document.body || panel.contains(active);
            var leftPanel = false;
            // focusin catches Tab and focused controls. The two down events
            // catch clicks that move no focus: plain page text, or any button
            // in Safari and iOS.
            function onAway(event) {
                if (event.target instanceof Node && !panel.contains(event.target)) {
                    leftPanel = true;
                }
            }
            document.addEventListener('focusin', onAway, true);
            document.addEventListener('pointerdown', onAway, true);
            document.addEventListener('mousedown', onAway, true);

            addMessage({ role: 'user', text: question }, true);
            input.value = '';
            updateCount();
            setBusy(true);
            showTyping();

            post({ message: question }).then(function (result) {
                hideTyping();
                var data = result.data;
                if (!result.ok || !data || data.ok !== true || typeof data.answer !== 'string') {
                    addMessage({ role: 'bot', text: errorText(result), error: true }, false);
                    return;
                }
                addMessage({
                    role: 'bot',
                    text: data.answer,
                    sources: Array.isArray(data.sources) ? data.sources : [],
                    mode: data.mode === 'search' ? 'search' : 'ai'
                }, true);
                if (typeof data.remaining === 'number' && data.remaining <= 3) {
                    announce(data.remaining === 1
                        ? 'You can ask 1 more question this hour.'
                        : 'You can ask ' + data.remaining + ' more questions this hour.');
                }
            }).catch(function () {
                hideTyping();
                addMessage({ role: 'bot', text: MSG_UNAVAILABLE, error: true }, false);
            }).finally(function () {
                setBusy(false);
                document.removeEventListener('focusin', onAway, true);
                document.removeEventListener('pointerdown', onAway, true);
                document.removeEventListener('mousedown', onAway, true);
                // Bring focus back to the box only if the question was sent
                // from the chat and the visitor stayed there. Disabling Send
                // may have dropped focus to <body>, which is why it is needed.
                if (!panel.hidden && focusWasInside && !leftPanel) {
                    input.focus();
                }
            });
        }

        function resetChat() {
            if (busy) {
                return;
            }
            // Clear the window straight away; the server forgets its copy
            // of the history in the background. If that request fails the
            // next answer may still remember the old topic, which is harmless.
            post({ action: 'reset' }).catch(function () {});
            state.messages = [];
            saveState(state);
            while (log.firstChild) {
                log.removeChild(log.firstChild);
            }
            updateSuggestions();
            announce('New chat started.');
            input.focus();
        }

        /* Opening and closing */

        function openPanel(moveFocus) {
            panel.hidden = false;
            launcher.setAttribute('aria-expanded', 'true');
            root.classList.add('is-open');
            state.open = true;
            saveState(state);
            scrollToEnd();
            if (moveFocus) {
                input.focus();
            }
        }

        function closePanel() {
            panel.hidden = true;
            launcher.setAttribute('aria-expanded', 'false');
            root.classList.remove('is-open');
            state.open = false;
            saveState(state);
            launcher.focus();
        }

        launcher.addEventListener('click', function () {
            if (panel.hidden) {
                openPanel(true);
            } else {
                closePanel();
            }
        });
        closeButton.addEventListener('click', closePanel);
        newButton.addEventListener('click', resetChat);

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                event.stopPropagation();
                closePanel();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ask(input.value);
        });

        input.addEventListener('keydown', function (event) {
            // isComposing: Enter also confirms a word in Arabic and other
            // input methods, and must not send a half-typed question.
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                ask(input.value);
            }
        });
        input.addEventListener('input', updateCount);

        /* Restore the earlier conversation before the panel joins the page,
         * so the log does not read every old message out again. */
        state.messages.forEach(renderMessage);
        updateSuggestions();

        root.appendChild(launcher);
        root.appendChild(panel);

        if (state.open) {
            // Reopen after a page change only where the panel is a small
            // floating window, and without stealing focus (the visitor may be
            // using the skip link). On a phone, or a short screen such as
            // 200% zoom, it fills the screen and would cover the new page
            // while focus stays behind it, so leave it closed; the
            // conversation is still there behind the launcher.
            // Same breakpoints as section 22 of style.css.
            var wide = !window.matchMedia
                || window.matchMedia('(min-width: 481px) and (min-height: 521px)').matches;
            if (wide) {
                openPanel(false);
            } else {
                state.open = false;
                saveState(state);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
