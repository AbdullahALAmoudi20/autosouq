<?php
/**
 * Contact us page: showroom address and a Google location map (Task 11).
 *
 * The map uses the free Google Maps embed endpoint
 * (https://www.google.com/maps?q=LAT,LNG&z=15&output=embed), which needs no
 * API key. The coordinates come from SHOP_LAT / SHOP_LNG in config.php, so
 * the printed address and the pin cannot drift apart.
 *
 * The enquiry form is checked by js/validation.js (Task 13) and again in PHP
 * below. It does not send real email, it only confirms.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/contact.php
 * @task    Task 11, Task 13, Task 15
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';

$enquiry = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $enquiry = [
        'name'    => str_input('name'),
        'email'   => str_input('email'),
        'subject' => str_input('subject'),
        'message' => str_input('message'),
    ];

    $errors = [];
    if (mb_strlen($enquiry['name']) < 2) {
        $errors[] = 'Please tell us your name.';
    }
    if (!filter_var($enquiry['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address so we can reply.';
    }
    if ($enquiry['subject'] === '') {
        $errors[] = 'Please choose what your message is about.';
    }
    if (mb_strlen($enquiry['message']) < 10) {
        $errors[] = 'Please write at least 10 characters so we can help properly.';
    }

    if ($errors === []) {
        flash('success', 'Thank you ' . $enquiry['name']
                         . '. Our sales team will reply within one working day.');
        redirect('contact.php');
    }

    foreach ($errors as $message) {
        flash('error', $message);
    }
}

$mapQuery = SHOP_LAT . ',' . SHOP_LNG;
$mapSrc   = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery)
          . '&hl=en&z=15&output=embed';
$directionsUrl = 'https://www.google.com/maps/dir/?api=1&destination='
               . rawurlencode($mapQuery);

$pageTitle       = 'Contact us';
$pageDescription = 'Find the AutoSouq showroom in Riyadh, and how to reach our sales team.';
$bodyClass       = 'page-contact';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head">
        <h1>Come and see us</h1>
        <p class="page-lead">
            Every car on this website is parked at the address below. Walk in,
            take a test drive, and speak to a real person.
        </p>
    </header>

    <div class="contact-layout">

        <section class="contact-details" aria-labelledby="details-heading">
            <h2 id="details-heading">Showroom details</h2>

            <dl class="contact-list">
                <div>
                    <dt>Address</dt>
                    <dd><address><?= e(SHOP_ADDRESS) ?></address></dd>
                </div>
                <div>
                    <dt>Telephone</dt>
                    <dd>
                        <a href="tel:<?= e(str_replace(' ', '', SHOP_PHONE)) ?>">
                            <?= e(SHOP_PHONE) ?>
                        </a>
                    </dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><a href="mailto:<?= e(SHOP_EMAIL) ?>"><?= e(SHOP_EMAIL) ?></a></dd>
                </div>
                <div>
                    <dt>Coordinates</dt>
                    <dd><?= e(SHOP_LAT) ?>, <?= e(SHOP_LNG) ?></dd>
                </div>
                <div>
                    <dt>Opening hours</dt>
                    <dd>
                        Saturday to Thursday, 09:00 to 21:00<br>
                        Friday, 16:00 to 21:00
                    </dd>
                </div>
            </dl>

            <p>
                <a class="btn btn-secondary" href="<?= e($directionsUrl) ?>"
                   target="_blank" rel="noopener noreferrer">
                    Get driving directions
                    <span class="sr-only">(opens Google Maps in a new tab)</span>
                </a>
            </p>
        </section>

        <!-- Task 11: Google location map. The iframe has a title so screen
             readers announce what it contains, loading=lazy keeps it off the
             critical path, and the text below is a fallback for anyone who
             blocks third-party frames. -->
        <section class="contact-map" aria-labelledby="map-heading">
            <h2 id="map-heading">Find us on the map</h2>

            <div class="map-frame">
                <iframe
                    src="<?= e($mapSrc) ?>"
                    title="Google map showing the AutoSouq showroom on King Fahd Road, Riyadh"
                    width="100%" height="380" style="border:0"
                    loading="lazy" allowfullscreen
                    referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>

            <p class="map-fallback">
                If the map does not load, search for
                <strong><?= e(SHOP_ADDRESS) ?></strong>
                or the coordinates <strong><?= e(SHOP_LAT) ?>, <?= e(SHOP_LNG) ?></strong>
                in any maps application.
            </p>
        </section>

    </div>

    <!-- Enquiry form. Validated in the browser by js/validation.js and again
         in PHP at the top of this file. -->
    <section class="section contact-form-section" aria-labelledby="enquiry-heading">
        <h2 id="enquiry-heading">Send us a question</h2>
        <p class="section-sub">
            Ask about stock, finance, a test drive or a trade-in.
        </p>

        <form class="contact-form" method="post" action="contact.php"
              data-validate="contact" novalidate>
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="field">
                    <label for="name">Your name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" maxlength="80"
                           value="<?= e($enquiry['name']) ?>"
                           autocomplete="name"
                           aria-describedby="name-error" required>
                    <p class="field-error" id="name-error" role="alert"></p>
                </div>

                <div class="field">
                    <label for="email">Your email <span class="req">*</span></label>
                    <input type="email" id="email" name="email" maxlength="120"
                           value="<?= e($enquiry['email']) ?>"
                           autocomplete="email"
                           aria-describedby="email-error" required>
                    <p class="field-error" id="email-error" role="alert"></p>
                </div>
            </div>

            <div class="field">
                <label for="subject">What is it about <span class="req">*</span></label>
                <select id="subject" name="subject"
                        aria-describedby="subject-error" required>
                    <option value="">Please choose</option>
                    <?php
                    $subjects = [
                        'Stock enquiry',
                        'Book a test drive',
                        'Finance and instalments',
                        'Trade in my current car',
                        'After-sales and warranty',
                        'Something else',
                    ];
                    foreach ($subjects as $subject):
                        ?>
                        <option value="<?= e($subject) ?>"
                            <?= $enquiry['subject'] === $subject ? 'selected' : '' ?>>
                            <?= e($subject) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-error" id="subject-error" role="alert"></p>
            </div>

            <div class="field">
                <label for="message">Your message <span class="req">*</span></label>
                <textarea id="message" name="message" rows="5" maxlength="1000"
                          aria-describedby="message-hint message-error"
                          required><?= e($enquiry['message']) ?></textarea>
                <p class="field-hint" id="message-hint">At least 10 characters.</p>
                <p class="field-error" id="message-error" role="alert"></p>
            </div>

            <button class="btn btn-primary btn-lg" type="submit">Send message</button>
        </form>
    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
