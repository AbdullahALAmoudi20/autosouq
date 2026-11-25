<?php
/**
 * AutoSouq - the customer's account page.
 *
 * Three parts: "Your details" (name and contact details, used to fill in
 * checkout), "Password" (change it, or set one for an account made with
 * Google) and "Your orders" (every order placed while signed in, newest
 * first, with its status). Signing out is a small POST form to logout.php.
 *
 * A form that fails validation is drawn again with the typed values and
 * the errors next to the fields. A form that works redirects back here
 * (POST, redirect, GET) so a refresh cannot send it twice.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/account.php
 * @task    Extra: customer accounts and Google sign-in
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/customer_auth.php';
require_once __DIR__ . '/includes/cart.php';

require_customer('account.php');

require_once __DIR__ . '/includes/customer_showroom.php';
$user   = current_user();
$userId = (int) $user['user_id'];

/** Values for the details form: the saved row, or what was just typed. */
$details = [
    'full_name' => (string) $user['full_name'],
    'phone'     => (string) ($user['phone'] ?? ''),
    'city'      => (string) ($user['city'] ?? ''),
    'address'   => (string) ($user['address'] ?? ''),
];
$detailErrors    = [];
$detailMessage   = '';
$passwordErrors  = [];
$passwordMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = str_input('action');

    switch ($action) {

        case 'cancel_drive':
            update_test_drive(int_input('drive_id'), $action, $userId);
            redirect('account.php#test-drives');
        case 'remove_favorite':
            db()->prepare('DELETE FROM customer_favorites WHERE user_id=? AND car_id=?')->execute([$userId, int_input('car_id')]);
            redirect('account.php#favorites');
        case 'profile':
            foreach (array_keys($details) as $key) {
                $details[$key] = str_input($key);
            }
            $result = customer_update_profile($userId, $details);
            if ($result['ok']) {
                flash('success', $result['message']);
                redirect('account.php');
            }
            $detailErrors  = $result['errors'];
            $detailMessage = $result['message'];
            break;

        case 'password':
            // Passwords are used exactly as typed, so no trim here.
            $result = customer_change_password(
                $userId,
                raw_input('current_password'),
                raw_input('new_password'),
                raw_input('new_password_confirm')
            );
            if ($result['ok']) {
                flash('success', $result['message']);
                redirect('account.php');
            }
            $passwordErrors  = $result['errors'];
            $passwordMessage = $result['message'];
            break;
    }
}

$hasPassword = $user['password_hash'] !== null;
$editPassword = ($_GET['edit_password'] ?? '') === '1' || $passwordMessage !== '' || $passwordErrors !== [];
$hasGoogle   = $user['google_sub'] !== null;
$orders      = get_user_orders($userId);

