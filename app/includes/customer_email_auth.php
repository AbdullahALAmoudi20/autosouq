<?php
/** AutoSouq customer sign-in codes. Loaded by customer_auth.php. */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/email_template.php';

const CUSTOMER_EMAIL_CODE_TTL = 5 * 60;
const CUSTOMER_EMAIL_SEND_LIMIT = 3;
const CUSTOMER_EMAIL_SEND_IP_LIMIT = 12;

/**
 * True when Resend is set up in secrets.php, so codes and reset links can
 * actually be emailed. When it is not (for example on the free host, which
 * has no domain of our own to send from), password sign-in, sign-up and
 * the account page work directly without an email code, and "Forgot
 * password?" is hidden, instead of every password sign-in failing.
 */
function customer_email_enabled(): bool
{
    return CUSTOMER_EMAIL_RESEND_API_KEY !== ''
        && filter_var(CUSTOMER_EMAIL_FROM, FILTER_VALIDATE_EMAIL) !== false;
}

/** Drop expired challenges even if the customer leaves the form open. */
function customer_email_challenge(): ?array
{
    $challenge = $_SESSION['customer_email_challenge'] ?? null;
    if ($challenge === null || $challenge['expires_at'] <= time()) {
        unset($_SESSION['customer_email_challenge']);
        return null;
    }
    return $challenge;
}

/** Changing the password or email invalidates a code already in flight. */
function customer_email_account_hash(array $user): string
{
    return hash_hmac('sha256', $user['password_hash'] . "\0" . $user['email'], RATE_LIMIT_SECRET);
}

/** Send a code after the correct password has been entered. */
function customer_email_start(array $user, string $next): array
{
    $email = (string) $user['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($email), '.test')) {
        return ['ok' => false, 'message' => 'A deliverable email address is required to sign in.'];
    }
    if (!customer_email_enabled()) {
        return ['ok' => false, 'message' => 'Customer email verification is not configured. Contact the site administrator.'];
    }

    $userId = (int) $user['user_id'];
    $sendKey = hash_hmac('sha256', 'customer-email-send|' . $userId, RATE_LIMIT_SECRET);
    $sendIp = login_ip_hash('customer-email-send');
    $attempt = login_attempt_start($sendKey, $sendIp);
    if ($attempt['key_count'] > CUSTOMER_EMAIL_SEND_LIMIT || $attempt['ip_count'] > CUSTOMER_EMAIL_SEND_IP_LIMIT) {
        login_attempt_forget($attempt['id']);
        $wait = max(
            1,
            login_key_wait('email_hash', $sendKey, CUSTOMER_EMAIL_SEND_LIMIT),
            login_key_wait('ip_hash', $sendIp, CUSTOMER_EMAIL_SEND_IP_LIMIT)
        );
        return ['ok' => false, 'message' => 'Too many code requests. Try again in ' . customer_wait_text($wait) . '.'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    if (!session_regenerate_id(true)) {
        return ['ok' => false, 'message' => 'Could not start verification. Please sign in again.'];
    }
    unset($_SESSION['csrf_token']);

    $response = http_post('https://api.resend.com/emails', [
        'Authorization' => 'Bearer ' . CUSTOMER_EMAIL_RESEND_API_KEY,
    ], [
        'from' => SITE_NAME . ' <' . CUSTOMER_EMAIL_FROM . '>',
        'to' => [$email],
        'subject' => SITE_NAME . ' customer sign-in code',
        'html' => customer_code_email_html((string) $user['full_name'], $code),
        'text' => "Your customer sign-in code is {$code}. It expires in 5 minutes.\n\n"
                  . 'If you did not request this code, ignore this email and let your site administrator know.',
    ], 10);

    if ($response['status'] < 200 || $response['status'] >= 300 || empty($response['data']['id'])) {
        error_log('AutoSouq customer email send failed (HTTP ' . $response['status'] . ').');
        return ['ok' => false, 'message' => 'Could not send the sign-in code. Try again later or contact the site administrator.'];
    }

    $_SESSION['customer_email_challenge'] = [
        'user_id' => $userId,
        'next' => safe_next($next),
        'account_hash' => customer_email_account_hash($user),
        'code_hash' => $codeHash,
        'expires_at' => time() + CUSTOMER_EMAIL_CODE_TTL,
    ];
    return ['ok' => true, 'message' => 'Check your customer email for your sign-in code.'];
}

function customer_email_resend(array $challenge): array
{
    $wait = $challenge['expires_at'] - CUSTOMER_EMAIL_CODE_TTL + 60 - time();
    if ($wait > 0) {
        return ['ok' => false, 'message' => "Please wait {$wait} seconds before requesting another code."];
    }
    $stmt = db()->prepare('SELECT user_id, email, full_name, password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$challenge['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !hash_equals($challenge['account_hash'], customer_email_account_hash($user))) {
        unset($_SESSION['customer_email_challenge']);
        return ['ok' => false, 'message' => 'Please sign in again.'];
    }
    return customer_email_start($user, $challenge['next']);
}

/** Verify a single-use code and grant the customer session. */
function customer_email_verify(string $code): array
{
    $challenge = customer_email_challenge();
    if ($challenge === null) {
        return ['ok' => false, 'message' => 'Your sign-in code expired. Please sign in again.'];
    }
    if (!preg_match('/\A[0-9]{6}\z/', $code)) {
        return ['ok' => false, 'message' => 'Enter the six-digit code from your email.'];
    }

    $userId = $challenge['user_id'];
    $key = hash_hmac('sha256', 'customer-email-verify|' . $userId, RATE_LIMIT_SECRET);
    $ip = login_ip_hash('customer-email-verify');
    $attempt = login_attempt_start($key, $ip);
    if ($attempt['key_count'] > CUSTOMER_MAX_ATTEMPTS || $attempt['ip_count'] > CUSTOMER_MAX_IP_ATTEMPTS) {
        login_attempt_forget($attempt['id']);
        unset($_SESSION['customer_email_challenge']);
        $wait = max(
            1,
            login_key_wait('email_hash', $key, CUSTOMER_MAX_ATTEMPTS),
            login_key_wait('ip_hash', $ip, CUSTOMER_MAX_IP_ATTEMPTS)
        );
        return ['ok' => false, 'message' => 'Too many code attempts. Sign in again in ' . customer_wait_text($wait) . '.'];
    }
    if (!password_verify($code, $challenge['code_hash'])) {
        return ['ok' => false, 'message' => 'That sign-in code is incorrect.'];
    }

    // PHP holds the session lock during verification. Consume the code
    // before rotating the session ID or granting access to customer pages.
    unset($_SESSION['customer_email_challenge']);
    $stmt = db()->prepare(
        'SELECT user_id, password_hash, full_name, email
           FROM users WHERE user_id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false
        || !hash_equals($challenge['account_hash'], customer_email_account_hash($user))
        || $challenge['expires_at'] <= time()) {
        return ['ok' => false, 'message' => 'Verification expired or the account changed. Please sign in again.'];
    }

    unset($_SESSION['csrf_token']);
    login_attempt_clear($key);
    customer_start_session($userId, (string) $user['full_name']);
    return ['ok' => true, 'message' => 'Welcome back, ' . $user['full_name'] . '.'];
}
