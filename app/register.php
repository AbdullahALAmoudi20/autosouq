<?php
/**
 * AutoSouq - create a customer account.
 *
 * Name, email and password are required. Phone, city and address are
 * optional and only used to fill in the checkout form later. The rules are
 * checked on the server by customer_register(); on success the new
 * customer is sent to sign in and verify their email.
 *
 * Accessibility: labels on every input, the password rules are written
 * under the field (not only shown after a mistake), errors are listed in a
 * role="alert" box at the top with links to each field, and every bad
 * field gets aria-invalid plus aria-describedby pointing at its message.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/register.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';
require_once __DIR__ . '/includes/cart.php';

$next = safe_next(str_input('next'));

// A signed-in customer already has an account.
if (is_customer()) {
    redirect($next);
}

/** Form values, refilled when the form comes back with errors. */
$form = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'city'      => '',
    'address'   => '',
];
$errors      = [];
$formMessage = '';
$emailTaken  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach (array_keys($form) as $key) {
        $form[$key] = str_input($key);
    }

    // Passwords are passed on exactly as typed, spaces included.
    $result = customer_register($form + [
        'password'         => raw_input('password'),
        'password_confirm' => raw_input('password_confirm'),
    ]);

    if ($result['ok']) {
        flash('success', $result['message']);
        redirect('login.php?next=' . rawurlencode($next));
    }

    $errors      = $result['errors'] ?? [];
    $formMessage = $result['message'];
    $emailTaken  = !empty($result['email_taken']);
}

/** Field labels, used for the links in the error box. */
$labels = [
    'full_name'        => 'Full name',
    'email'            => 'Email',
    'password'         => 'Password',
    'password_confirm' => 'Confirm password',
    'phone'            => 'Phone',
    'city'             => 'City',
    'address'          => 'Address',
];

$pageTitle       = 'Create an account';
$pageDescription = 'Create an AutoSouq account to keep track of your orders.';
$bodyClass       = 'page-login page-account-auth';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <div class="login-shell">

        <section class="login-card" aria-labelledby="register-heading">
            <h1 id="register-heading">Create an account</h1>
            <p class="login-sub">
                It takes a minute. Fields marked <span class="req">*</span> are required.
            </p>

            <?php if ($formMessage !== ''): ?>
                <div class="form-errors" id="form-errors" role="alert">
                    <p class="form-errors-title"><?= e($formMessage) ?></p>
                    <?php if ($errors !== []): ?>
                        <ul>
                            <?php foreach ($errors as $field => $message): ?>
                                <li>
                                    <a href="#<?= e($field) ?>">
                                        <?= e($labels[$field] ?? $field) ?>: <?= e($message) ?>
                                    </a>
                                </li>
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
                <p class="auth-divider"><span>or sign up with your email</span></p>
            <?php endif; ?>

            <form method="post" action="register.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">

                <div class="field">
                    <label for="full_name">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= e($form['full_name']) ?>"
                           autocomplete="name" maxlength="80"
                           <?= field_aria($errors, 'full_name') ?>
                           <?= $errors === [] && $formMessage === '' ? 'autofocus' : '' ?>
                           required>
                    <?php if (isset($errors['full_name'])): ?>
                        <p class="field-error" id="full_name-error"><?= e($errors['full_name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="email">Email <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" id="email" name="email"
                           value="<?= e($form['email']) ?>"
                           autocomplete="email" maxlength="120" inputmode="email"
                           <?= field_aria($errors, 'email', 'email-hint') ?>
                           required>
                    <p class="field-hint" id="email-hint">You will use this to sign in.</p>
                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error" id="email-error">
                            <?= e($errors['email']) ?>
                            <?php if ($emailTaken): ?>
                                <a href="<?= e('login.php?next=' . rawurlencode($next)) ?>">Go to sign in</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password">Password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" minlength="8" maxlength="72"
                           <?= field_aria($errors, 'password', 'password-hint') ?>
                           required>
                    <p class="field-hint" id="password-hint">
                        At least 8 characters, with at least one letter and one number.
                    </p>
                    <?php if (isset($errors['password'])): ?>
                        <p class="field-error" id="password-error"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password_confirm">Confirm password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           autocomplete="new-password" maxlength="72"
                           <?= field_aria($errors, 'password_confirm') ?>
                           required>
                    <?php if (isset($errors['password_confirm'])): ?>
                        <p class="field-error" id="password_confirm-error"><?= e($errors['password_confirm']) ?></p>
                    <?php endif; ?>
                </div>

                <fieldset class="auth-optional">
                    <legend>Contact details <span class="optional">(optional)</span></legend>
                    <p class="field-hint auth-optional-hint">
                        We only use these to fill in the checkout form for you.
                    </p>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= e($form['phone']) ?>"
                               autocomplete="tel" maxlength="25" placeholder="05XXXXXXXX"
                               <?= field_aria($errors, 'phone', 'phone-hint') ?>>
                        <p class="field-hint" id="phone-hint">
                            Digits, spaces, brackets and + are accepted.
                        </p>
                        <?php if (isset($errors['phone'])): ?>
                            <p class="field-error" id="phone-error"><?= e($errors['phone']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city"
                               value="<?= e($form['city']) ?>"
                               autocomplete="address-level2" maxlength="60"
                               <?= field_aria($errors, 'city') ?>>
                        <?php if (isset($errors['city'])): ?>
                            <p class="field-error" id="city-error"><?= e($errors['city']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="2" maxlength="200"
                                  autocomplete="street-address"
                                  <?= field_aria($errors, 'address') ?>><?= e($form['address']) ?></textarea>
                        <?php if (isset($errors['address'])): ?>
                            <p class="field-error" id="address-error"><?= e($errors['address']) ?></p>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <button class="btn btn-primary btn-lg btn-block" type="submit">
                    Create my account
                </button>
            </form>

            <p class="auth-switch">
                Already have an account?
                <a href="<?= e('login.php?next=' . rawurlencode($next)) ?>">Sign in</a>
            </p>
            <p class="login-back"><a href="index.php">Back to the showroom</a></p>
        </section>

        <aside class="login-aside" aria-labelledby="privacy-heading">
            <h2 id="privacy-heading">What we keep</h2>
            <ul class="auth-benefits">
                <li>Your name, email and the contact details you choose to add.</li>
                <li>Your password is stored only as a secure hash. Nobody at
                    <?= e(SITE_NAME) ?> can read it.</li>
                <li>Orders you place while signed in are linked to your account.</li>
            </ul>
            <p class="login-aside-note">
                You can change your details at any time on your account page.
            </p>
        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
