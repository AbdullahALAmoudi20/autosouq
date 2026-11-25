<?php
/**
 * AutoSouq - customer accounts: sign up, sign in, profile, and
 * "Sign in with Google".
 *
 * Customers still do not need an account to buy. An account only lets a
 * buyer see every order they placed, from any device, and fills the
 * checkout form for them. Guests keep the old cookie-based history.
 *
 * The customer login uses its own session keys (user_id, user_name), so it
 * never mixes with the manager login in includes/auth.php. The same browser
 * can be signed in as both, and signing out of one leaves the other and the
 * cart alone.
 *
 * Passwords follow the same rules as the manager login: bcrypt through
 * password_hash(), one generic error message and a dummy check for unknown
 * emails so the timing gives nothing away. Failed sign-ins are counted in
 * the login_attempts table (not the session, which an attacker can simply
 * throw away), per email and per IP address, and lock the form for a while.
 *
 * Each session remembers a fingerprint of the account's password hash and
 * Google id. When either changes (a new password, Google linked), every
 * other session of that account is signed out on its next page.
 *
 * Google sign-in is the normal server-side OAuth 2.0 flow with PKCE. We
 * only ask Google for the person's id, name and verified email, and we
 * never keep Google's tokens.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/includes/customer_auth.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/http.php';

/**
 * Failed sign-ins allowed inside the window, per email and per IP address.
 * The IP limit is higher because a family or an office can share one.
 */
const CUSTOMER_MAX_ATTEMPTS    = 5;
const CUSTOMER_MAX_IP_ATTEMPTS = 20;
const CUSTOMER_LOCK_WINDOW     = 900;   // 15 minutes, in seconds

/** Length limits, matching the users table. */
const CUSTOMER_NAME_MIN  = 3;
const CUSTOMER_NAME_MAX  = 80;
const CUSTOMER_EMAIL_MAX = 120;
const CUSTOMER_PASS_MIN  = 8;
const CUSTOMER_PASS_MAX  = 72;   // bcrypt ignores anything after 72 bytes

/**
 * A real bcrypt hash of a throwaway word. Unknown emails are checked
 * against it so a wrong email takes as long as a wrong password. It has to
 * be a valid hash: a malformed one makes password_verify() return at once,
 * which would undo the point.
 */
const CUSTOMER_DUMMY_HASH = '$2y$10$llPryPENPvCASIZM2yGZGektqpAXvKl83/kIxMSrnOReLxE6I78BS';

/** Pages that must never be the "next" page, or sign-in would loop. */
const CUSTOMER_NO_NEXT = [
    'login.php', 'register.php', 'logout.php', 'google_login.php', 'google_callback.php',
];

/** A Google sign-in that takes longer than this has to start again. */
const GOOGLE_STATE_SECONDS = 600;


/* SECTION A: WHO IS SIGNED IN */

/**
 * The signed-in customer's row from the users table, or null.
 *
 * The row is read fresh from the database (not trusted from the session),
 * once per request. If the account was deleted while the person was signed
 * in, the session is cleared so the site stops treating them as a member.
 *
 * @param bool $reload read again, after this request changed the row
 */
function current_user(bool $reload = false): ?array
{
    static $loaded = false;
    static $user   = null;

    if ($loaded && !$reload) {
        return $user;
    }
    $loaded = true;
    $user   = null;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, password_hash, google_sub,
                phone, city, address, created_at, last_login
           FROM users
          WHERE user_id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    if ($row === false) {
        customer_logout();
        return null;
    }

    // The password or the Google link changed since this session signed
    // in (maybe on another device, after a leak), so this session ends.
    $saved = (string) ($_SESSION['user_cred'] ?? '');
    if (!hash_equals(customer_credential_fingerprint($row), $saved)) {
        customer_logout();
        if ($saved !== '') {
            flash('info', 'Your password or sign-in method was changed, so please sign in again.');
        }
        return null;
    }

    $user = $row;
    return $user;
}

/** True when a customer is signed in on this session. */
function is_customer(): bool
{
    return current_user() !== null;
}

/** The signed-in customer's name, or an empty string. */
function customer_name(): string
{
    $user = current_user();
    return $user === null ? '' : (string) $user['full_name'];
}

/**
 * Gate a customer page. Call before any HTML is sent.
 *
 * @param string $next the page to come back to after signing in
 */
