<?php
/**
 * Shared page head, masthead and navigation.
 *
 * Included by every page, so all nine pages share one layout (Task 2) and
 * the cart summary appears on all of them (Task 5). A page sets $pageTitle
 * and optionally $pageDescription and $bodyClass before including this file.
 *
 * Task 15 accessibility: lang attribute, skip link, header/nav/main/footer
 * landmarks, aria-current on the active link, aria-live cart summary and
 * role="alert"/"status" on flash messages.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/includes/header.php
 * @task    Task 2, Task 15
 */

declare(strict_types=1);

require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/customer_auth.php';

$pageTitle       = $pageTitle       ?? SITE_NAME;
$pageDescription = $pageDescription ?? 'Toyota, Ford, Chevrolet, Nissan, Hyundai and BMW in one showroom.';
$bodyClass       = $bodyClass       ?? '';

$cartUnits  = cart_count();
$cartAmount = cart_total();
$flashes    = take_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($pageDescription) ?>">
    <title><?= e($pageTitle) ?> | <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="icon" href="<?= e(asset('images/brands/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= e($bodyClass) ?>">

<!-- Task 15: skip link, the first focusable element on the page -->
<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="site-header">
    <div class="container header-inner">

        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">AS</span>
            <span class="brand-text">
                <strong><?= e(SITE_NAME) ?></strong>
                <small><?= e(SITE_TAGLINE) ?></small>
            </span>
        </a>

        <button class="nav-toggle" type="button"
                aria-expanded="false" aria-controls="primary-nav">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="sr-only">Show navigation menu</span>
        </button>

        <nav id="primary-nav" class="primary-nav" aria-label="Main navigation">
            <ul>
                <li><a href="index.php"    <?= is_page('index.php')    ? 'aria-current="page"' : '' ?>>Home</a></li>
                <li><a href="products.php" <?= is_page('products.php') ? 'aria-current="page"' : '' ?>>Showroom</a></li>
                <li><a href="compare.php"  <?= is_page('compare.php')  ? 'aria-current="page"' : '' ?>>Compare</a></li>
                <li><a href="contact.php"  <?= is_page('contact.php')  ? 'aria-current="page"' : '' ?>>Contact us</a></li>
                <?php if (is_customer()): ?>
                    <li><a href="account.php" <?= is_page('account.php') ? 'aria-current="page"' : '' ?>>My account</a></li>
                <?php else: ?>
                    <li><a href="login.php" <?= is_page('login.php') || is_page('register.php') ? 'aria-current="page"' : '' ?>>Sign in</a></li>
                <?php endif; ?>
                <?php if (is_admin()): ?>
                    <li><a href="admin_dashboard.php" <?= is_page('admin_dashboard.php') ? 'aria-current="page"' : '' ?>>Dashboard</a></li>
                    <li><a href="admin_manage.php"    <?= is_page('admin_manage.php')    ? 'aria-current="page"' : '' ?>>Manage cars</a></li>
                    <li><a href="admin_knowledge.php" <?= is_page('admin_knowledge.php') ? 'aria-current="page"' : '' ?>>Knowledge base</a></li>
                <?php else: ?>
                    <li><a href="admin_login.php" <?= is_page('admin_login.php') ? 'aria-current="page"' : '' ?>>Manager</a></li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Task 5 / Task 6: cart summary on every page, links to checkout.
             aria-live means a screen reader announces the updated total. -->
        <a class="cart-chip" href="checkout.php" aria-live="polite">
            <svg class="cart-icon" viewBox="0 0 24 24" role="img"
                 aria-label="Shopping cart" focusable="false">
                <path d="M2 3h3l2.4 12.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H6"
                      fill="none" stroke="currentColor" stroke-width="1.8"
                      stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="10" cy="20" r="1.6" fill="currentColor"/>
                <circle cx="18" cy="20" r="1.6" fill="currentColor"/>
            </svg>
            <span class="cart-figures">
                <span class="cart-count"><?= (int) $cartUnits ?>
                    <?= $cartUnits === 1 ? 'vehicle' : 'vehicles' ?></span>
                <span class="cart-total"><?= e(money($cartAmount)) ?></span>
            </span>
        </a>

        <?php if (is_admin()): ?>
            <div class="admin-chip">
                <span class="sr-only">Signed in as</span>
                <span class="admin-chip-name"><?= e(admin_name()) ?></span>
                <a class="admin-chip-out" href="admin_logout.php">Log out</a>
            </div>
        <?php endif; ?>

    </div>
</header>

<?php if ($flashes !== []): ?>
    <div class="container flash-stack">
        <?php foreach ($flashes as $flashMessage): ?>
            <?php
            // Errors get role="alert" so they are announced straight away;
            // everything else gets role="status".
            $isError = $flashMessage['type'] === 'error';
            ?>
            <p class="flash flash-<?= e($flashMessage['type']) ?>"
               role="<?= $isError ? 'alert' : 'status' ?>">
                <?= e($flashMessage['message']) ?>
            </p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main id="main-content" class="site-main">
