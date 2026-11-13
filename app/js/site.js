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

    function init() {
        initNavigation();
        initConfirmations();
        initFlashDismiss();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
