/* AutoSouq - client-side form validation.
 *
 * Task 13: every form is validated in JavaScript before it is submitted.
 * A form opts in with data-validate="<rule set name>"; the RULES object
 * maps that name to a list of checks, each naming a field, a test
 * function and the message shown when the test fails.
 *
 * Every form is validated in PHP as well, because JavaScript can be
 * switched off, so this check is a convenience and not the defence.
 * Errors go into the <p class="field-error"> under each field, which
 * carries role="alert", and aria-invalid is toggled so assistive software
 * knows which field is wrong (Task 15).
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/js/validation.js
 * @task    Task 13
 */

(function () {
    'use strict';

    /* Reusable tests */
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/;
    var PHONE_RE = /^[0-9+\s()-]{8,20}$/;

    function notEmpty(value) {
        return value.trim().length > 0;
    }

    function minLength(n) {
        return function (value) {
            return value.trim().length >= n;
        };
    }

    function isEmail(value) {
        return EMAIL_RE.test(value.trim());
    }

    function isPhone(value) {
        return PHONE_RE.test(value.trim());
    }

    function isPositiveNumber(value) {
        var n = parseFloat(value);
        return !isNaN(n) && n > 0;
    }

    function isNonNegativeInteger(value) {
        return /^\d+$/.test(value.trim());
    }

    function inRange(min, max) {
        return function (value) {
            var n = parseFloat(value);
            return !isNaN(n) && n >= min && n <= max;
        };
    }

    /**
     * Quantity check for the add-to-cart and cart rows. The upper bound
     * comes from data-max on the input, which PHP wrote from the live
     * stock level.
     */
    function quantityWithinStock(value, field) {
        if (!/^\d+$/.test(value.trim())) {
            return false;
        }
        var wanted = parseInt(value, 10);
        var max = parseInt(field.getAttribute('data-max') || '0', 10);
        return wanted >= 1 && wanted <= max;
    }

    /* The rule sets. The key matches data-validate="..." on the form. */
    var RULES = {

        /* product_details.php - add to cart (Task 5) */
        'add-to-cart': [
            {
                field: 'quantity',
                test: quantityWithinStock,
                message: function (field) {
                    var max = field.getAttribute('data-max') || '0';
                    if (max === '0') {
                        return 'There are no more units available to add.';
                    }
                    return 'Enter a whole number between 1 and ' + max + '.';
                }
            }
        ],

        /* checkout.php - change a quantity on one cart row (Task 6) */
        'cart-qty': [
            {
                field: 'quantity',
                test: quantityWithinStock,
                message: function (field) {
                    return 'Only ' + (field.getAttribute('data-max') || '0')
                         + ' units are in stock.';
                }
            }
        ],

        /* checkout.php - the buyer details before Buy (Task 7) */
        'buy': [
            { field: 'full_name', test: minLength(3),
              message: 'Please enter your full name, at least 3 characters.' },
            { field: 'email', test: isEmail,
              message: 'Please enter a valid email address, for example name@example.com.' },
            { field: 'phone', test: isPhone,
              message: 'Please enter a valid phone number, 8 to 20 digits.' },
            { field: 'city', test: notEmpty,
              message: 'Please tell us which city you are in.' }
        ],

        /* contact.php - the enquiry form */
        'contact': [
            { field: 'name', test: minLength(2),
              message: 'Please tell us your name.' },
            { field: 'email', test: isEmail,
              message: 'We need a valid email address to reply to you.' },
            { field: 'subject', test: notEmpty,
              message: 'Please choose what your message is about.' },
            { field: 'message', test: minLength(10),
              message: 'Please write at least 10 characters.' }
        ],

        /* admin_login.php - manager sign in (Task 8) */
        'login': [
            { field: 'username', test: notEmpty,
              message: 'Please enter your manager id.' },
            { field: 'password', test: minLength(4),
              message: 'Please enter your password.' }
        ],

        /* products.php and admin_manage.php - the search boxes */
        'search': [
            {
                field: 'q',
                test: function (value) {
                    var term = value.trim();
                    return term.length === 0 || term.length >= 2;
                },
                message: 'Type at least 2 characters, or leave the box empty.'
            }
        ],

        /* admin_manage.php - add or modify a vehicle (Tasks 9 and 10) */
        'car': [
            { field: 'car_name', test: minLength(3),
              message: 'The vehicle name must be at least 3 characters.' },
            { field: 'brand_id', test: notEmpty,
              message: 'Please choose a brand.' },
            { field: 'category_id', test: notEmpty,
              message: 'Please choose a body style.' },
            { field: 'model_year', test: inRange(1990, new Date().getFullYear() + 2),
              message: 'Model year must be between 1990 and '
                       + (new Date().getFullYear() + 2) + '.' },
            { field: 'price', test: isPositiveNumber,
              message: 'Price must be a number greater than zero.' },
            { field: 'stock', test: isNonNegativeInteger,
              message: 'Stock must be a whole number, zero or more.' },
            { field: 'engine', test: notEmpty,
              message: 'Please describe the engine, for example 2.5L 4-Cylinder.' },
            { field: 'horsepower', test: inRange(50, 1500),
              message: 'Horsepower must be between 50 and 1500.' },
            { field: 'seats', test: inRange(2, 9),
              message: 'Seats must be between 2 and 9.' }
        ]
    };

    /* Showing and clearing an error */

    function errorBox(field) {
        return document.getElementById(field.name + '-error')
            || document.getElementById(field.id + '-error');
    }

    function showError(field, text) {
        var box = errorBox(field);
        if (box) {
            box.textContent = text;
        }
        field.setAttribute('aria-invalid', 'true');
        field.classList.add('is-invalid');
    }

    function clearError(field) {
        var box = errorBox(field);
        if (box) {
            box.textContent = '';
        }
        field.removeAttribute('aria-invalid');
        field.classList.remove('is-invalid');
    }

    /** Run one rule. Returns true when the field is acceptable. */
    function checkRule(form, rule) {
        var field = form.elements[rule.field];
        if (!field || field.disabled) {
            return true;                       // field not on this page
        }

        var value = field.value === undefined ? '' : String(field.value);

        if (rule.test(value, field)) {
            clearError(field);
            return true;
        }

        var text = typeof rule.message === 'function'
            ? rule.message(field)
            : rule.message;

        showError(field, text);
        return false;
    }

    /* The picture upload on the admin form needs a check the rule table
     * cannot express: allowed file type and under the size cap. */
    var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    var MAX_BYTES = 2 * 1024 * 1024;

    function checkPicture(form) {
        var field = form.elements['picture'];
        if (!field || !field.files || field.files.length === 0) {
            return true;                       // no new picture chosen
        }

        var file = field.files[0];
        var ext = file.name.split('.').pop().toLowerCase();

        if (ALLOWED_EXT.indexOf(ext) === -1) {
            showError(field, 'Allowed picture types are: ' + ALLOWED_EXT.join(', ') + '.');
            return false;
        }
        if (file.size > MAX_BYTES) {
            showError(field, 'That picture is larger than 2 MB. Please choose a smaller file.');
            return false;
        }

        clearError(field);
        return true;
    }

    /* The gallery input takes several files: each one is checked the same
     * way, and together they must fit in the room the car has left. */
    function checkGallery(form) {
        var field = form.elements['gallery[]'];
        if (!field || !field.files || field.files.length === 0) {
            return true;
        }

        // Room left on the car, plus the photos ticked for removal in this
        // same save. When the main picture is only the placeholder, one new
        // photo (or a promoted one) takes that place, which frees one more.
        var room = parseInt(field.getAttribute('data-room'), 10);
        if (!isNaN(room)) {
            room += form.querySelectorAll('input[name="remove_images[]"]:checked').length;
            if (field.getAttribute('data-placeholder') === '1') {
                var mainPick = form.querySelector('input[name="main_image"]:checked');
                var promoting = mainPick !== null && mainPick.value !== '0';
                var newMain = form.elements.picture && form.elements.picture.files
                    && form.elements.picture.files.length > 0;
                if (promoting || !newMain) {
                    room += 1;
                }
            }
        }
        if (!isNaN(room) && field.files.length > room) {
            showError(field, room === 0
                ? 'This car already has the most gallery photos allowed. Remove one first.'
                : 'You can add ' + room + ' more ' + (room === 1 ? 'photo' : 'photos') + ' to this car.');
            return false;
        }

        for (var i = 0; i < field.files.length; i += 1) {
            var file = field.files[i];
            var ext = file.name.split('.').pop().toLowerCase();
            if (ALLOWED_EXT.indexOf(ext) === -1) {
                showError(field, file.name + ': allowed picture types are ' + ALLOWED_EXT.join(', ') + '.');
                return false;
            }
            if (file.size > MAX_BYTES) {
                showError(field, file.name + ' is larger than 2 MB. Please choose a smaller file.');
                return false;
            }
        }

        clearError(field);
        return true;
    }

    /* Wire up every opted-in form */

    function attach(form) {
        var ruleSetName = form.getAttribute('data-validate');
        var rules = RULES[ruleSetName];

        if (!rules) {
            return;
        }

        // Submit is the check that actually blocks the form.
        form.addEventListener('submit', function (event) {
            var firstBadField = null;

            rules.forEach(function (rule) {
                if (!checkRule(form, rule)) {
                    if (!firstBadField) {
                        firstBadField = form.elements[rule.field];
                    }
                }
            });

            if (ruleSetName === 'car' && !checkPicture(form)) {
                if (!firstBadField) {
                    firstBadField = form.elements['picture'];
                }
            }
            if (ruleSetName === 'car' && !checkGallery(form)) {
                if (!firstBadField) {
                    firstBadField = form.elements['gallery[]'];
                }
            }

            if (firstBadField) {
                event.preventDefault();
                firstBadField.focus();
                firstBadField.dispatchEvent(new Event('autosouq:field-jump', { bubbles: true }));

                // Mark the form so the failure is only announced once.
                if (form.dataset.announced !== '1') {
                    form.dataset.announced = '1';
                }
            }
        });

        // Re-check on blur, and while typing only after a field has
        // already failed, so the user is not warned before they finish.
        rules.forEach(function (rule) {
            var field = form.elements[rule.field];
            if (!field || !field.addEventListener) {
                return;
            }

            field.addEventListener('blur', function () {
                if (String(field.value).trim() !== '') {
                    checkRule(form, rule);
                }
            });

            field.addEventListener('input', function () {
                if (field.classList.contains('is-invalid')) {
                    checkRule(form, rule);
                }
            });
        });
    }

    function init() {
        var forms = document.querySelectorAll('form[data-validate]');
        Array.prototype.forEach.call(forms, attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