function require_customer(string $next = ''): void
{
    if (!is_customer()) {
        flash('info', 'Please sign in to open that page.');
        redirect('login.php?next=' . rawurlencode(safe_next($next)));
    }
}

/**
 * The page to go to after signing in. The value comes from the query
 * string, so only a bare page of ours is allowed ("checkout.php" or
 * "product_details.php?id=7"). Anything else, such as a full URL to
 * another site, falls back to the home page, so a normal sign-in (email
 * or Google) lands the customer straight on the showroom. This also stops
 * the sign-in form being used as an open redirect.
 */
function safe_next(string $next): string
{
    // The D flag stops "$" from also matching before a trailing newline.
    if (!preg_match('/^[a-z_]+\.php(\?[a-z_]+=[0-9]+)?$/D', $next)) {
        return 'index.php';
    }
    $page = explode('?', $next, 2)[0];
    return in_array($page, CUSTOMER_NO_NEXT, true) ? 'index.php' : $next;
}

/** Lowercase and trim an email so "Ali@Mail.com " and "ali@mail.com" match. */
function normalise_email(string $email): string
{
    return mb_strtolower(trim($email));
}


require_once __DIR__ . '/customer_email_auth.php';

/* SECTION B: SESSION HELPERS */

/**
 * Mark the session as signed in as this user. Shared by the password
 * email verification and Google sign-in.
 */
function customer_start_session(int $userId, string $fullName): void
{
    session_regenerate_id(true);               // stop session fixation
    unset($_SESSION['customer_email_challenge']);

    $_SESSION['user_id']    = $userId;
    $_SESSION['user_name']  = $fullName;
    $_SESSION['user_since'] = time();

    db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id')
        ->execute([':id' => $userId]);

    customer_store_fingerprint($userId);
    current_user(true);
}

/**
 * A keyed hash of the password hash and the Google id. Stored in the
 * session at sign-in; current_user() signs the session out when the row no
 * longer gives the same value. Keyed so the session file never holds
 * anything that helps guess the password.
 */
function customer_credential_fingerprint(array $row): string
{
    return hash_hmac(
        'sha256',
        ($row['password_hash'] ?? 'none') . '|' . ($row['google_sub'] ?? 'none'),
        RATE_LIMIT_SECRET
    );
}

/** Save the account's current fingerprint in this session. */
function customer_store_fingerprint(int $userId): void
{
    $stmt = db()->prepare('SELECT password_hash, google_sub FROM users WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    $_SESSION['user_cred'] = $row === false ? '' : customer_credential_fingerprint($row);
}

/**
 * Sign the customer out. Only the customer keys are removed, so the cart
 * and a manager login in the same browser are kept (same idea as
 * admin_logout()).
 */
function customer_logout(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_since'],
        $_SESSION['user_cred'],
        $_SESSION['google_oauth'],
        $_SESSION['customer_email_challenge']
    );
    // current_user() can call this half way through a page, after the
    // HTML has started, and PHP cannot change the session id by then.
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    current_user(true);   // forget the row cached for this request
}


/* SECTION B2: FAILED SIGN-IN LOCK
 * Shared by this file and the manager login in includes/auth.php. Only
 * keyed hashes of the email (or manager id) and the IP address are
 * stored, so the table holds no personal data in plain text.
 *
 * Every try is written down FIRST and only then counted. If we counted
 * first and wrote the failure after the password check, a burst of
 * parallel requests would all see "not locked yet" and all get a guess. */

/** Keyed hash of a (normalised) email, for the login_attempts table. */
function customer_email_hash(string $email): string
{
    return hash_hmac('sha256', 'email|' . normalise_email($email), RATE_LIMIT_SECRET);
}

/**
 * Keyed hash of the visitor's IP address. An IPv6 user usually gets a
 * whole /64 block, so only the first 64 bits count; otherwise they could
 * pick a new address for every guess.
 *
 * @param string $scope 'ip' for customers, 'admin-ip' for managers, so a
 *                      run of bad manager logins does not lock shoppers
 */
function login_ip_hash(string $scope = 'ip'): string
{
    $ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16) {
        $ip = bin2hex(substr($packed, 0, 8)) . '::/64';
    }
    return hash_hmac('sha256', $scope . '|' . $ip, RATE_LIMIT_SECRET);
}

