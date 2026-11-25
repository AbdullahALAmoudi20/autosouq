<?php
/** AutoSouq customer email verification page. */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';

if (is_customer()) {
    redirect('account.php');
}
$challenge = customer_email_challenge();
if ($challenge === null) {
    flash('info', 'Sign in with your email and password to request a code.');
    redirect('login.php');
}

$next = safe_next($challenge['next']);
$login = 'login.php?next=' . rawurlencode($next);
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (str_input('action') === 'restart') {
        unset($_SESSION['customer_email_challenge']);
        redirect($login);
    }

    if (str_input('action') === 'resend') {
        $result = customer_email_resend($challenge);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('verify_email.php');
    }

    $digits = $_POST['digits'] ?? null;
    $code = str_input('code');
    if ($digits !== null) {
        $code = '';
        if (is_array($digits) && count($digits) === 6) {
            foreach ($digits as $digit) {
                if (!is_string($digit) || !preg_match('/^[0-9]$/D', $digit)) {
                    $code = '';
                    break;
                }
                $code .= $digit;
            }
        }
    }
    $result = customer_email_verify($code);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    if ($result['ok']) {
        redirect($next);
    }
    redirect(customer_email_challenge() === null ? $login : 'verify_email.php');
}

$pageTitle = 'Verify customer sign-in';
$bodyClass = 'page-login';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="login-shell verification-shell">
        <section class="login-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Check your email</h1>
            <p class="login-sub" id="code-help">
                Enter the six-digit code sent to your email. It expires after five minutes.
            </p>

            <form method="post" action="verify_email.php">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="code">Sign-in code</label>
                    <div id="code-entry" role="group" aria-label="Six-digit sign-in code">
                    <input type="text" id="code" name="code" autocomplete="one-time-code"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           aria-describedby="code-help" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">Verify code</button>
            </form>

            <form class="login-back" method="post" action="verify_email.php">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost" name="action" value="resend">Resend code</button>
                <button type="submit" class="btn btn-ghost" name="action" value="restart">Start over</button>
            </form>
        </section>
    </div>
</div>

<script src="<?= e(asset('js/verify-code.js')) ?>" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
