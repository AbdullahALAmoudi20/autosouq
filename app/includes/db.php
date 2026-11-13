<?php
/**
 * AutoSouq database connection. The only place the project opens a PDO
 * connection; every page includes this file.
 *
 * PDO was chosen over mysqli because prepared statements are its normal
 * style and it throws on error instead of returning false.
 *
 * @author  Nasser Alshammari (Database Developer)
 * @file    app/includes/db.php
 * @task    Task 1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** @var PDO $pdo Shared by the whole request. */
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
         . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // use real server-side prepares
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
} catch (PDOException $ex) {
    http_response_code(500);
    if (APP_DEBUG) {
        die('<h1>Database connection failed</h1><pre>'
            . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8')
            . '</pre><p>Check DB_USER / DB_PASS in app/includes/config.php, and make sure '
            . 'you imported database/car_dealership.sql.</p>');
    }
    die('The showroom database is temporarily unavailable. Please try again later.');
}

/**
 * Returns the connection, so callers do not need "global $pdo".
 *
 * @return PDO
 */
function db(): PDO
{
    global $pdo;
    return $pdo;
}
