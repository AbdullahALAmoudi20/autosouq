<?php
/**
 * AutoSouq - manager authentication and page protection.
 *
 * Passwords are stored as bcrypt hashes from password_hash(); the plain
 * text is never stored or echoed back. Login failures give one generic
 * message so an attacker cannot work out which usernames exist. The
 * session id is regenerated on login. Five bad attempts for one manager
 * id, or twenty from one IP address, lock the form for 15 minutes.
 *
 * The bad attempts are kept in the login_attempts table, the same one the
 * customer sign-in uses (helpers in customer_auth.php), not in the
 * session: an attacker could simply drop the cookie before every guess.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/includes/auth.php
 * @task    Task 8
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/customer_auth.php';   // shared login_attempts helpers

/** Sentinel written by database/car_dealership.sql before hashes exist. */
const HASH_NOT_SET = 'NEEDS_HASH';

/**
 * How many bad attempts before the form is temporarily locked, per
 * manager id and per IP address. The window is the same 15 minutes as
 * the customer lock, because the shared counting code uses that one.
 */
const MAX_LOGIN_ATTEMPTS    = 5;
const MAX_LOGIN_IP_ATTEMPTS = 20;
const LOGIN_LOCK_SECONDS    = CUSTOMER_LOCK_WINDOW;

/** A manager who does nothing for this long is signed out. */
const ADMIN_IDLE_SECONDS = 30 * 60;

/** True when a manager is logged in on this session. */
function is_admin(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    // Sign out a manager who left the page open and walked away.
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && time() - $last > ADMIN_IDLE_SECONDS) {
        admin_logout();
        flash('info', 'You were signed out after 30 minutes without activity.');
        return false;
    }
    $_SESSION['admin_last_seen'] = time();
    return true;
}

/** The logged-in manager display name, or an empty string. */
function admin_name(): string
{
    return (string) ($_SESSION['admin_name'] ?? '');
}

/** The logged-in manager username, or an empty string. */
function admin_username(): string
{
    return (string) ($_SESSION['admin_username'] ?? '');
}

/** Gate an admin page. Call before any HTML is sent. */
function require_admin(): void
{
    if (!is_admin()) {
        flash('error', 'Please sign in as a manager to open that page.');
        redirect('admin_login.php');
    }
}

/**
 * Keyed hash of a manager id for the login_attempts table. The "admin|"
 * prefix keeps it apart from the customer email hashes in the same
 * column. Lowercased because the username column ignores case, so "Ali"
 * and "ali" must share one counter.
 */
function admin_key_hash(string $username): string
{
    return hash_hmac('sha256', 'admin|' . mb_strtolower(trim($username)), RATE_LIMIT_SECRET);
}

/**
 * Seconds remaining on the login lock, or 0 when the form is open. The IP
 * lock is always checked; the manager id lock only when we know the id.
 */
function login_lock_remaining(string $username = ''): int
{
    $wait = login_key_wait('ip_hash', login_ip_hash('admin-ip'), MAX_LOGIN_IP_ATTEMPTS);
    if (trim($username) !== '') {
        $wait = max($wait, login_key_wait('email_hash', admin_key_hash($username), MAX_LOGIN_ATTEMPTS));
    }
    return $wait;
}

/**
 * Task 8 - check the submitted id and password against the admins table.
 *
 * Each try is written to login_attempts before the password is checked
 * and then counted (see customer_auth.php SECTION B2), so parallel
 * requests cannot all slip in under the limit. A refused try's row is
 * removed again, so the lock still ends on time.
 *
 * @return array{ok: bool, message: string}
 */
function admin_login(string $username, string $password): array
{
    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => 'Please enter both your manager id and password.'];
    }

    $keyHash = admin_key_hash($username);
    $attempt = login_attempt_start($keyHash, login_ip_hash('admin-ip'));

    if ($attempt['key_count'] > MAX_LOGIN_ATTEMPTS
        || $attempt['ip_count'] > MAX_LOGIN_IP_ATTEMPTS) {
        login_attempt_forget($attempt['id']);
        $remaining = max(1, login_lock_remaining($username));
        return [
            'ok'      => false,
            'message' => 'Too many failed attempts. Please wait '
                         . customer_wait_text($remaining) . ' and try again.',
        ];
    }

    $stmt = db()->prepare(
        'SELECT admin_id, username, password_hash, full_name, email, is_active
           FROM admins
          WHERE username = :username'
    );
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch();

    // Seed data has no real hash yet, so refuse the login.
    // No password was checked, so this try does not count as a failure.
    if ($admin !== false && $admin['password_hash'] === HASH_NOT_SET) {
        login_attempt_forget($attempt['id']);
        return [
            'ok'      => false,
            'message' => 'Manager passwords have not been created yet. '
                         . 'Run database/setup_admins.php once, then sign in.',
        ];
    }

    // Same message for an unknown user and a wrong password.
    $badCredentials = ['ok' => false, 'message' => 'Incorrect manager id or password.'];

    // A failure needs no extra write: its row is already in the table.
    if ($admin === false) {
        // Dummy verify so the reply takes about as long as a real one and
        // the timing does not reveal whether the username exists. It must
        // be a complete bcrypt hash, or password_verify() returns at once.
        password_verify($password, CUSTOMER_DUMMY_HASH);
        return $badCredentials;
    }

    if (!password_verify($password, (string) $admin['password_hash'])) {
        return $badCredentials;
    }

    if ((int) $admin['is_active'] !== 1) {
        // The password was right, so this is not a guess to count.
        login_attempt_forget($attempt['id']);
        return ['ok' => false, 'message' => 'This manager account has been disabled.'];
    }

    // Login succeeded.
    session_regenerate_id(true);               // stop session fixation

    $_SESSION['admin_id']       = (int) $admin['admin_id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    $_SESSION['admin_name']     = (string) $admin['full_name'];
    $_SESSION['admin_email']    = (string) $admin['email'];
    $_SESSION['admin_since']    = time();
    $_SESSION['admin_last_seen'] = time();

    // Clears this try's row and the old failures for this manager id.
    login_attempt_clear($keyHash);

    db()->prepare('UPDATE admins SET last_login = NOW() WHERE admin_id = :id')
        ->execute([':id' => (int) $admin['admin_id']]);

    return ['ok' => true, 'message' => 'Welcome back, ' . $admin['full_name'] . '.'];
}

/**
 * Log the manager out. Only the admin keys are cleared, so a customer
 * shopping in the same browser keeps their cart.
 */
function admin_logout(): void
{
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_name'],
        $_SESSION['admin_email'],
        $_SESSION['admin_since'],
        $_SESSION['admin_last_seen']
    );
    session_regenerate_id(true);
}