/**
 * Seconds until one key or IP hash drops back under its limit, or 0 when
 * it is not locked. The lock ends when the oldest failure that still
 * counts leaves the 15-minute window.
 */
function login_key_wait(string $column, string $hash, int $limit): int
{
    // The column name comes from this fixed list only, never from input.
    $where = match ($column) {
        'email_hash' => 'email_hash = :hash',
        'ip_hash'    => 'ip_hash = :hash',
    };
    // Look at the $limit-th newest failure in the window. $limit is one of
    // our constants, so writing it into the SQL is safe.
    $stmt = db()->prepare(
        'SELECT GREATEST(0, :window_left - TIMESTAMPDIFF(SECOND, created_at, NOW()))
           FROM login_attempts
          WHERE ' . $where . '
            AND created_at > NOW() - INTERVAL :window_since SECOND
       ORDER BY created_at DESC, attempt_id DESC
          LIMIT 1 OFFSET ' . ($limit - 1)
    );
    $stmt->execute([
        ':window_left'  => CUSTOMER_LOCK_WINDOW,
        ':hash'         => $hash,
        ':window_since' => CUSTOMER_LOCK_WINDOW,
    ]);
    $wait = $stmt->fetchColumn();
    return $wait === false ? 0 : (int) $wait;
}

/**
 * Write one sign-in try down, before the password is checked, then count
 * the tries in the window for this key and this IP, the new one included.
 *
 * The caller refuses when a count is over the limit. Because the row is
 * written first, at most $limit of any number of parallel tries can see a
 * count inside the limit: each one already counts every try that got its
 * row in before it.
 *
 * @return array{id: int, key_count: int, ip_count: int}
 */
function login_attempt_start(string $keyHash, string $ipHash): array
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (email_hash, ip_hash) VALUES (:email_hash, :ip_hash)')
        ->execute([':email_hash' => $keyHash, ':ip_hash' => $ipHash]);
    $id = (int) $pdo->lastInsertId();

    // Now and then, drop rows too old to matter, so the table stays small
    // without a cron job (the free host has none).
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    }

    // Native prepares cannot reuse a placeholder, hence the "2" copies.
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(email_hash = :key_hash), 0) AS key_count,
                COALESCE(SUM(ip_hash = :ip_hash), 0)     AS ip_count
           FROM login_attempts
          WHERE (email_hash = :key_hash2 OR ip_hash = :ip_hash2)
            AND created_at > NOW() - INTERVAL :window SECOND'
    );
    $stmt->execute([
        ':key_hash'  => $keyHash,
        ':ip_hash'   => $ipHash,
        ':key_hash2' => $keyHash,
        ':ip_hash2'  => $ipHash,
        ':window'    => CUSTOMER_LOCK_WINDOW,
    ]);
    $row = $stmt->fetch();

    return [
        'id'        => $id,
        'key_count' => (int) ($row['key_count'] ?? 0),
        'ip_count'  => (int) ($row['ip_count'] ?? 0),
    ];
}

/**
 * Remove one try's row again. Used when the try was refused before the
 * password was even checked: it is not a failure, and keeping it would
 * push the end of the lock further away with every refused try.
 */
function login_attempt_forget(int $attemptId): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_id = :id')
        ->execute([':id' => $attemptId]);
}

/** A good sign-in clears that key's failures (the IP's stay). */
function login_attempt_clear(string $keyHash): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email_hash = :email_hash')
        ->execute([':email_hash' => $keyHash]);
}

/**
 * Seconds left on the sign-in lock, or 0 when the form is open. The IP
 * lock is always checked; the email lock only when we know the email.
 */
function customer_lock_remaining(string $email = ''): int
{
    $wait = login_key_wait('ip_hash', login_ip_hash('ip'), CUSTOMER_MAX_IP_ATTEMPTS);
    if (normalise_email($email) !== '') {
        $wait = max($wait, login_key_wait('email_hash', customer_email_hash($email), CUSTOMER_MAX_ATTEMPTS));
    }
    return $wait;
}

/** "45 seconds" or "about 12 minutes", for the lock message. */
function customer_wait_text(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ($seconds === 1 ? ' second' : ' seconds');
    }
    $minutes = (int) ceil($seconds / 60);
    return 'about ' . $minutes . ($minutes === 1 ? ' minute' : ' minutes');
}


