/* AutoSouq - pop-up help window on the product details page.
 *
 * Task 14: the help window opened from the button beside the quantity box.
 *
 * It uses the native <dialog> element with showModal() rather than
 * window.open(), because browsers block real pop-up windows by default and
 * a pop-up built from a floating <div> leaves keyboard focus behind it on
 * the page. <dialog> gives a modal layer, Escape to close, focus kept
 * inside it and the rest of the page hidden from screen readers, which
 * also covers Task 15.
 *
 * Browsers without <dialog> support fall back to a real new window.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/help.js
 * @task    Task 14, Task 15
 */

(function () {
    'use strict';

    function init() {
        var dialog = document.getElementById('help-dialog');
        var openButton = document.getElementById('help-button');
        var closeButton = document.getElementById('help-close');

        if (!dialog || !openButton) {
            return;                        // not the product details page
        }

        // Remember who opened the dialog so focus can go back there.
        var lastFocused = null;

        var supportsDialog = typeof dialog.showModal === 'function';

        function open() {
            lastFocused = document.activeElement;

            if (supportsDialog) {
                dialog.showModal();
                if (closeButton) {
                    closeButton.focus();
                }
                return;
            }

            openInSeparateWindow();
        }

        function close() {
            if (supportsDialog && dialog.open) {
                dialog.close();
            }
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        /** Fallback for browsers with no <dialog>: copy the help markup
         *  into a real child window. */
        function openInSeparateWindow() {
            var inner = dialog.querySelector('.help-dialog-inner');
            var popup = window.open('', 'autosouq-help',
                'width=520,height=640,scrollbars=yes,resizable=yes');

            if (!popup) {
                // Pop-ups blocked, so show the dialog inline instead.
                dialog.setAttribute('open', 'open');
                dialog.classList.add('is-inline-fallback');
                return;
            }

            popup.document.write(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                + '<title>How buying a car here works</title>'
                + '<link rel="stylesheet" href="css/style.css">'
                + '</head><body class="help-window">'
                + (inner ? inner.innerHTML : '')
                + '</body></html>'
            );
            popup.document.close();
            popup.focus();
        }

        openButton.addEventListener('click', open);

        if (closeButton) {
            closeButton.addEventListener('click', close);
        }

        // Clicking the dark area outside the panel closes the dialog.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                close();
            }
        });

        // Escape fires the native cancel event; put focus back afterwards.
        dialog.addEventListener('cancel', function () {
            window.setTimeout(function () {
                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }
            }, 0);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
