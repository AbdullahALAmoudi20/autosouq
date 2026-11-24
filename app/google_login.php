<?php
/**
 * AutoSouq - start "Sign in with Google".
 *
 * Builds the Google sign-in link (with a one-time state, a nonce and a
 * PKCE challenge kept in the session) and sends the visitor to Google.
 * Google then sends them back to google_callback.php. This page prints
 * nothing.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/google_login.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';

$next = safe_next(str_input('next'));

if (!google_enabled()) {
    flash('info', 'Sign in with Google is not available at the moment. '
                  . 'Please use your email and password.');
    redirect('login.php?next=' . rawurlencode($next));
}

if (is_customer()) {
    redirect($next);
}

redirect(google_auth_url($next));
