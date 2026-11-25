<?php
/**
 * AutoSouq - customer sign in page.
 *
 * Email and password, or "Continue with Google" when Google sign-in is set
 * up. The POST is handled before header.php is included, so a successful
 * sign-in redirects without ever drawing this page. After signing in the
 * visitor goes back to the page in ?next= (checked by safe_next()), or to
 * the home page.
 *
 * Accessibility: every input has a label, errors are listed at the top in
 * a role="alert" box with links to the fields, and a bad field is marked
 * with aria-invalid and points at its message with aria-describedby.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/login.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';
require_once __DIR__ . '/includes/cart.php';

$next = safe_next(str_input('next'));

// Already signed in, so the form has nothing to do.
if (is_customer()) {
    redirect($next);
}

$email        = '';
$errors       = [];   // field name => message
$formMessage  = '';   // the line at the top of the error box
$badPassword  = false;
$lockRemaining = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = str_input('email');
    $password = raw_input('password');

    if ($email === '') {
        $errors['email'] = 'Please enter your email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Please enter your password.';
    }

    if ($errors === []) {
        $result = customer_login($email, $password, $next);

        if ($result['ok']) {
            redirect('verify_email.php');
        }

        $formMessage   = $result['message'];
        $lockRemaining = (int) ($result['locked'] ?? 0);
        // A wrong email or password is not tied to one field, but both
        // inputs are marked so a screen reader user knows where to look.
        $badPassword = $lockRemaining === 0 && empty($result['credentials_valid']);
    } else {
        $formMessage = 'Please fill in both fields.';
    }
}

// The lock is kept in the database. Without an email we can only check
// the lock on this IP address; an email lock shows after the next try.
$lockRemaining = max($lockRemaining, customer_lock_remaining());
$locked        = $lockRemaining > 0 ? ' disabled' : '';

/** aria attributes for one input, including the shared "wrong login" case. */
$ariaFor = static function (string $field) use ($errors, $badPassword): string {
    if ($badPassword) {
        return ' aria-invalid="true" aria-describedby="form-errors"';
    }
    return field_aria($errors, $field);
};

// Put the cursor where the visitor needs to type next.
$focusField = isset($errors['email']) || $email === '' ? 'email' : 'password';

$pageTitle       = 'Sign in';
$pageDescription = 'Sign in to your AutoSouq account to see your orders.';
$bodyClass       = 'page-login page-account-auth';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <div class="login-shell">

        <section class="login-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Sign in</h1>
            <p class="login-sub">
                Sign in to see your orders and check out faster. You can still buy
                without an account.
            </p>

            <?php if ($lockRemaining > 0): ?>
                <p class="notice notice-warn" role="alert">
                    Too many failed attempts. The form unlocks in
                    <?= e(customer_wait_text($lockRemaining)) ?>.
                </p>
            <?php elseif ($formMessage !== ''): ?>
                <div class="form-errors" id="form-errors" role="alert">
                    <p class="form-errors-title"><?= e($formMessage) ?></p>
                    <?php if ($errors !== []): ?>
                        <ul>
                            <?php foreach ($errors as $field => $message): ?>
                                <li><a href="#<?= e($field) ?>"><?= e($message) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (google_enabled()): ?>
                <a class="btn btn-google btn-block"
                   href="<?= e('google_login.php?next=' . rawurlencode($next)) ?>">
                    <svg class="google-mark" viewBox="0 0 48 48" width="20" height="20"
                         aria-hidden="true" focusable="false">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    Continue with Google
                </a>
                <p class="auth-divider"><span>or sign in with your email</span></p>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">

                <div class="field">
                    <label for="email">Email <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" id="email" name="email"
                           value="<?= e($email) ?>"
                           autocomplete="email" maxlength="120" inputmode="email"
                           <?= $ariaFor('email') ?><?= $locked ?>
                           <?= $focusField === 'email' && $lockRemaining === 0 ? 'autofocus' : '' ?>
                           required>
                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error" id="email-error"><?= e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password">Password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" maxlength="100"
                           <?= $ariaFor('password') ?><?= $locked ?>
                           <?= $focusField === 'password' && $lockRemaining === 0 ? 'autofocus' : '' ?>
                           required>
                    <?php if (isset($errors['password'])): ?>
                        <p class="field-error" id="password-error"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <button class="btn btn-primary btn-lg btn-block" type="submit"<?= $locked ?>>
                    Sign in
                </button>
            </form>
            <div class="login-back"><?php require __DIR__ . '/includes/forgot_password_dialog.php'; ?></div>

            <p class="auth-switch">
                New to <?= e(SITE_NAME) ?>?
                <a href="<?= e('register.php?next=' . rawurlencode($next)) ?>">Create an account</a>
            </p>
            <p class="login-back"><a href="index.php">Back to the showroom</a></p>
        </section>

        <aside class="login-aside" aria-labelledby="why-heading">
            <h2 id="why-heading">Why have an account?</h2>
            <ul class="auth-benefits">
                <li>See every order you place, on any device.</li>
                <li>Your name and phone are filled in at checkout.</li>
                <li>Track the status of a reservation, from placed to delivered.</li>
            </ul>
            <p class="login-aside-note">
                Managers sign in on the <a href="admin_login.php">manager page</a> instead.
            </p>
        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
