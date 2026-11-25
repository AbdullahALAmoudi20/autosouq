<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/password_reset.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    unset($_SESSION['reset_token']);
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $_SESSION['reset_token'] = $token;
    } else {
        flash('error', 'Invalid reset link. Request a fresh link.');
    }
    redirect('reset_password.php');
}
$token = $_SESSION['reset_token'] ?? null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (str_input('action') === 'new_link') {
        unset($_SESSION['reset_token']);
    } elseif (str_input('action') === 'request_link' || $token === null) {
        unset($_SESSION['reset_token']);
        request_password_reset(str_input('email'));
        flash('info', 'If this email has a password account, check your inbox for a reset link.');
        if (str_input('action') === 'request_link') redirect(is_customer() ? 'account.php' : 'login.php');
    } else {
        $error = complete_password_reset($token, raw_input('password'), raw_input('confirm')) ?? '';
        if ($error === '') {
            unset($_SESSION['reset_token'], $_SESSION['customer_email_challenge']);
            flash('success', 'Password updated. Sign in with your new password.');
            redirect('login.php');
        }
    }
    if ($error === '') redirect('reset_password.php');
}
$pageTitle = 'Reset password';
require __DIR__ . '/includes/header.php';
?>
<div class="container"><div class="login-shell verification-shell">
<section class="login-card">
<h1><?= $token === null ? 'Forgot password?' : 'Choose a new password' ?></h1>
<?php if ($error !== ''): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <?php if ($token === null): ?>
        <div class="field"><label for="email">Account email</label><input type="email" name="email" id="email" autocomplete="email" required></div>
        <button class="btn btn-primary">Email reset link</button>
    <?php else: ?>
        <p>Use at least eight characters, including a letter and a number.</p>
        <div class="field"><label for="password">New password</label><input type="password" name="password" id="password" autocomplete="new-password" required></div>
        <div class="field"><label for="confirm">Confirm new password</label><input type="password" name="confirm" id="confirm" autocomplete="new-password" required></div>
        <button class="btn btn-primary">Save password</button>
        <button class="btn btn-ghost" name="action" value="new_link" formnovalidate>Request another link</button>
    <?php endif; ?>
</form>
<p class="login-back"><a href="login.php">Back to sign in</a></p>
</section></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