$pageTitle       = 'My account';
$pageDescription = 'Your AutoSouq details, password and orders.';
$bodyClass       = 'page-account';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head account-head">
        <div>
            <h1>My account</h1>
            <p class="page-lead">
                Signed in as <strong><?= e($user['full_name']) ?></strong>
                (<?= e($user['email']) ?>).
                Member since
                <time datetime="<?= e(date('Y-m-d', strtotime((string) $user['created_at']))) ?>">
                    <?= e(date('j F Y', strtotime((string) $user['created_at']))) ?></time>.
            </p>
        </div>
        <form method="post" action="logout.php" class="account-signout">
            <?= csrf_field() ?>
            <button class="btn btn-quiet" type="submit">Sign out</button>
        </form>
    </header>

    <nav class="account-jump" aria-label="On this page">
        <ul>
            <li><a href="#details-heading">Your details</a></li>
            <li><a href="#password-heading">Password</a></li>
            <li><a href="#favorites">Favorite cars</a></li>
            <li><a href="#test-drives">Test drives</a></li>
            <li><a href="#orders-heading">Your orders (<?= count($orders) ?>)</a></li>
        </ul>
    </nav>

    <div class="account-grid">

        <!-- Name and contact details. The email is shown but cannot be
             changed here, because it is what the customer signs in with. -->
        <section class="panel account-panel" aria-labelledby="details-heading">
            <h2 id="details-heading">Your details</h2>
            <p class="panel-sub">We use these to fill in the checkout form for you.</p>

            <?php if ($detailMessage !== ''): ?>
                <div class="form-errors" role="alert">
                    <p class="form-errors-title"><?= e($detailMessage) ?></p>
                    <ul>
                        <?php foreach ($detailErrors as $field => $message): ?>
                            <li><a href="#<?= e($field) ?>"><?= e($message) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="account.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="field">
                    <label for="account_email">Email</label>
                    <input type="email" id="account_email" value="<?= e($user['email']) ?>"
                           readonly aria-describedby="account_email-hint">
                    <p class="field-hint" id="account_email-hint">
                        You sign in with this email, so it cannot be changed here.
                        <?php if ($hasGoogle): ?>
                            It is linked to your Google account.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="field">
                    <label for="full_name">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= e($details['full_name']) ?>"
                           autocomplete="name" maxlength="80"
                           <?= field_aria($detailErrors, 'full_name') ?> required>
                    <?php if (isset($detailErrors['full_name'])): ?>
                        <p class="field-error" id="full_name-error"><?= e($detailErrors['full_name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="phone">Phone <span class="optional">(optional)</span></label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= e($details['phone']) ?>"
                           autocomplete="tel" maxlength="25" placeholder="05XXXXXXXX"
                           <?= field_aria($detailErrors, 'phone', 'phone-hint') ?>>
                    <p class="field-hint" id="phone-hint">Digits, spaces, brackets and + are accepted.</p>
                    <?php if (isset($detailErrors['phone'])): ?>
                        <p class="field-error" id="phone-error"><?= e($detailErrors['phone']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="city">City <span class="optional">(optional)</span></label>
                    <input type="text" id="city" name="city"
                           value="<?= e($details['city']) ?>"
                           autocomplete="address-level2" maxlength="60"
                           <?= field_aria($detailErrors, 'city') ?>>
                    <?php if (isset($detailErrors['city'])): ?>
                        <p class="field-error" id="city-error"><?= e($detailErrors['city']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="address">Address <span class="optional">(optional)</span></label>
                    <textarea id="address" name="address" rows="2" maxlength="200"
                              autocomplete="street-address"
                              <?= field_aria($detailErrors, 'address') ?>><?= e($details['address']) ?></textarea>
                    <?php if (isset($detailErrors['address'])): ?>
                        <p class="field-error" id="address-error"><?= e($detailErrors['address']) ?></p>
                    <?php endif; ?>
                </div>

                <button class="btn btn-primary" type="submit">Save my details</button>
            </form>
        </section>

        <!-- Password. An account made with Google has no password yet, so
             it can set one without giving a current password. -->
        <section class="panel account-panel account-password" aria-labelledby="password-heading">
            <h2 id="password-heading">Password</h2>
            <?php if (!$editPassword): ?>
                <?php if ($hasPassword && customer_email_enabled()): ?>
                    <?php require __DIR__ . '/includes/forgot_password_dialog.php'; ?>
                <?php elseif ($hasPassword): /* no email, so change it here */ ?>
                    <a class="btn btn-quiet" href="account.php?edit_password=1#password-heading">Change password</a>
                <?php else: ?>
                    <a class="btn btn-quiet" href="account.php?edit_password=1#password-heading">Set a password</a>
                <?php endif; ?>
            <?php else: ?>

            <?php if ($passwordMessage !== ''): ?>
                <div class="form-errors" role="alert">
                    <p class="form-errors-title"><?= e($passwordMessage) ?></p>
                    <ul>
                        <?php foreach ($passwordErrors as $field => $message): ?>
                            <li><a href="#<?= e($field) ?>"><?= e($message) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="account.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <?php if ($hasPassword): ?>
                    <div class="field">
                        <label for="current_password">Current password <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="current_password" name="current_password"
                               autocomplete="current-password" maxlength="100"
                               <?= field_aria($passwordErrors, 'current_password') ?> required>
                        <?php if (isset($passwordErrors['current_password'])): ?>
                            <p class="field-error" id="current_password-error"><?= e($passwordErrors['current_password']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="new_password">New password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" minlength="8" maxlength="72"
                           <?= field_aria($passwordErrors, 'new_password', 'new_password-hint') ?> required>
                    <p class="field-hint" id="new_password-hint">
                        At least 8 characters, with at least one letter and one number.
                    </p>
                    <?php if (isset($passwordErrors['new_password'])): ?>
                        <p class="field-error" id="new_password-error"><?= e($passwordErrors['new_password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="new_password_confirm">Confirm new password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm"
                           autocomplete="new-password" maxlength="72"
                           <?= field_aria($passwordErrors, 'new_password_confirm') ?> required>
                    <?php if (isset($passwordErrors['new_password_confirm'])): ?>
                        <p class="field-error" id="new_password_confirm-error"><?= e($passwordErrors['new_password_confirm']) ?></p>
                    <?php endif; ?>
                </div>

                <button class="btn btn-primary" type="submit">
                    <?= $hasPassword ? 'Change my password' : 'Set a password' ?>
                </button>
                <a class="btn btn-quiet" href="account.php#password-heading">Cancel</a>
            </form>
            <?php endif; ?>
        </section>

    </div>

    <!-- Every order placed while signed in to this account. The badge
         shows the status as a word, not only as a colour. -->
    <?php
    $manageDrives = false;
    $drives = list_test_drives($userId);
    require __DIR__ . '/includes/test_drive_list.php';
    $favorites = db()->prepare('SELECT c.car_id, c.car_name, c.picture FROM customer_favorites f JOIN cars c ON c.car_id=f.car_id WHERE f.user_id=? ORDER BY c.car_name');
    $favorites->execute([$userId]);
    $savedCars = $favorites->fetchAll();
    ?>
    <section class="panel account-panel" id="favorites">
        <h2>Favorite cars</h2>
        <?php if (!$savedCars): ?>
            <p>Your saved cars will appear here. <a href="products.php">Browse the showroom</a></p>
        <?php else: ?>
        <ul class="saved-cars-grid">
        <?php foreach ($savedCars as $savedCar): ?>
            <li class="saved-car">
                <a class="saved-car-link" href="product_details.php?id=<?= (int) $savedCar['car_id'] ?>">
                    <img src="<?= e(car_image($savedCar['picture'])) ?>" alt="" width="240" height="150" loading="lazy">
                    <span><?= e($savedCar['car_name']) ?></span>
                </a>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="car_id" value="<?= (int) $savedCar['car_id'] ?>">
                    <button class="btn btn-quiet btn-sm" name="action" value="remove_favorite">Remove<span class="sr-only"> <?= e($savedCar['car_name']) ?> from favorites</span></button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <section class="account-orders" aria-labelledby="orders-heading">
        <h2 id="orders-heading">Your orders</h2>

        <?php if ($orders === []): ?>
            <div class="empty-state">
                <p>You have not placed an order with this account yet.</p>
                <p><a class="btn btn-primary" href="products.php">Browse the showroom</a></p>
            </div>
        <?php else: ?>
            <p class="section-sub account-orders-sub">
                <?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?>, newest first.
                Bring your ID to the showroom to collect a reserved car.
            </p>

            <ul class="order-list account-order-list">
                <?php foreach ($orders as $order): ?>
                    <li class="order-card">
                        <div class="order-card-head">
                            <h3>Order #<?= (int) $order['order_id'] ?></h3>
                            <span class="badge badge-<?= e(strtolower((string) $order['status'])) ?>">
                                <span class="sr-only">Status:</span>
                                <?= e($order['status']) ?>
                            </span>
                        </div>
                        <p class="order-meta">
                            Placed on
                            <time datetime="<?= e(date('Y-m-d\TH:i', strtotime((string) $order['order_date']))) ?>">
                                <?= e(date('j F Y, H:i', strtotime((string) $order['order_date']))) ?>
                            </time>
                        </p>

                        <ul class="order-items account-order-items">
                            <?php foreach ($order['items'] as $item): ?>
                                <li>
                                    <img src="<?= e(car_image($item['picture'])) ?>" alt=""
                                         width="64" height="40" loading="lazy">
                                    <span class="account-item-name">
                                        <a href="product_details.php?id=<?= (int) $item['car_id'] ?>">
                                            <?= e($item['car_name']) ?>
                                        </a>
                                        <small>
                                            <?= (int) $item['quantity'] ?> x
                                            <?= e(money_exact((float) $item['unit_price'])) ?>
                                        </small>
                                    </span>
                                    <span class="qty">
                                        <?= e(money_exact((float) $item['unit_price'] * (int) $item['quantity'])) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <p class="account-order-total">
                            <span>Total</span>
                            <strong><?= e(money_exact((float) $order['total_amount'])) ?></strong>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
