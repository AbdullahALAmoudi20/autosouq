/* AutoSouq - "Compare" buttons and the compare tray.
 *
 * Every car card (includes/car_card.php) and the car page carry a hidden
 * <button class="compare-toggle">. This script shows them, remembers the
 * picked cars in localStorage and draws a small tray in the bottom-left
 * corner (the chat button has the bottom-right) with a link to
 * compare.php?ids=1,2,3.
 *
 * At most three cars can be picked, the same limit compare.php applies.
 * If the browser blocks storage the list is kept in memory, so the
 * buttons still work on the current page.
 *
 * On compare.php itself there is no tray. Instead the saved list is
 * replaced by the cars in the table, so removing a car there (a normal
 * link) also clears its button everywhere else.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/compare.js
 * @task    Extra: car comparison
 */

(function () {
    'use strict';

    var STORAGE_KEY = 'autosouq_compare';
    var MAX_CARS = 3;
    var LIMIT_MESSAGE = 'You can compare up to 3 cars. Remove one first.';
    var MIN_MESSAGE = 'Pick at least 2 cars to compare.';

    var memoryList = [];                   // used when storage is blocked

    /* Storage */

    /** Keep only well-formed entries, without duplicates, at most three. */
    function clean(list) {
        var result = [];
        var seen = {};
        if (!Array.isArray(list)) {
            return result;
        }
        list.forEach(function (item) {
            if (!item || result.length >= MAX_CARS) {
                return;
            }
            var id = parseInt(item.id, 10);
            var name = typeof item.name === 'string' ? item.name.slice(0, 120) : '';
            if (id > 0 && !seen[id]) {
                seen[id] = true;
                result.push({ id: id, name: name || ('Car ' + id) });
            }
        });
        return result;
    }

    function load() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            return raw ? clean(JSON.parse(raw)) : [];
        } catch (error) {
            return clean(memoryList);
        }
    }

    function save(list) {
        memoryList = clean(list);
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(memoryList));
        } catch (error) {
            // Private mode or storage switched off: memoryList still works.
        }
    }

    function indexOfCar(list, id) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) {
                return i;
            }
        }
        return -1;
    }

    /* Toggle buttons */

    function allToggles() {
        return Array.prototype.slice.call(document.querySelectorAll('.compare-toggle[data-compare-id]'));
    }

    function updateToggles(list) {
        allToggles().forEach(function (button) {
            var id = parseInt(button.getAttribute('data-compare-id'), 10);
            var picked = indexOfCar(list, id) !== -1;

            // The label stays "Compare": a toggle button's name must not
            // change with its state. aria-pressed tells screen readers, and
            // the CSS tick on .is-picked shows it on screen.
            button.hidden = false;
            button.setAttribute('aria-pressed', picked ? 'true' : 'false');
            button.classList.toggle('is-picked', picked);
        });
    }

    /* Tray */

    var tray = null;
    var trayTitle = null;
    var trayList = null;
    var trayLink = null;
    var trayMessage = null;

    function buildTray() {
        tray = document.createElement('aside');
        tray.className = 'compare-tray';
        tray.setAttribute('aria-labelledby', 'compare-tray-title');
        tray.hidden = true;

        trayTitle = document.createElement('h2');
        trayTitle.className = 'compare-tray-title';
        trayTitle.id = 'compare-tray-title';

        trayList = document.createElement('ul');
        trayList.className = 'compare-tray-list';

        var actions = document.createElement('div');
        actions.className = 'compare-tray-actions';

        trayLink = document.createElement('a');
        trayLink.className = 'btn btn-primary btn-sm compare-tray-go';
        trayLink.textContent = 'Compare now';
        trayLink.href = 'compare.php';
        trayLink.addEventListener('click', function (event) {
            // The link stays focusable while disabled, so explain instead
            // of silently doing nothing.
            if (trayLink.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                say(MIN_MESSAGE);
            }
        });

        var clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-quiet btn-sm compare-tray-clear';
        clearButton.textContent = 'Clear';
        clearButton.addEventListener('click', function () {
            save([]);
            refresh();
            focusMain();                   // the tray is hidden now
        });

        actions.appendChild(trayLink);
        actions.appendChild(clearButton);

        trayMessage = document.createElement('p');
        trayMessage.className = 'compare-tray-msg';
        trayMessage.setAttribute('aria-live', 'polite');

        tray.appendChild(trayTitle);
        tray.appendChild(trayList);
        tray.appendChild(actions);
        tray.appendChild(trayMessage);
        document.body.appendChild(tray);
    }

    var messageTimer = null;
    function say(text) {
        if (!trayMessage) {
            return;
        }
        // Clear first so the same message is announced again on a repeat.
        trayMessage.textContent = '';
        window.clearTimeout(messageTimer);
        window.setTimeout(function () {
            trayMessage.textContent = text;
        }, 50);
        messageTimer = window.setTimeout(function () {
            trayMessage.textContent = '';
        }, 6000);
    }

    function renderTray(list) {
        if (!tray) {
            return;
        }

        trayTitle.textContent = 'Compare (' + list.length + '/' + MAX_CARS + ')';

        while (trayList.firstChild) {
            trayList.removeChild(trayList.firstChild);
        }
        list.forEach(function (car) {
            var item = document.createElement('li');

            var name = document.createElement('span');
            name.className = 'compare-tray-name';
            name.setAttribute('dir', 'auto');
            name.textContent = car.name;

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'compare-tray-remove';
            remove.setAttribute('aria-label', 'Remove ' + car.name + ' from comparison');
            remove.setAttribute('data-remove-id', String(car.id));
            remove.textContent = '×';

            item.appendChild(name);
            item.appendChild(remove);
            trayList.appendChild(item);
        });

        if (list.length >= 2) {
            trayLink.href = 'compare.php?ids=' + list.map(function (car) {
                return car.id;
            }).join(',');
            trayLink.removeAttribute('aria-disabled');
            trayLink.classList.remove('is-disabled');
        } else {
            trayLink.href = 'compare.php';
            trayLink.setAttribute('aria-disabled', 'true');
            trayLink.classList.add('is-disabled');
        }

        tray.hidden = list.length === 0;
        document.body.classList.toggle('has-compare-tray', list.length > 0);
        reserveTraySpace();
    }

    /** Leave room under the page for the tray, using its real height. With
     *  three cars the pills wrap and the tray is taller than any fixed CSS
     *  value, so the footer would end up underneath it. scroll-padding also
     *  keeps a focused element from scrolling in behind the tray. */
    function reserveTraySpace() {
        var html = document.documentElement;
        if (!tray || tray.hidden) {
            document.body.style.paddingBottom = '';
            html.style.scrollPaddingBottom = '';
            return;
        }
        // From the tray's top edge to the bottom of the window, plus a gap.
        var space = Math.ceil(window.innerHeight - tray.getBoundingClientRect().top) + 16;
        document.body.style.paddingBottom = space + 'px';
        html.style.scrollPaddingBottom = space + 'px';
    }

    function refresh() {
        var list = load();
        updateToggles(list);
        renderTray(list);
    }

    /* Actions */

    function toggleCar(button) {
        var id = parseInt(button.getAttribute('data-compare-id'), 10);
        var name = button.getAttribute('data-compare-name') || '';
        var list = load();
        var index = indexOfCar(list, id);

        if (!(id > 0)) {
            return;
        }
        if (index !== -1) {
            list.splice(index, 1);
        } else if (list.length >= MAX_CARS) {
            say(LIMIT_MESSAGE);
            return;
        } else {
            list.push({ id: id, name: name });
        }
        save(list);
        refresh();
    }

    /** Focus the start of the page content, used when the tray closes. */
    function focusMain() {
        var main = document.getElementById('main-content');
        if (main) {
            main.setAttribute('tabindex', '-1');
            main.focus();
        }
    }

    function removeCar(id) {
        var list = load();
        var index = indexOfCar(list, id);
        if (index === -1) {
            return;
        }
        list.splice(index, 1);
        save(list);
        refresh();

        // The clicked button is gone, so put focus somewhere useful: the
        // next remove button, or the car's own toggle once the tray closes.
        if (tray && !tray.hidden) {
            (trayList.querySelector('.compare-tray-remove') || trayLink).focus();
            return;
        }
        var toggle = document.querySelector('.compare-toggle[data-compare-id="' + id + '"]');
        if (toggle) {
            toggle.focus();
        } else {
            focusMain();
        }
    }

    /** compare.php: the page is the truth, so copy it into storage. It
     *  prints the marker whenever the URL named the cars, also for 0 or 1
     *  car left (an empty value means Clear, so an empty list). */
    function syncFromComparePage() {
        var wrapper = document.querySelector('[data-compare-ids]');
        if (!wrapper) {
            return;                        // bare compare.php (nav link): leave the list alone
        }
        var list = [];
        (wrapper.getAttribute('data-compare-ids') || '').split(',').forEach(function (part) {
            var id = parseInt(part, 10);
            var header = wrapper.querySelector('[data-car-id="' + id + '"]');
            if (id > 0) {
                list.push({ id: id, name: header ? header.getAttribute('data-car-name') || '' : '' });
            }
        });
        save(list);
    }

    function init() {
        var onComparePage = document.body.classList.contains('page-compare');

        if (onComparePage) {
            syncFromComparePage();
        } else {
            buildTray();
            // The tray's height changes when the window is resized (the
            // pills rewrap) or a web font loads, so measure it again.
            if (typeof ResizeObserver === 'function') {
                new ResizeObserver(reserveTraySpace).observe(tray);
            }
            window.addEventListener('resize', reserveTraySpace);
        }

        // One listener for every button, including ones added later.
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var toggle = target.closest('.compare-toggle[data-compare-id]');
            if (toggle) {
                event.preventDefault();
                toggleCar(toggle);
                return;
            }
            var remove = target.closest('.compare-tray-remove');
            if (remove) {
                removeCar(parseInt(remove.getAttribute('data-remove-id'), 10));
            }
        });

        // Keep two open tabs in step with each other.
        window.addEventListener('storage', function (event) {
            if (event.key === STORAGE_KEY || event.key === null) {
                refresh();
            }
        });

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