/* SECTION C: VALIDATION
 * Errors are keyed by field name, so a page can put the message next to
 * the right input and mark only that input as invalid. */

/**
 * Check the contact fields shared by sign-up and the profile form.
 *
 * @return array{values: array, errors: array<string,string>}
 */
function customer_validate_details(array $input): array
{
    $values = [
        'full_name' => trim((string) ($input['full_name'] ?? '')),
        'phone'     => trim((string) ($input['phone'] ?? '')),
        'city'      => trim((string) ($input['city'] ?? '')),
        'address'   => trim((string) ($input['address'] ?? '')),
    ];
    $errors = [];

    $nameLength = mb_strlen($values['full_name']);
    if ($nameLength < CUSTOMER_NAME_MIN || $nameLength > CUSTOMER_NAME_MAX) {
        $errors['full_name'] = 'Please enter your full name (3 to 80 characters).';
    }
    // Phone, city and address are optional, but must be sensible if given.
    if ($values['phone'] !== '' && !preg_match('/^[0-9+\s()-]{8,20}$/', $values['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number, or leave it empty.';
    }
    if (mb_strlen($values['city']) > 60) {
        $errors['city'] = 'The city name can be at most 60 characters.';
    }
    if (mb_strlen($values['address']) > 200) {
        $errors['address'] = 'The address can be at most 200 characters.';
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Check a new password and its confirmation. Returns error messages keyed
 * by field ('password' or 'password_confirm').
 *
 * @return array<string,string>
 */
function customer_password_errors(string $password, string $confirm, string $field = 'password'): array
{
    if (strlen($password) < CUSTOMER_PASS_MIN
        || !preg_match('/[A-Za-z]/', $password)
        || !preg_match('/[0-9]/', $password)) {
        return [$field => 'Your password needs at least 8 characters, with at least one letter and one number.'];
    }
    if (strlen($password) > CUSTOMER_PASS_MAX) {
        return [$field => 'Your password can be at most 72 characters.'];
    }
    if (!hash_equals($password, $confirm)) {
        return [$field . '_confirm' => 'The two passwords do not match.'];
    }
    return [];
}

/** Store empty optional fields as NULL rather than ''. */
function null_if_empty(string $value): ?string
{
    return $value === '' ? null : $value;
}


/* SECTION D: SIGN UP, SIGN IN, PROFILE */

/**
 * Create an account with an email and password, then sign the person in.
 *
 * @param array $input full_name, email, password, password_confirm,
 *                     and optional phone, city, address
 * @return array{ok: bool, message: string, errors?: array<string,string>, email_taken?: bool}
 */
function customer_register(array $input): array
{
    $checked = customer_validate_details($input);
    $values  = $checked['values'];
    $errors  = $checked['errors'];

    $email = normalise_email((string) ($input['email'] ?? ''));
    if ($email === '' || mb_strlen($email) > CUSTOMER_EMAIL_MAX
        || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $errors += customer_password_errors(
        (string) ($input['password'] ?? ''),
        (string) ($input['password_confirm'] ?? '')
    );

    // email_taken lets register.php offer a "Go to sign in" link.
    $emailTaken = false;
    if (!isset($errors['email'])) {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            $errors['email'] = 'An account with this email already exists. Please sign in instead.';
            $emailTaken      = true;
        }
    }

    if ($errors !== []) {
        return [
            'ok'          => false,
            'message'     => 'Please fix the highlighted fields.',
            'errors'      => $errors,
            'email_taken' => $emailTaken,
        ];
    }

    try {
        $insert = db()->prepare(
            'INSERT INTO users (full_name, email, password_hash, phone, city, address)
             VALUES (:full_name, :email, :password_hash, :phone, :city, :address)'
        );
        $insert->execute([
            ':full_name'     => $values['full_name'],
            ':email'         => $email,
            ':password_hash' => password_hash((string) $input['password'], PASSWORD_DEFAULT),
            ':phone'         => null_if_empty($values['phone']),
            ':city'          => null_if_empty($values['city']),
            ':address'       => null_if_empty($values['address']),
        ]);
    } catch (PDOException $ex) {
        // 23000 is a duplicate key: someone signed up with the same email
        // between our check above and this insert.
        if ($ex->getCode() === '23000') {
            return [
                'ok'          => false,
                'message'     => 'Please fix the highlighted fields.',
                'errors'      => ['email' => 'An account with this email already exists. Please sign in instead.'],
                'email_taken' => true,
            ];
        }
        error_log('AutoSouq sign-up failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'We could not create your account. Please try again.', 'errors' => []];
    }

    // With email codes the customer signs in next and verifies the email.
    // Without them, sign the new customer in straight away.
    if (!customer_email_enabled()) {
        customer_start_session((int) db()->lastInsertId(), $values['full_name']);
        return ['ok' => true, 'signed_in' => true,
                'message' => 'Welcome to ' . SITE_NAME . ', ' . $values['full_name'] . '. Your account is ready.'];
    }

    return ['ok' => true, 'message' => 'Welcome to ' . SITE_NAME . ', ' . $values['full_name'] . '. Sign in to verify your email.'];
}

/**
 * Check an email and password against the users table.
 *
 * Unknown emails are counted and locked exactly like real ones, so the
 * lock does not show which emails have accounts either. Tries made while
 * locked are refused without checking the password, and their row is
 * removed again, so the lock always ends on time. After 5 failures the
 * 6th try is the first one refused.
 *
 * @return array{ok: bool, message: string, locked?: int}
 */
function customer_login(string $email, string $password, string $next): array
{
    unset($_SESSION['customer_email_challenge']);
    $email = normalise_email($email);
    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Please enter both your email and your password.'];
    }

    // Write this try down before anything else (see SECTION B2).
    $emailHash = customer_email_hash($email);
    $attempt   = login_attempt_start($emailHash, login_ip_hash('ip'));

    if ($attempt['key_count'] > CUSTOMER_MAX_ATTEMPTS
        || $attempt['ip_count'] > CUSTOMER_MAX_IP_ATTEMPTS) {
        login_attempt_forget($attempt['id']);
        // At least 1: in a burst the other refused rows may already be gone.
        $remaining = max(1, customer_lock_remaining($email));
        return [
            'ok'      => false,
            'message' => 'Too many failed attempts. Please wait '
                         . customer_wait_text($remaining) . ' and try again.',
            'locked'  => $remaining,
        ];
    }

    // Same message for an unknown email, a wrong password, and an account
    // that only ever used Google, so the form does not reveal which emails
    // have accounts. A failure needs no extra write: its row is already in.
    $badCredentials = ['ok' => false, 'message' => 'Incorrect email or password.'];

    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, password_hash FROM users WHERE email = :email'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user === false || $user['password_hash'] === null) {
        password_verify($password, CUSTOMER_DUMMY_HASH);   // timing only
        return $badCredentials;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return $badCredentials;
    }

    // Success removes this try's row too, along with the old failures.
    login_attempt_clear($emailHash);

    // If PHP's default algorithm or cost has changed since the hash was
    // made, store a new one while we have the plain password. (This also
    // changes the fingerprint, so other devices sign in again once.)
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehash = db()->prepare(
            'UPDATE users SET password_hash = :hash WHERE user_id = :id AND password_hash = :previous'
        );
        $rehash->execute([
            ':hash' => $newHash,
            ':id' => (int) $user['user_id'],
            ':previous' => $user['password_hash'],
        ]);
        if ($rehash->rowCount() !== 1) {
            return $badCredentials;
        }
        $user['password_hash'] = $newHash;
    }

    // Without Resend there is no way to send a code, so sign in directly
    // (the same as before email codes were added).
    if (!customer_email_enabled()) {
        customer_start_session((int) $user['user_id'], (string) $user['full_name']);
        return ['ok' => true, 'signed_in' => true, 'message' => 'Welcome back, ' . $user['full_name'] . '.'];
    }

    return customer_email_start($user, $next) + ['credentials_valid' => true];
}

/**
 * Save the name and contact details from the account page. The email is
 * not editable here, because it is what the person signs in with.
 *
 * @return array{ok: bool, message: string, errors: array<string,string>}
 */
function customer_update_profile(int $userId, array $input): array
{
    $checked = customer_validate_details($input);
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $checked['errors']];
    }
    $values = $checked['values'];

    db()->prepare(
        'UPDATE users
            SET full_name = :full_name, phone = :phone, city = :city, address = :address
          WHERE user_id = :id'
    )->execute([
        ':full_name' => $values['full_name'],
        ':phone'     => null_if_empty($values['phone']),
        ':city'      => null_if_empty($values['city']),
        ':address'   => null_if_empty($values['address']),
        ':id'        => $userId,
    ]);

    $_SESSION['user_name'] = $values['full_name'];
    current_user(true);

    return ['ok' => true, 'message' => 'Your details have been saved.', 'errors' => []];
}

