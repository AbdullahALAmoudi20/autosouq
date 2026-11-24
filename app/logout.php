<?php
/**
 * AutoSouq - customer sign out.
 *
 * Signing out changes the session, so it only happens on a POST with the
 * CSRF token. Otherwise any other site could sign our visitors out with a
 * plain link or image. A GET (for example someone typing the address)
 * shows a small page with the sign-out button instead.
 *
 * Only the customer keys are cleared (see customer_logout()), so the cart
 * is still there afterwards.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/logout.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';
require_once __DIR__ . '/includes/cart.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    customer_logout();
    flash('info', 'You have been signed out. Your cart has been kept.');
    redirect('index.php');
}

// Nobody to sign out.
if (!is_customer()) {
    redirect('login.php');
}

$pageTitle       = 'Sign out';
$pageDescription = 'Sign out of your AutoSouq account.';
$bodyClass       = 'page-login page-account-auth';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="login-card auth-confirm" aria-labelledby="logout-heading">
        <h1 id="logout-heading">Sign out?</h1>
        <p class="login-sub">
            You are signed in as <strong><?= e(customer_name()) ?></strong>.
            Anything in your cart stays there.
        </p>
        <form method="post" action="logout.php" class="auth-confirm-actions">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit">Sign out</button>
            <a class="btn btn-quiet" href="account.php">Stay signed in</a>
        </form>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
