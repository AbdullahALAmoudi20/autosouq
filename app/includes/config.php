<?php
/**
 * AutoSouq configuration: constants, session start and error mode.
 * Loaded first on every request.
 *
 * This file is the same on every machine and on the live host. Anything
 * that differs (database login, debug mode, API keys) goes in secrets.php,
 * which is not in git; see secrets.example.php.
 *
 * @author  Nasser Alshammari (Database Developer)
 * @file    app/includes/config.php
 * @task    Task 16
 */

declare(strict_types=1);

/* 0. Local settings first, so they win over the defaults below. */
if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}

/* 1. Database credentials.
 * Defaults suit a stock XAMPP install. Put different values (another port,
 * a root password, the live host's login) in secrets.php. */
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');   // not 'localhost', so Windows connects over TCP
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'car_dealership');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/* 2. Site identity. */
define('SITE_NAME',    'AutoSouq');
define('SITE_TAGLINE', 'Six brands. One showroom.');
define('CURRENCY',     'SAR');

/* Showroom address, also used by contact.php for the map. */
define('SHOP_ADDRESS', 'King Fahd Road, Al Olaya District, Riyadh 12333, Saudi Arabia');
define('SHOP_PHONE',   '+966 11 555 0199');
define('SHOP_EMAIL',   'sales@autosouq.test');
define('SHOP_LAT',     '24.6944');
define('SHOP_LNG',     '46.6853');

/* 3. Image paths. The database stores a file name only (Task 1), so these
 * prefixes turn it into a usable path. */
define('IMG_CARS',    'images/cars/');
define('IMG_BRANDS',  'images/brands/');
define('IMG_UPLOADS', 'images/uploads/');
define('UPLOAD_DIR',  __DIR__ . '/../images/uploads/');   // absolute, for move_uploaded_file()
define('PLACEHOLDER_IMAGE', 'placeholder.svg');

/* Upload limits used by admin_manage.php (Task 9). */
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);              // 2 MB
define('ALLOWED_UPLOAD_EXT', 'jpg,jpeg,png,webp');      // no SVG: it can carry JavaScript

/* 4. Business rules. */
define('LOW_STOCK_THRESHOLD', 3);        // dashboard warning level
define('MAX_QTY_PER_ORDER',  10);        // cap on the quantity box

/* 5. Cookie holding the past purchases (Task 12). */
define('PAST_PURCHASE_COOKIE', 'autosouq_past_purchases');
define('COOKIE_LIFETIME', 60 * 60 * 24 * 30);   // 30 days

/* 6. Environment. On by default for XAMPP; the live host sets APP_DEBUG to
 * false in its secrets.php so a failed query never prints to the page. */
defined('APP_DEBUG') || define('APP_DEBUG', true);

date_default_timezone_set('Asia/Riyadh');

/* 6b. API keys and Google sign-in. Any value secrets.php does not define is
 * empty, and the feature that needs it switches itself off. */
foreach (['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GOOGLE_CLIENT_ID',
          'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'] as $secretName) {
    defined($secretName) || define($secretName, '');
}
unset($secretName);

/* Salt for the hashed IP addresses in chat_rate and login_attempts. Without
 * a secret salt a stored hash could be reversed by trying every address. */
defined('RATE_LIMIT_SECRET') || define('RATE_LIMIT_SECRET',
    hash('sha256', 'autosouq|' . DB_PASS . '|' . ANTHROPIC_API_KEY . '|' . GOOGLE_CLIENT_SECRET . '|' . __DIR__));

/* AI assistant settings (RAG). */
define('AI_EMBED_MODEL',   'text-embedding-3-small');   // OpenAI embeddings
define('AI_EMBED_DIMS',    512);                        // shorter vectors, same quality for our size
define('AI_CHAT_MODEL',    'claude-haiku-5-5');         // Claude writes the answer
define('AI_TOP_K',         5);                          // sources passed to Claude per question
define('AI_RATE_PER_HOUR', 20);                         // questions per visitor per hour

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

/* 7. Security headers, sent on every page.
 * The Content-Security-Policy only lets the browser run scripts that come
 * from our own server, and only lets the Google map load in a frame. Even if
 * someone managed to get a <script> tag into a page, the browser would refuse
 * to run it. */
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
         . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
         . "frame-src https://www.google.com https://maps.google.com; "
         . "connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');                     // nobody can put our pages in a frame
    header('X-Content-Type-Options: nosniff');           // a .jpg is always treated as an image
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/* 8. Session. Holds the cart (Task 5) and the admin login (Task 8), so it
 * has to start before any output is sent. */
if (session_status() === PHP_SESSION_NONE) {
    // The live host ends HTTPS at its proxy and passes the original scheme
    // in X-Forwarded-Proto, so $_SERVER['HTTPS'] alone is not reliable there.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    ini_set('session.use_strict_mode', '1');    // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');   // never accept the id from the URL
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,      // on HTTPS the cookie is never sent over plain http
        'httponly' => true,          // keeps JavaScript away from the session id
        'samesite' => 'Lax',         // basic CSRF protection
    ]);
    unset($isHttps);
    session_name('AUTOSOUQ_SESSID');
    session_start();
}