/**
 * Change (or, for a Google-only account, set) the password.
 *
 * An account that already has a password must give the current one, so
 * someone who finds the laptop left signed in cannot lock the owner out.
 * An account made through Google has no password to give.
 *
 * @return array{ok: bool, message: string, errors: array<string,string>}
 */
function customer_change_password(int $userId, string $current, string $new, string $confirm): array
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false) {
        return ['ok' => false, 'message' => 'Your account could not be found.', 'errors' => []];
    }

    $errors = [];
    if ($hash !== null && !password_verify($current, (string) $hash)) {
        $errors['current_password'] = 'Your current password is not correct.';
    }
    $errors += customer_password_errors($new, $confirm, 'new_password');

    if ($errors !== []) {
        return ['ok' => false, 'message' => 'Your password was not changed.', 'errors' => $errors];
    }

    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')
        ->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $userId]);

    // A new session id after a credential change, like after a login.
    // The new fingerprint keeps this browser signed in; every other
    // session still has the old one and is signed out on its next page.
    session_regenerate_id(true);
    customer_store_fingerprint($userId);
    current_user(true);

    return [
        'ok'      => true,
        'message' => $hash === null
            ? 'Password set. You can now also sign in with your email and password.'
            : 'Your password has been changed.',
        'errors'  => [],
    ];
}

