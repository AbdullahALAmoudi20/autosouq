/* AutoSouq - monthly payment estimate on the product details page.
 *
 * Recalculates the four figures under "Estimate your monthly payment" as
 * the visitor changes the down payment, the term or the profit rate.
 *
 * Car finance in Saudi Arabia is mostly Murabaha: the bank buys the car
 * and sells it on with a fixed profit, so the profit is a flat rate on the
 * amount financed for every year of the term, not interest on a falling
 * balance. That makes the sum simple:
 *
 *   financed = price - down payment
 *   profit   = financed x rate x years
 *   monthly  = (financed + profit) / months
 *
 * product_details.php prints the same sum with the default inputs, so the
 * page still shows an example when JavaScript is off.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/finance.js
 * @task    Extra: finance calculator
 */

(function () {
    'use strict';

    var DOWN_MIN = 10;
    var DOWN_MAX = 50;
    var DOWN_DEFAULT = 20;
    var RATE_MIN = 0;
    var RATE_MAX = 15;
    var RATE_DEFAULT = 4.5;
    var TERMS = [12, 24, 36, 48, 60];
    var TERM_DEFAULT = 60;

    /** "93,360 SAR", the same format PHP prints. */
    function sar(amount) {
        return Math.round(amount).toLocaleString('en-US') + ' SAR';
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    /** Parse a number box. Returns NaN for an empty or non-numeric value.
     *  A comma decimal ("5,5") counts as 5.5. The rate box is type="text"
     *  so the browser passes the comma through; Number() alone would also
     *  take "0x10" or "1e1", so the shape is checked first. */
    function readNumber(input) {
        var raw = input.value.trim().replace(',', '.');
        if (!/^-?(\d+(\.\d*)?|\.\d+)$/.test(raw)) {
            return NaN;
        }
        return Number(raw);
    }

    function init() {
        var section = document.getElementById('finance');
        if (!section) {
            return;                        // not the product details page
        }

        var price = parseFloat(section.getAttribute('data-price') || '');
        var form = document.getElementById('finance-form');
        var down = document.getElementById('fin-down');
        var downRange = document.getElementById('fin-down-range');
        var term = document.getElementById('fin-term');
        var rate = document.getElementById('fin-rate');

        if (!form || !down || !downRange || !term || !rate || !(price > 0)) {
            return;                        // keep the PHP example as it is
        }

        var out = {
            monthly: document.getElementById('fin-monthly'),
            financed: document.getElementById('fin-financed'),
            profit: document.getElementById('fin-profit'),
            total: document.getElementById('fin-total'),
            downAmount: document.getElementById('fin-down-amount'),
            basis: document.getElementById('fin-basis')
        };
        var downError = document.getElementById('fin-down-error');
        var rateError = document.getElementById('fin-rate-error');

        function setText(element, text) {
            // Only touch the element when the text really changes, so the
            // live regions are not re-announced with the same figure.
            if (element && element.textContent !== text) {
                element.textContent = text;
            }
        }

        function showError(input, errorBox, message) {
            if (message) {
                input.setAttribute('aria-invalid', 'true');
                input.classList.add('is-invalid');
            } else {
                input.removeAttribute('aria-invalid');
                input.classList.remove('is-invalid');
            }
            setText(errorBox, message);
        }

        var MSG_DOWN_EMPTY = 'Enter a down payment between 10% and 50%.';
        var MSG_DOWN_RANGE = 'The down payment must be between 10% and 50%.';
        var MSG_RATE_EMPTY = 'Enter a profit rate between 0% and 15%.';
        var MSG_RATE_RANGE = 'The profit rate must be between 0% and 15%.';

        /** The one-off note tidy() left under each box ("... We used 15%."),
         *  with the value it was written for. It stays while the box still
         *  holds that value, so a later render (Enter, the term select) does
         *  not wipe it; once the visitor changes the box it is dropped. */
        var notes = { down: null, rate: null };

        /** Update the message under one box.
         *  - report and a real problem: show it as an error (aria-invalid).
         *  - still typing and the box is empty or not a number: leave the
         *    message alone, the problem it describes is still there.
         *  - otherwise: show the note from tidy() if it still applies, or
         *    clear the message. */
        function updateMessage(key, input, errorBox, message, invalid, report) {
            var note = notes[key];
            if (note && input.value !== note.value) {
                notes[key] = note = null;
            }
            if (report && message) {
                showError(input, errorBox, message);
            } else if (!report && invalid) {
                return;
            } else if (note) {
                showError(input, errorBox, '');    // a note, not an error
                setText(errorBox, note.text);
            } else {
                showError(input, errorBox, '');
            }
        }

        /** Read the inputs. Out-of-range values are clamped for the sum;
         *  empty or non-numeric ones return null so nothing odd is shown.
         *  report is false while the visitor is still typing: no new message
         *  is shown, because "3" on the way to "35", or an empty box before
         *  retyping, is not a mistake yet. Leaving the box (tidy) or Enter
         *  reports. */
        function readInputs(report) {
            var downPct = readNumber(down);
            var ratePct = readNumber(rate);
            var months = parseInt(term.value, 10);
            var downMessage = '';
            var rateMessage = '';
            var downBad = isNaN(downPct);
            var rateBad = isNaN(ratePct);

            if (downBad) {
                downMessage = MSG_DOWN_EMPTY;
            } else {
                if (downPct < DOWN_MIN || downPct > DOWN_MAX) {
                    downMessage = MSG_DOWN_RANGE + ' We used ' + clamp(downPct, DOWN_MIN, DOWN_MAX) + '%.';
                }
                downPct = clamp(downPct, DOWN_MIN, DOWN_MAX);
            }

            if (rateBad) {
                rateMessage = MSG_RATE_EMPTY;
            } else {
                if (ratePct < RATE_MIN || ratePct > RATE_MAX) {
                    rateMessage = MSG_RATE_RANGE + ' We used ' + clamp(ratePct, RATE_MIN, RATE_MAX) + '%.';
                }
                ratePct = clamp(ratePct, RATE_MIN, RATE_MAX);
            }

            updateMessage('down', down, downError, downMessage, downBad, report);
            updateMessage('rate', rate, rateError, rateMessage, rateBad, report);

            // The select can only hold our values, but check anyway in case
            // the markup is edited in the browser.
            if (TERMS.indexOf(months) === -1) {
                months = TERM_DEFAULT;
            }

            if (downBad || rateBad) {
                return null;
            }
            return { downPct: downPct, ratePct: ratePct, months: months };
        }

        function render(report) {
            var input = readInputs(report);

            if (input === null) {
                // Leave a plain dash rather than "NaN SAR" while the box is
                // empty, and do not leave the old "Based on ..." line behind.
                setText(out.monthly, '-');
                setText(out.financed, '-');
                setText(out.profit, '-');
                setText(out.total, '-');
                setText(out.downAmount, '-');
                setText(out.basis, 'Enter a down payment and a profit rate to see an estimate.');
                return;
            }

            var downAmount = price * input.downPct / 100;
            var financed = price - downAmount;
            var profit = financed * (input.ratePct / 100) * (input.months / 12);
            var monthly = (financed + profit) / input.months;

            setText(out.monthly, sar(monthly));
            setText(out.financed, sar(financed));
            setText(out.profit, sar(profit));
            setText(out.total, sar(price + profit));
            setText(out.downAmount, sar(downAmount));
            setText(out.basis, 'Based on ' + input.downPct + '% down over ' + input.months
                + ' months at ' + input.ratePct + '% a year, for a price of ' + sar(price) + '.');
        }

        // Dragging the slider fires many input events. A short delay means
        // the figures, and so the screen reader, update once it settles.
        // Typing never reports errors (render(false)); leaving the box does.
        var timer = null;
        function renderSoon() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                render(false);
            }, 150);
        }

        /** On leaving a box, write the clamped value back into it and say
         *  what we did. The box now holds a valid value, so the message is
         *  a note (no aria-invalid). It is kept in notes[] so the render
         *  that Enter triggers straight after this does not clear it. */
        function tidy(key, input, min, max, fallback, emptyMessage, rangeMessage) {
            var raw = readNumber(input);
            var value = isNaN(raw) ? fallback : clamp(raw, min, max);
            var note = '';
            if (isNaN(raw)) {
                note = emptyMessage + ' We used ' + value + '%.';
            } else if (raw !== value) {
                note = rangeMessage + ' We used ' + value + '%.';
            }
            window.clearTimeout(timer);    // a pending render(false) is now stale
            input.value = String(value);
            if (input === down) {
                downRange.value = String(value);
            }
            notes[key] = note ? { value: input.value, text: note } : null;
            render(true);
        }

        downRange.addEventListener('input', function () {
            down.value = downRange.value;
            renderSoon();
        });

        down.addEventListener('input', function () {
            var value = readNumber(down);
            if (!isNaN(value)) {
                downRange.value = String(clamp(value, DOWN_MIN, DOWN_MAX));
            }
            renderSoon();
        });
        down.addEventListener('change', function () {
            tidy('down', down, DOWN_MIN, DOWN_MAX, DOWN_DEFAULT, MSG_DOWN_EMPTY, MSG_DOWN_RANGE);
        });

        rate.addEventListener('input', renderSoon);
        rate.addEventListener('change', function () {
            tidy('rate', rate, RATE_MIN, RATE_MAX, RATE_DEFAULT, MSG_RATE_EMPTY, MSG_RATE_RANGE);
        });

        term.addEventListener('change', function () {
            render(true);
        });

        // Enter in a box would submit the form and reload the page.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(timer);
            render(true);
        });

        render(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
