<?php
/**
 * AutoSouq - API keys and sign-in settings (example).
 *
 * Copy this file to secrets.php in the same folder and fill in the values.
 * secrets.php is listed in .gitignore, so the real keys never reach GitHub,
 * and the includes/ folder is closed to browsers on the live host.
 *
 * Every value may be left empty: the site still works, the assistant just
 * falls back to plain search and the Google button is hidden.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/includes/secrets.example.php
 */

declare(strict_types=1);

/* Database login. Leave these commented out on a stock XAMPP install
 * (root, no password, port 3306). On the live host fill in the values from
 * the control panel (InfinityFree: Accounts > MySQL Databases). */
// define('DB_HOST', 'sql208.infinityfree.com');
// define('DB_PORT', '3306');
// define('DB_NAME', 'if0_00000000_car_dealership');
// define('DB_USER', 'if0_00000000');
// define('DB_PASS', '');

/* Error messages on the page: keep true on XAMPP, set false on the live
 * host so a failed query never shows its details to visitors. */
// define('APP_DEBUG', false);

/* Secret salt for the hashed IP addresses used by the rate limits. Any
 * long random text, for example the output of bin2hex(random_bytes(32)). */
// define('RATE_LIMIT_SECRET', '');

/* AI assistant (RAG). OpenAI turns text into embeddings for the search,
 * Claude writes the answer from the search results. */
define('OPENAI_API_KEY',    '');   // sk-...
define('ANTHROPIC_API_KEY', '');   // sk-ant-...

/* Sign in with Google. Create an OAuth client of type "Web application" in
 * Google Cloud Console and list GOOGLE_REDIRECT_URI there exactly as it is
 * written here, for example:
 *   http://localhost/autosouq/app/google_callback.php        (XAMPP)
 *   https://autosouq.infinityfreeapp.com/google_callback.php  (live) */
define('GOOGLE_CLIENT_ID',     '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI',  '');
