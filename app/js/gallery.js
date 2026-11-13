/* AutoSouq - photo gallery and full-screen viewer on the product page.
 *
 * The thumbnails under the main photo switch the photo in place, and the
 * main photo opens a full-screen viewer with the whole, uncropped image.
 * The viewer is a native <dialog> opened with showModal(), the same
 * approach as the help window in help.js: Escape closes it, focus stays
 * inside it, and focus goes back to where it was afterwards. The arrow
 * keys and a finger swipe move between photos.
 *
 * Without JavaScript (or without <dialog> support) every photo is a plain
 * link to the image file, so nothing is lost.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/gallery.js
 * @task    Task 4, Task 15
 */

(function () {
    'use strict';

    function init() {
        var main = document.getElementById('detail-main');
        var mainImg = document.getElementById('detail-main-img');
        var viewer = document.getElementById('photo-viewer');

        if (!main || !mainImg || !viewer || typeof viewer.showModal !== 'function') {
            return;                        // keep the plain links
        }

        var photos = [];
        try {
            photos = JSON.parse(viewer.getAttribute('data-photos') || '[]');
        } catch (error) {
            photos = [];
        }
        if (photos.length === 0) {
            return;
        }

        var viewerImg = document.getElementById('photo-viewer-img');
        var counter = document.getElementById('photo-viewer-count');
        var prevButton = document.getElementById('photo-viewer-prev');
        var nextButton = document.getElementById('photo-viewer-next');
        var closeButton = document.getElementById('photo-viewer-close');
        var credit = document.getElementById('photo-credit');
        var viewerCredit = document.getElementById('photo-viewer-credit');
        var thumbs = Array.prototype.slice.call(document.querySelectorAll('.detail-thumb'));

        var current = 0;
        var lastFocused = null;

        /* Show photo number "index" in the main frame on the page. */
        function select(index) {
            var thumb = thumbs[index];
            if (!thumb) {
                return;
            }
            current = index;

            mainImg.src = thumb.getAttribute('href');
            mainImg.alt = thumb.getAttribute('data-photo-alt') || '';
            mainImg.setAttribute('style', thumb.getAttribute('data-photo-style') || '');

            main.setAttribute('href', thumb.getAttribute('href'));
            main.setAttribute('data-photo-index', String(index));
            main.setAttribute('aria-label', 'Enlarge photo ' + (index + 1) + ' of ' + photos.length);

            thumbs.forEach(function (other, i) {
                other.classList.toggle('is-active', i === index);
                if (i === index) {
                    other.setAttribute('aria-current', 'true');
                } else {
                    other.removeAttribute('aria-current');
                }
            });

            renderCredit(credit, photos[index], true);
        }

        /* Fill a credit line for one photo: "Photo: author, licence, via
         * Wikimedia Commons". Built with text nodes, never innerHTML, and
         * the link is only kept when it points to an https address. */
        function renderCredit(target, photo, withSuffix) {
            if (!target) {
                return;
            }
            target.textContent = '';
            var info = photo && photo.credit;
            if (!info || !info.author) {
                target.hidden = true;
                return;
            }
            target.appendChild(document.createTextNode('Photo: ' + info.author + ', '));
            var licence = document.createElement(/^https:\/\//.test(info.source || '') ? 'a' : 'span');
            licence.textContent = info.license || 'licence';
            if (licence.tagName === 'A') {
                licence.href = info.source;
                licence.target = '_blank';
                licence.rel = 'noopener noreferrer';
            }
            target.appendChild(licence);
            if (withSuffix) {
                target.appendChild(document.createTextNode(', via Wikimedia Commons'));
            }
            target.hidden = false;
        }

        /* Show photo number "index" inside the viewer, wrapping around. */
        function show(index) {
            current = (index + photos.length) % photos.length;
            viewerImg.src = photos[current].src;
            viewerImg.alt = photos[current].alt || '';
            counter.textContent = (current + 1) + ' / ' + photos.length;
            renderCredit(viewerCredit, photos[current], false);
        }

        function open(index) {
            lastFocused = document.activeElement;
            show(index);
            viewer.showModal();
            document.body.classList.add('has-viewer');
            closeButton.focus();
        }

        viewer.addEventListener('close', function () {
            document.body.classList.remove('has-viewer');
            // Leave the page showing the photo the visitor stopped on.
            if (thumbs.length > 0) {
                select(current);
            }
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        });

        main.addEventListener('click', function (event) {
            event.preventDefault();
            open(parseInt(main.getAttribute('data-photo-index'), 10) || 0);
        });

        thumbs.forEach(function (thumb, index) {
            thumb.addEventListener('click', function (event) {
                event.preventDefault();
                select(index);
            });
        });

        if (prevButton) {
            prevButton.addEventListener('click', function () { show(current - 1); });
        }
        if (nextButton) {
            nextButton.addEventListener('click', function () { show(current + 1); });
        }
        closeButton.addEventListener('click', function () { viewer.close(); });

        // A click on the dark area around the photo closes the viewer.
        viewer.addEventListener('click', function (event) {
            var target = event.target;
            if (target === viewer || target.classList.contains('photo-viewer-inner')
                || target.classList.contains('photo-viewer-figure')) {
                viewer.close();
            }
        });

        viewer.addEventListener('keydown', function (event) {
            if (photos.length < 2) {
                return;
            }
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                show(current - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                show(current + 1);
            }
        });

        // Swipe left or right on a phone. Only a one-finger gesture counts,
        // so pinching to zoom into a photo never jumps to another one.
        var startX = null;
        var multiTouch = false;
        viewer.addEventListener('touchstart', function (event) {
            if (event.touches.length > 1) {
                multiTouch = true;
                startX = null;
                return;
            }
            if (!multiTouch) {
                startX = event.touches[0].clientX;
            }
        }, { passive: true });
        viewer.addEventListener('touchend', function (event) {
            if (event.touches.length > 0) {
                return;                    // other fingers are still down
            }
            var zoomed = window.visualViewport && window.visualViewport.scale > 1;
            if (!multiTouch && !zoomed && startX !== null && photos.length > 1) {
                var distance = event.changedTouches[0].clientX - startX;
                if (Math.abs(distance) > 40) {
                    show(current + (distance < 0 ? 1 : -1));
                }
            }
            startX = null;
            multiTouch = false;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
