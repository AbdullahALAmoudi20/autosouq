<?php
/**
 * AutoSouq - manager authentication and page protection.
 *
 * Passwords are stored as bcrypt hashes from password_hash(); the plain
 * text is never stored or echoed back. Login failures give one generic
 * message so an attacker cannot work out which usernames exist. The
 * session id is regenerated on login, and five bad attempts lock the
 * form for 60 seconds.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/includes/auth.php
 * @task    Task 8
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Sentinel written by database/car_dealership.sql before hashes exist. */
const HASH_NOT_SET = 'NEEDS_HASH';

/** How many bad attempts before the form is temporarily locked. */
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 60;

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

/** Seconds remaining on the login lock, or 0 when the form is open. */
function login_lock_remaining(): int
{
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    return max(0, $lockedUntil - time());
}

/**
 * Task 8 - check the submitted id and password against the admins table.
 *
 * @return array{ok: bool, message: string}
 */
function admin_login(string $username, string $password): array
{
    $remaining = login_lock_remaining();
    if ($remaining > 0) {
        return [
            'ok'      => false,
            'message' => 'Too many failed attempts. Please wait ' . $remaining
                         . ' seconds and try again.',
        ];
    }

    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => 'Please enter both your manager id and password.'];
    }

    $stmt = db()->prepare(
        'SELECT admin_id, username, password_hash, full_name, email, is_active
           FROM admins
          WHERE username = :username'
    );
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch();

    // Seed data has no real hash yet, so refuse the login.
    if ($admin !== false && $admin['password_hash'] === HASH_NOT_SET) {
        return [
            'ok'      => false,
            'message' => 'Manager passwords have not been created yet. '
                         . 'Run database/setup_admins.php once, then sign in.',
        ];
    }

    // Same message for an unknown user and a wrong password.
    $badCredentials = ['ok' => false, 'message' => 'Incorrect manager id or password.'];

    if ($admin === false) {
        // Dummy verify so the reply takes about as long as a real one and
        // the timing does not reveal whether the username exists.
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuv');
        register_failed_login();
        return $badCredentials;
    }

    if (!password_verify($password, (string) $admin['password_hash'])) {
        register_failed_login();
        return $badCredentials;
    }

    if ((int) $admin['is_active'] !== 1) {
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

    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

    db()->prepare('UPDATE admins SET last_login = NOW() WHERE admin_id = :id')
        ->execute([':id' => (int) $admin['admin_id']]);

    return ['ok' => true, 'message' => 'Welcome back, ' . $admin['full_name'] . '.'];
}

/** Count one bad attempt and lock the form once the limit is reached. */
function register_failed_login(): void
{
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $_SESSION['login_locked_until'] = time() + LOGIN_LOCK_SECONDS;
        $_SESSION['login_attempts']     = 0;
    }
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