/**
 * Every order placed while signed in to this account, newest first, each
 * with its items. Same shape as get_orders_by_ids(), which does the work.
 */
function get_user_orders(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT order_id
           FROM orders
          WHERE user_id = :user_id
       ORDER BY order_date DESC, order_id DESC'
    );
    $stmt->execute([':user_id' => $userId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return $ids === [] ? [] : get_orders_by_ids($ids);
}


/* SECTION E: SIGN IN WITH GOOGLE (OAuth 2.0 authorisation code + PKCE)
 *
 * 1. google_login.php sends the visitor to Google with a random "state"
 *    (stops a forged callback), a "nonce" (ties the id token to this
 *    session) and a PKCE challenge (a stolen code is useless without the
 *    verifier, which never leaves our server).
 * 2. Google sends the visitor back to google_callback.php with a code.
 * 3. We swap the code for an id token, server to server, and read the
 *    person's id and email from it. */

const GOOGLE_AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const GOOGLE_ISSUERS        = ['accounts.google.com', 'https://accounts.google.com'];

/** True when the three Google settings are filled in (see secrets.php). */
function google_enabled(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '' && GOOGLE_REDIRECT_URI !== '';
}

/** base64url, the URL-safe base64 without padding that OAuth uses. */
function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** The reverse. Returns null for input that is not valid base64url. */
function base64url_decode(string $text): ?string
{
    $padded  = strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? null : $decoded;
}

/**
 * Build the Google sign-in link and remember the one-time values in the
 * session, where google_handle_callback() checks them.
 *
 * @param string $next the page to return to afterwards (checked by safe_next)
 */
function google_auth_url(string $next): string
{
    $state    = bin2hex(random_bytes(32));
    $nonce    = bin2hex(random_bytes(32));
    $verifier = bin2hex(random_bytes(32));   // 64 characters, inside PKCE's 43 to 128

    $_SESSION['google_oauth'] = [
        'state'    => $state,
        'nonce'    => $nonce,
        'verifier' => $verifier,
        'next'     => safe_next($next),
        'created'  => time(),
    ];

    $params = [
        'response_type'         => 'code',
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => GOOGLE_REDIRECT_URI,
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];

    return GOOGLE_AUTH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Finish a Google sign-in from the query string of google_callback.php.
 * Messages are written for the visitor; the technical reason only goes to
 * the PHP error log.
 *
 * @return array{ok: bool, message: string, next: string, cancelled?: bool}
 */
function google_handle_callback(array $query): array
{
    // The saved values are single-use: remove them before anything else,
    // so the same callback URL cannot be replayed.
    $saved = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);

    $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'next' => 'login.php'];
    $tryAgain = 'We could not sign you in with Google. Please try again.';

    // The person pressed Cancel on Google's screen. Not really an error,
    // so the page shows it as a note.
    if (isset($query['error'])) {
        return $fail('Google sign-in was cancelled. You can try again, or use your email and password.')
               + ['cancelled' => true];
    }

    if (!is_array($saved) || empty($saved['state']) || time() - (int) ($saved['created'] ?? 0) > GOOGLE_STATE_SECONDS) {
        return $fail('Your Google sign-in took too long or was already used. Please try again.');
    }

    $state = $query['state'] ?? '';
    if (!is_string($state) || !hash_equals((string) $saved['state'], $state)) {
        return $fail($tryAgain);
    }

    $code = $query['code'] ?? '';
    if (!is_string($code) || $code === '' || strlen($code) > 1024) {
        return $fail($tryAgain);
    }

    // Step 3: code -> tokens, sent as a form, server to server.
    $response = http_post(GOOGLE_TOKEN_ENDPOINT, ['Accept' => 'application/json'], [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
        'code_verifier' => (string) $saved['verifier'],
    ], 15, true);

    $idToken = $response['data']['id_token'] ?? null;
    if ($response['status'] !== 200 || !is_string($idToken)) {
        error_log('AutoSouq Google token exchange failed: '
                  . ($response['error'] !== '' ? $response['error'] : 'no id_token'));
        return $fail($tryAgain);
    }

    $claims = google_id_token_claims($idToken);
    $problem = $claims === null ? 'unreadable id_token' : google_claims_problem($claims, (string) $saved['nonce']);
    if ($problem !== '') {
        error_log('AutoSouq Google id_token rejected: ' . $problem);
        return $fail($tryAgain);
    }

    $email = normalise_email((string) $claims['email']);
    if (mb_strlen($email) > CUSTOMER_EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $fail('Your Google account email cannot be used here. Please sign up with email and password.');
    }

    $user = google_find_or_create_user((string) $claims['sub'], $email, (string) ($claims['name'] ?? ''));
    if ($user === null) {
        return $fail('This email is already linked to a different Google account. '
                     . 'Please sign in with your email and password.');
    }

    customer_start_session((int) $user['user_id'], (string) $user['full_name']);

    $message = 'Welcome, ' . $user['full_name'] . '. You are signed in with Google.';
    if (!empty($user['password_removed'])) {
        $message .= ' This email already had an account with a password. To keep the account safe, '
                  . 'that old password and the saved phone and address have been removed. '
                  . 'You can set a new password and your details in My account.';
    }

    return [
        'ok'      => true,
        'message' => $message,
        'next'    => safe_next((string) ($saved['next'] ?? '')),
    ];
}

