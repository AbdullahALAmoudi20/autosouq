<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_auth.php';

function request_password_reset(string $email): void
{
    $email = normalise_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $key = hash_hmac('sha256', 'password-reset|' . $email, RATE_LIMIT_SECRET);
    $attempt = login_attempt_start($key, login_ip_hash('password-reset'));
    if ($attempt['key_count'] > 3 || $attempt['ip_count'] > 12) {
        login_attempt_forget($attempt['id']);
        return;
    }
    $stmt = db()->prepare('SELECT user_id, full_name, password_hash, email FROM users WHERE email = ? AND password_hash IS NOT NULL');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || CUSTOMER_EMAIL_RESEND_API_KEY === '' || !filter_var(CUSTOMER_EMAIL_FROM, FILTER_VALIDATE_EMAIL)) return;

    $token = bin2hex(random_bytes(32));
    $digest = hash('sha256', $token);
    $save = db()->prepare('INSERT INTO password_resets (user_id, token_hash, account_hash, expires_at)
        VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
        ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), account_hash = VALUES(account_hash), expires_at = VALUES(expires_at)');
    $save->execute([$user['user_id'], $digest, customer_email_account_hash($user)]);
    $url = rtrim(CUSTOMER_APP_URL, '/') . '/reset_password.php?token=' . $token;
    $response = http_post('https://api.resend.com/emails', ['Authorization' => 'Bearer ' . CUSTOMER_EMAIL_RESEND_API_KEY], [
        'from' => SITE_NAME . ' <' . CUSTOMER_EMAIL_FROM . '>',
        'to' => [$email],
        'subject' => SITE_NAME . ' password reset',
        'html' => customer_reset_email_html($user['full_name'], $url),
        'text' => "Use this link within 15 minutes to choose a new password:\n\n" . $url
            . "\n\nIf you did not request this change, you can ignore this email.",
    ], 10);
    if (empty($response['data']['id'])) {
        db()->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$digest]);
        error_log('AutoSouq password reset delivery failed (HTTP ' . $response['status'] . ').');
    }
}

function complete_password_reset(string $token, string $password, string $confirm): ?string
{
    $errors = customer_password_errors($password, $confirm);
    if ($errors) return reset($errors);
    $db = db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT u.user_id, u.password_hash, u.email, r.account_hash
            FROM password_resets r JOIN users u ON u.user_id = r.user_id
            WHERE r.token_hash = ? AND r.expires_at > UTC_TIMESTAMP() FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        if (!$user || !hash_equals($user['account_hash'], customer_email_account_hash($user))) {
            $db->rollBack();
            return 'This link has expired or has already been used. Request a fresh link.';
        }
        $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['user_id']]);
        $db->commit();
        return null;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
