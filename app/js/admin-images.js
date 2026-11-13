/* AutoSouq - live photo preview on the manager's add / edit form.
 *
 * The preview frame has the same shape as a showroom card, so the manager
 * sees exactly how the picture will be cropped before saving. Moving the
 * fit, position or zoom controls restyles the preview straight away;
 * choosing a new file, or a gallery photo as the new main picture, swaps
 * the preview image. New gallery files are listed with a small thumbnail.
 *
 * Files are read with FileReader as data: URLs because the
 * Content-Security-Policy allows images from 'self' and data: only.
 * Without JavaScript the controls still post normal form values.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/js/admin-images.js
 * @task    Task 9, Task 10
 */

(function () {
    'use strict';

    var IMAGE_TYPES = /^image\/(jpeg|png|webp)$/;

    function readAsDataUrl(file, done) {
        if (!file || !IMAGE_TYPES.test(file.type)) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function () { done(reader.result); };
        reader.readAsDataURL(file);
    }

    function init() {
        var img = document.getElementById('frame-preview-img');
        var form = img ? img.closest('form') : null;
        if (!img || !form) {
            return;                        // not the add / edit form
        }

        var fit = form.elements.image_fit;
        var posX = form.elements.image_pos_x;
        var posY = form.elements.image_pos_y;
        var zoom = form.elements.image_zoom;
        var picture = form.elements.picture;
        var gallery = document.getElementById('gallery');
        var newList = document.getElementById('gallery-new-list');
        var reset = document.getElementById('frame-reset');
        var originalSrc = img.getAttribute('src');

        function setOutput(id, text) {
            var output = document.getElementById(id + '-out');
            if (output) {
                output.textContent = text;
            }
        }

        /* Copy the control values onto the preview image. */
        function apply() {
            var x = parseInt(posX.value, 10);
            var y = parseInt(posY.value, 10);
            var z = parseInt(zoom.value, 10) / 100;

            img.style.objectFit = fit.value === 'contain' ? 'contain' : 'cover';
            img.style.objectPosition = x + '% ' + y + '%';
            img.style.transformOrigin = x + '% ' + y + '%';
            img.style.setProperty('--zoom', z.toFixed(2));

            setOutput('image_pos_x', x + '%');
            setOutput('image_pos_y', y + '%');
            setOutput('image_zoom', zoom.value + '%');
        }

        [fit, posX, posY, zoom].forEach(function (control) {
            control.addEventListener('input', apply);
            control.addEventListener('change', apply);
        });

        if (reset) {
            reset.hidden = false;
            reset.addEventListener('click', function () {
                fit.value = 'cover';
                posX.value = 50;
                posY.value = 50;
                zoom.value = 100;
                apply();
            });
        }

        /* The image that will be the main picture after saving. */
        function chosenMainSrc() {
            var radio = form.querySelector('input[name="main_image"]:checked');
            return radio && radio.getAttribute('data-src') ? radio.getAttribute('data-src') : originalSrc;
        }

        function newMainChosen() {
            return picture && picture.files && picture.files.length > 0;
        }

        if (picture) {
            picture.addEventListener('change', function () {
                if (newMainChosen()) {
                    readAsDataUrl(picture.files[0], function (url) { img.src = url; });
                } else {
                    img.src = chosenMainSrc();
                }
            });
        }

        // "Make main" on a gallery photo previews that photo, and clears its
        // Remove box so the two choices cannot clash.
        Array.prototype.forEach.call(form.querySelectorAll('input[name="main_image"]'), function (radio) {
            radio.addEventListener('change', function () {
                var removeBox = document.getElementById('remove-' + radio.value);
                if (removeBox) {
                    removeBox.checked = false;
                    removeBox.closest('li').classList.remove('is-removing');
                }
                if (!newMainChosen()) {
                    img.src = chosenMainSrc();
                }
            });
        });

        // Removing the photo picked as main puts the main choice back.
        Array.prototype.forEach.call(form.querySelectorAll('input[name="remove_images[]"]'), function (box) {
            box.addEventListener('change', function () {
                var radio = document.getElementById('main-' + box.value);
                if (box.checked && radio && radio.checked) {
                    var keep = document.getElementById('main-0');
                    if (keep) {
                        keep.checked = true;
                    }
                    if (!newMainChosen()) {
                        img.src = chosenMainSrc();
                    }
                }
                box.closest('li').classList.toggle('is-removing', box.checked);
            });
        });

        // New gallery files. A file input normally forgets the previous
        // choice when it is opened again, so the files picked so far are
        // kept in "chosen" and written back with a DataTransfer. Picking
        // photos one by one therefore adds them up, and each one can be
        // taken off the list again before saving.
        if (gallery && newList) {
            var canKeep = typeof DataTransfer === 'function';
            var chosen = [];

            var sameFile = function (a, b) {
                return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
            };

            var writeBack = function () {
                var transfer = new DataTransfer();
                chosen.forEach(function (file) { transfer.items.add(file); });
                gallery.files = transfer.files;
            };

            var render = function () {
                newList.textContent = '';
                chosen.forEach(function (file, index) {
                    var item = document.createElement('li');
                    var thumb = document.createElement('img');
                    var name = document.createElement('span');
                    thumb.alt = '';
                    thumb.width = 92;
                    thumb.height = 58;
                    name.textContent = file.name;
                    item.appendChild(thumb);
                    item.appendChild(name);

                    if (canKeep) {
                        var drop = document.createElement('button');
                        drop.type = 'button';
                        drop.className = 'gallery-new-remove';
                        drop.textContent = 'Remove';
                        drop.setAttribute('aria-label', 'Do not add ' + file.name);
                        drop.addEventListener('click', function () {
                            chosen.splice(index, 1);
                            writeBack();
                            render();
                            gallery.focus();
                        });
                        item.appendChild(drop);
                    }

                    newList.appendChild(item);
                    readAsDataUrl(file, function (url) { thumb.src = url; });
                });
            };

            gallery.addEventListener('change', function () {
                if (canKeep) {
                    Array.prototype.forEach.call(gallery.files, function (file) {
                        var known = chosen.some(function (kept) { return sameFile(kept, file); });
                        if (!known) {
                            chosen.push(file);
                        }
                    });
                    writeBack();
                } else {
                    chosen = Array.prototype.slice.call(gallery.files);
                }
                render();
            });
        }

        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
