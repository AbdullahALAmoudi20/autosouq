<?php
/**
 * AutoSouq - Google sends the visitor back here after "Sign in with
 * Google".
 *
 * All the checks (state, code exchange, id token claims, finding or making
 * the account) are in google_handle_callback() in
 * includes/customer_auth.php. This page only turns the result into a
 * message and a redirect, so the code and state in the address bar are
 * gone from the browser straight away. Google's own error text and the
 * tokens are never shown.
 *
 * The address of this page must be listed exactly as an "Authorised
 * redirect URI" in the Google Cloud console, and in GOOGLE_REDIRECT_URI.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/google_callback.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';

if (!google_enabled()) {
    flash('info', 'Sign in with Google is not available at the moment. '
                  . 'Please use your email and password.');
    redirect('login.php');
}

$result = google_handle_callback($_GET);

if ($result['ok']) {
    flash('success', $result['message']);
} else {
    flash(empty($result['cancelled']) ? 'error' : 'info', $result['message']);
}
redirect($result['ok'] ? $result['next'] : 'login.php');