/**
 * Read the claims (the middle part) of an id token.
 *
 * We do not check the token's signature here. Google's documentation
 * allows that when the token came straight from Google's token endpoint
 * over HTTPS, as it did here, because nobody else could have handed it to
 * us. The claims themselves are still checked by google_claims_problem().
 */
function google_id_token_claims(string $idToken): ?array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return null;
    }
    $json = base64url_decode($parts[1]);
    if ($json === null) {
        return null;
    }
    $claims = json_decode($json, true);
    return is_array($claims) ? $claims : null;
}

/**
 * Check that the id token was issued by Google, for our app, for this
 * sign-in attempt, and has not expired. Returns '' when it is fine, or a
 * short reason for the log.
 */
function google_claims_problem(array $claims, string $nonce): string
{
    if (!in_array($claims['iss'] ?? '', GOOGLE_ISSUERS, true)) {
        return 'wrong issuer';
    }
    if (($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
        return 'wrong audience';
    }
    if (isset($claims['azp']) && $claims['azp'] !== GOOGLE_CLIENT_ID) {
        return 'wrong authorised party';
    }
    if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
        return 'expired';
    }
    if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
        return 'nonce mismatch';
    }
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 64) {
        return 'missing subject';
    }
    // Only trust an email Google has confirmed belongs to the person,
    // otherwise anyone could take over an account by typing its email.
    if (($claims['email_verified'] ?? false) !== true || !is_string($claims['email'] ?? null)) {
        return 'email not verified';
    }
    return '';
}

