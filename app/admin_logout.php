<?php
/**
 * AutoSouq - manager sign out.
 *
 * Clears the admin session keys and returns to the login page. Only the
 * admin keys are removed, so a customer cart in the same browser is kept
 * (see admin_logout() in includes/auth.php).
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/admin_logout.php
 * @task    Task 8
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

admin_logout();
flash('info', 'You have been signed out.');
redirect('admin_login.php');
