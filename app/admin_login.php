<?php
/**
 * AutoSouq - manager sign in page.
 *
 * Task 8: the id and password are checked against the admins table.
 * The POST is handled and the redirect issued before header.php is
 * included, so a successful login never renders this page.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/admin_login.php
 * @task    Task 8
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart.php';

// Already signed in, so skip the form.
if (is_admin()) {
    redirect('admin_dashboard.php');
}

$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = str_input('username');
    $password = (string) ($_POST['password'] ?? '');

    $result = admin_login($username, $password);

    if ($result['ok']) {
        flash('success', $result['message']);
        redirect('admin_dashboard.php');
    }

    flash('error', $result['message']);
}

$lockRemaining = login_lock_remaining();

$pageTitle       = 'Manager sign in';
$pageDescription = 'Authorised showroom managers only.';
$bodyClass       = 'page-login';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <div class="login-shell">

        <section class="login-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Manager sign in</h1>
            <p class="login-sub">
                This area is for AutoSouq staff. Customers do not need an account -
                you can browse and buy without signing in.
            </p>

            <?php if ($lockRemaining > 0): ?>
                <p class="notice notice-warn" role="alert">
                    Too many failed attempts. The form unlocks in
                    <?= $lockRemaining ?> seconds.
                </p>
            <?php endif; ?>

            <form method="post" action="admin_login.php"
                  data-validate="login" novalidate>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="username">Manager id <span class="req">*</span></label>
                    <input type="text" id="username" name="username"
                           value="<?= e($username) ?>"
                           autocomplete="username" maxlength="40"
                           aria-describedby="username-error"
                           <?= $lockRemaining > 0 ? 'disabled' : '' ?>
                           required autofocus>
                    <p class="field-error" id="username-error" role="alert"></p>
                </div>

                <div class="field">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" maxlength="100"
                           aria-describedby="password-error"
                           <?= $lockRemaining > 0 ? 'disabled' : '' ?>
                           required>
                    <p class="field-error" id="password-error" role="alert"></p>
                </div>

                <button class="btn btn-primary btn-lg btn-block" type="submit"
                        <?= $lockRemaining > 0 ? 'disabled' : '' ?>>
                    Sign in
                </button>
            </form>

            <p class="login-back"><a href="index.php">Back to the showroom</a></p>
        </section>

        <aside class="login-aside">
            <h2>Setting up for the first time</h2>
            <p>
                The database ships without manager passwords, because a bcrypt hash
                can only be produced by PHP. Open
                <code>database/setup_admins.php</code> once in your browser to create
                them, then come back here.
            </p>
            <p class="login-aside-note">
                Delete <code>setup_admins.php</code> once you have run it.
            </p>
        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