/**
 * Find the account for a Google user, or make one.
 *   1. an account already linked to this Google id
 *   2. an account with the same (verified) email, which we link now
 *   3. otherwise a new account with no password
 * Returns null when the email belongs to an account linked to a different
 * Google id, which we refuse rather than guess.
 *
 * In case 2 any password on the account is removed, and the name and
 * contact details are reset. Sign-up never checks that the person owns
 * the email, so someone else could have registered it first and kept the
 * password. Google has just proved who the owner is.
 *
 * @return array{user_id: int, full_name: string, password_removed?: bool}|null
 */
function google_find_or_create_user(string $sub, string $email, string $name): ?array
{
    $pdo = db();

    $bySub = $pdo->prepare('SELECT user_id, full_name FROM users WHERE google_sub = :sub');
    $bySub->execute([':sub' => $sub]);
    $user = $bySub->fetch();
    if ($user !== false) {
        return $user;
    }

    $byEmail = $pdo->prepare(
        'SELECT user_id, full_name, google_sub, password_hash FROM users WHERE email = :email'
    );
    $byEmail->execute([':email' => $email]);
    $user = $byEmail->fetch();
    if ($user !== false) {
        if ($user['google_sub'] !== null) {
            return null;
        }
        // Link and drop the password in one statement. The changed row
        // also signs out any session that was using that password.
        // Whoever registered first also typed the name, phone, city and
        // address, so those are reset too: otherwise checkout would fill
        // in someone else's phone and address for the real owner. Orders
        // already placed on the account stay, because they are its order
        // history and the shop needs them.
        $link = $pdo->prepare(
            'UPDATE users
                SET google_sub = :sub, password_hash = NULL, full_name = :full_name,
                    phone = NULL, city = NULL, address = NULL
              WHERE user_id = :id AND google_sub IS NULL'
        );
        $fullName = google_display_name($name, $email);
        $link->execute([':sub' => $sub, ':full_name' => $fullName, ':id' => (int) $user['user_id']]);

        if ($link->rowCount() === 0) {
            // Another tab linked it a moment ago: fine if it was this Google id.
            $bySub->execute([':sub' => $sub]);
            $linked = $bySub->fetch();
            return $linked === false ? null : $linked;
        }
        return [
            'user_id'          => (int) $user['user_id'],
            'full_name'        => $fullName,
            'password_removed' => $user['password_hash'] !== null,
        ];
    }

    // New account.
    $fullName = google_display_name($name, $email);

    try {
        $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, google_sub)
             VALUES (:full_name, :email, NULL, :sub)'
        )->execute([':full_name' => $fullName, ':email' => $email, ':sub' => $sub]);
    } catch (PDOException $ex) {
        // A second tab finished the same sign-in a moment earlier.
        if ($ex->getCode() === '23000') {
            $bySub->execute([':sub' => $sub]);
            $user = $bySub->fetch();
            return $user === false ? null : $user;
        }
        throw $ex;
    }

    return ['user_id' => (int) $pdo->lastInsertId(), 'full_name' => $fullName];
}

/** The Google name, or the part of the email before @ when it is too short. */
function google_display_name(string $name, string $email): string
{
    $fullName = mb_substr(trim($name), 0, CUSTOMER_NAME_MAX);
    if (mb_strlen($fullName) < CUSTOMER_NAME_MIN) {
        $fullName = mb_substr(strstr($email, '@', true) ?: $email, 0, CUSTOMER_NAME_MAX);
    }
    return $fullName;
}


/* SECTION F: FORM HELPER */

/**
 * aria-invalid and aria-describedby for one input. The input points at its
 * hint (if any) and, when it has an error, at the error message, so a
 * screen reader reads both when the field gets focus.
 *
 * @param array<string,string> $errors
 */
function field_aria(array $errors, string $field, string $hintId = ''): string
{
    $ids = $hintId !== '' ? [$hintId] : [];
    $attr = '';
    if (isset($errors[$field])) {
        $ids[] = $field . '-error';
        $attr .= ' aria-invalid="true"';
    }
    if ($ids !== []) {
        $attr .= ' aria-describedby="' . e(implode(' ', $ids)) . '"';
    }
    return $attr;
}
