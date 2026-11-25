/* Site-wide behaviours, kept out of validation.js:
 *   1. mobile navigation toggle
 *   2. confirmation before a destructive action (remove, empty, delete)
 *   3. auto-dismissing success and info flash messages
 *
 * All of it is progressive enhancement. Without JavaScript the navigation
 * is still visible, the forms still submit (PHP checks the CSRF token
 * either way) and flash messages stay until the next page load.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/js/site.js
 * @task    Task 2, Task 15, Task 16
 */

(function () {
    'use strict';

    /* 1. Mobile navigation */
    function initNavigation() {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.getElementById('primary-nav');

        if (!toggle || !nav) {
            return;
        }

        toggle.addEventListener('click', function () {
            var isOpen = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.querySelector('.sr-only').textContent =
                isOpen ? 'Hide navigation menu' : 'Show navigation menu';
        });

        // Escape closes the menu and returns focus to the button.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    }

    /* 2. Confirm destructive actions. Any form with data-confirm="..." asks
     * before submitting: remove a car from the cart, empty the cart, delete
     * a vehicle from the catalogue. */
    function initConfirmations() {
        var forms = document.querySelectorAll('form[data-confirm]');

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                var question = form.getAttribute('data-confirm');
                if (!window.confirm(question)) {
                    event.preventDefault();
                }
            });
        });
    }

    /* 3. Fade out success and info messages. Errors are never auto-dismissed,
     * so the customer can read what went wrong. */
    function initFlashDismiss() {
        var flashes = document.querySelectorAll('.flash-success, .flash-info');

        Array.prototype.forEach.call(flashes, function (flash) {
            window.setTimeout(function () {
                flash.classList.add('is-fading');
                window.setTimeout(function () {
                    if (flash.parentNode) {
                        flash.parentNode.removeChild(flash);
                    }
                }, 400);
            }, 6000);
        });
    }

    function initPageJumps() {
        let pending = null;
        let highlighted = null;
        let arrivalTimer;
        let clearTimer;

        function highlightAfterScroll() {
            if (!pending) return;
            clearTimeout(arrivalTimer);
            arrivalTimer = setTimeout(function () {
                highlighted = pending;
                pending = null;
                highlighted.classList.add('jump-highlight');
                clearTimer = setTimeout(function () {
                    highlighted.classList.remove('jump-highlight');
                    highlighted = null;
                }, 1600);
            }, 150);
        }

        function markDestination(target) {
            clearTimeout(arrivalTimer);
            clearTimeout(clearTimer);
            if (highlighted) highlighted.classList.remove('jump-highlight');
            highlighted = null;
            pending = null;
            if (!target || target.matches('dialog')) return;
            if (target.matches('main')) target = target.querySelector('h1') || target;
            if (target.matches('h2')) target = target.closest('section') || target;
            pending = target;
            highlightAfterScroll();
        }

        function followHash(hash) {
            let id;
            try { id = decodeURIComponent(hash.slice(1)); }
            catch { return; }
            markDestination(document.getElementById(id));
        }

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');
            if (!link || !link.hash || link.target || link.hasAttribute('download') ||
                event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (link.origin === location.origin && link.pathname === location.pathname && link.search === location.search) {
                followHash(link.hash);
            }
        });
        document.addEventListener('autosouq:field-jump', function (event) {
            markDestination(event.target);
        });
        window.addEventListener('scroll', highlightAfterScroll, { passive: true });
        window.addEventListener('hashchange', () => followHash(location.hash));
        if (location.hash) followHash(location.hash);
    }

    function init() {
        initNavigation();
        initConfirmations();
        initFlashDismiss();
        initPageJumps();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
