<?php
/**
 * Privacy policy. Google requires a public privacy policy link before a
 * "Sign in with Google" app can be published, and it tells customers what
 * the site keeps about them.
 *
 * @author  Abdullah Alamoudi (Back-End)
 * @file    app/privacy.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';

$pageTitle       = 'Privacy policy';
$pageDescription = 'What AutoSouq stores about you and why.';
$bodyClass       = 'page-privacy';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head">
        <h1>Privacy policy</h1>
        <p class="page-lead">
            AutoSouq is a CIS209 Web-Based Systems student project. This page
            explains what the website keeps about you and what it is used for.
        </p>
    </header>

    <section class="panel">
        <h2>What we store</h2>
        <ul>
            <li><strong>Your account:</strong> your name, email address and, if you
                add them, your phone number, city and address. Passwords are stored
                only as a bcrypt hash, never as plain text.</li>
            <li><strong>Sign in with Google:</strong> if you use it, Google gives us
                your name, your email address and an account id. We never see or
                store your Google password.</li>
            <li><strong>Orders:</strong> the cars you buy and the delivery details you
                type at checkout.</li>
            <li><strong>Security:</strong> failed sign-in attempts are kept for a short
                time, as one-way hashes of the email and network address, to stop
                password guessing.</li>
        </ul>
    </section>

    <section class="panel">
        <h2>Cookies and your browser</h2>
        <ul>
            <li>One session cookie keeps you signed in and remembers your cart.
                It is deleted when you sign out or close the browser.</li>
            <li>The compare list and the assistant chat history are kept only in
                your own browser (local storage and session storage).</li>
            <li>There are no advertising or tracking cookies.</li>
        </ul>
    </section>

    <section class="panel">
        <h2>The AI assistant</h2>
        <p>
            Questions you type into the assistant are sent to Anthropic (Claude)
            together with the matching car and help articles, so it can write the
            answer. Do not type personal details into the assistant.
        </p>
    </section>

    <section class="panel">
        <h2>Sharing and deleting your data</h2>
        <p>
            We do not sell or share your data with anyone else. To see or delete
            your account and orders, email
            <a href="mailto:<?= e(SHOP_EMAIL) ?>"><?= e(SHOP_EMAIL) ?></a>.
        </p>
    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
