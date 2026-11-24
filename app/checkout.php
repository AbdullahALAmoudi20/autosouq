<?php
/**
 * AutoSouq - checkout page.
 *
 * Task 6: list the cart and allow a quantity change, a line delete, or
 * emptying the cart. Task 7: buy, which writes the order, reduces stock
 * and clears the cart.
 *
 * Each action is a POST followed by a redirect so refreshing the page
 * cannot repeat a purchase.
 *
 * Accounts: a signed-in customer gets the buyer form filled in from their
 * account, and the order is linked to the account. Guests can still buy.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/checkout.php
 * @task    Task 6, Task 7, Extra: customer accounts
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/customer_auth.php';

/** Buyer form values, refilled when validation sends the form back. */
$buyer = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'city'      => '',
    'address'   => '',
];

// Accounts: the signed-in customer, or null for a guest. $buyerTyped is
// set once the visitor has posted the form, so their typed values are
// never replaced by the saved ones.
$account    = current_user();
$buyerTyped = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // str_input() gives '' for action[]=x, where a (string) cast would
    // print an "Array to string" warning with the server path in it.
    $action = str_input('action');

    switch ($action) {

        /* Task 6: modify a line */
        case 'update':
            $result = cart_update(int_input('car_id'), int_input('quantity', 0, 0, 999));
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect('checkout.php');

        /* Task 6: delete a line */
        case 'remove':
            $result = cart_remove(int_input('car_id'));
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect('checkout.php');

        /* Task 6: empty the cart */
        case 'empty':
            $result = cart_clear();
            flash('info', $result['message']);
            redirect('checkout.php');

        /* Task 7: buy */
        case 'buy':
            $buyerTyped = true;
            $buyer = [
                'full_name' => str_input('full_name'),
                'email'     => str_input('email'),
                'phone'     => str_input('phone'),
                'city'      => str_input('city'),
                'address'   => str_input('address'),
            ];

            // js/validation.js checks the same rules in the browser, but
            // the form can be posted with JavaScript off, so check again
            // here on the server.
            $errors = [];
            if (mb_strlen($buyer['full_name']) < 3) {
                $errors[] = 'Please enter your full name.';
            }
            if (!filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if (!preg_match('/^[0-9+\s()-]{8,20}$/', $buyer['phone'])) {
                $errors[] = 'Please enter a valid phone number.';
            }
            if ($buyer['city'] === '') {
                $errors[] = 'Please tell us which city you are in.';
            }

            if ($errors !== []) {
                foreach ($errors as $message) {
                    flash('error', $message);
                }
                break;   // re-render the form with the typed values
            }

            // Accounts: link the order to the signed-in customer, if any.
            $result = cart_buy($buyer, $account === null ? null : (int) $account['user_id']);

            if ($result['ok']) {
                flash('success', $result['message']);
                flash('info', 'We have reserved your vehicles. Please bring your ID '
                              . 'to the showroom to complete the paperwork.');
                redirect('index.php');
            }

            flash('error', $result['message']);
            break;
    }
}

// Accounts: fill the buyer form from the account on a fresh visit.
if ($account !== null && !$buyerTyped) {
    $buyer = [
        'full_name' => (string) $account['full_name'],
        'email'     => (string) $account['email'],
        'phone'     => (string) ($account['phone'] ?? ''),
        'city'      => (string) ($account['city'] ?? ''),
        'address'   => (string) ($account['address'] ?? ''),
    ];
}

$lines   = cart_detailed();
$total   = cart_total();
$units   = cart_count();
$hasStockProblem = false;
foreach ($lines as $line) {
    if ($line['stock_problem']) {
        $hasStockProblem = true;
        break;
    }
}

$pageTitle       = 'Your cart';
$pageDescription = 'Review the vehicles you selected and complete your reservation.';
$bodyClass       = 'page-checkout';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head">
        <h1>Your cart</h1>
        <p class="page-lead">
            <?php if ($lines === []): ?>
                Your cart is empty at the moment.
            <?php else: ?>
                <?= $units ?> <?= $units === 1 ? 'vehicle' : 'vehicles' ?>
                across <?= count($lines) ?>
                <?= count($lines) === 1 ? 'model' : 'models' ?>.
                Nothing is confirmed until you press Buy.
            <?php endif; ?>
        </p>
    </header>

    <?php if ($lines === []): ?>

        <div class="empty-state empty-state-lg">
            <h2>Nothing selected yet</h2>
            <p>Pick a car from the showroom and it will appear here.</p>
            <p><a class="btn btn-primary btn-lg" href="products.php">Browse the showroom</a></p>
        </div>

    <?php else: ?>

        <?php if ($hasStockProblem): ?>
            <p class="notice notice-warn" role="alert">
                One of your lines asks for more units than we now hold. Please reduce the
                quantity before buying - the stock changed after you added it.
            </p>
        <?php endif; ?>

        <div class="checkout-layout">

            <!-- Task 6: cart contents, one row per model, each row with
                 its own update and remove controls. -->
            <section class="cart-panel" aria-labelledby="cart-heading">
                <h2 id="cart-heading" class="sr-only">Vehicles in your cart</h2>

                <table class="cart-table">
                    <caption class="sr-only">
                        Your selected vehicles, with quantity and line total
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Vehicle</th>
                            <th scope="col">Unit price</th>
                            <th scope="col">Quantity</th>
                            <th scope="col">Total</th>
                            <th scope="col"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $line): ?>
                        <tr class="<?= $line['stock_problem'] ? 'row-problem' : '' ?>">

                            <th scope="row" class="cart-cell-car">
                                <img src="<?= e(car_image($line['picture'])) ?>" alt=""
                                     width="96" height="60" loading="lazy">
                                <span>
                                    <a href="product_details.php?id=<?= (int) $line['car_id'] ?>">
                                        <?= e($line['car_name']) ?>
                                    </a>
                                    <small><?= e($line['brand_name']) ?>
                                        &middot; <?= e($line['category_name']) ?></small>
                                    <?php if ($line['stock_problem']): ?>
                                        <small class="text-danger">
                                            Only <?= (int) $line['stock'] ?> in stock
                                        </small>
                                    <?php endif; ?>
                                </span>
                            </th>

                            <td data-label="Unit price">
                                <?= e(money((float) $line['price'])) ?>
                            </td>

                            <!-- Task 6: change this line's quantity -->
                            <td data-label="Quantity">
                                <form class="inline-form" method="post" action="checkout.php"
                                      data-validate="cart-qty" novalidate>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="car_id"
                                           value="<?= (int) $line['car_id'] ?>">

                                    <label class="sr-only"
                                           for="qty-<?= (int) $line['car_id'] ?>">
                                        Quantity of <?= e($line['car_name']) ?>
                                    </label>
                                    <input class="qty-input" type="number"
                                           id="qty-<?= (int) $line['car_id'] ?>"
                                           name="quantity"
                                           value="<?= (int) $line['quantity'] ?>"
                                           min="1" max="<?= (int) $line['stock'] ?>" step="1"
                                           data-max="<?= (int) $line['stock'] ?>"
                                           inputmode="numeric">

                                    <button class="btn btn-quiet btn-sm" type="submit">
                                        Update
                                    </button>
                                </form>
                            </td>

                            <td data-label="Total" class="cart-cell-total">
                                <?= e(money((float) $line['line_total'])) ?>
                            </td>

                            <!-- Task 6: delete this line -->
                            <td data-label="Remove">
                                <form method="post" action="checkout.php"
                                      data-confirm="Remove the <?= e($line['car_name']) ?> from your cart?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="car_id"
                                           value="<?= (int) $line['car_id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">
                                        Remove
                                        <span class="sr-only">
                                            <?= e($line['car_name']) ?> from cart
                                        </span>
                                    </button>
                                </form>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th scope="row" colspan="3">Total to pay at the showroom</th>
                            <td class="cart-grand-total"><?= e(money_exact($total)) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="cart-panel-actions">
                    <a class="btn btn-quiet" href="products.php">Keep browsing</a>

                    <!-- Task 6: empty the whole cart -->
                    <form method="post" action="checkout.php"
                          data-confirm="Empty your whole cart? This cannot be undone.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="empty">
                        <button class="btn btn-danger" type="submit">Empty the cart</button>
                    </form>
                </div>
            </section>

            <!-- Task 7: buyer details. Posting this writes the whole cart
                 to the database in one transaction. -->
            <section class="buy-panel" aria-labelledby="buy-heading">
                <h2 id="buy-heading">Complete your reservation</h2>
                <p class="buy-intro">
                    We do not take payment online. Give us your details, we will hold
                    the <?= $units === 1 ? 'car' : 'cars' ?> for 48 hours, and you pay
                    at the showroom.
                </p>

                <!-- Accounts: tell a guest they can sign in first, and tell a
                     signed-in customer where the order will be saved. -->
                <?php if ($account === null): ?>
                    <p class="notice notice-info checkout-account-note">
                        Have an account?
                        <a href="login.php?next=checkout.php">Sign in to track this order</a>.
                        Your cart will still be here.
                    </p>
                <?php else: ?>
                    <p class="notice notice-info checkout-account-note">
                        Signed in as <strong><?= e($account['full_name']) ?></strong>.
                        This order will be saved to <a href="account.php">your account</a>.
                    </p>
                <?php endif; ?>

                <form method="post" action="checkout.php"
                      data-validate="buy" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="buy">

                    <div class="field">
                        <label for="full_name">Full name <span class="req">*</span></label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?= e($buyer['full_name']) ?>"
                               autocomplete="name" maxlength="80"
                               aria-describedby="full_name-error" required>
                        <p class="field-error" id="full_name-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" id="email" name="email"
                               value="<?= e($buyer['email']) ?>"
                               autocomplete="email" maxlength="120"
                               aria-describedby="email-error" required>
                        <p class="field-error" id="email-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="phone">Phone <span class="req">*</span></label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= e($buyer['phone']) ?>"
                               autocomplete="tel" maxlength="25"
                               placeholder="05XXXXXXXX"
                               aria-describedby="phone-hint phone-error" required>
                        <p class="field-hint" id="phone-hint">
                            Digits, spaces, brackets and + are accepted.
                        </p>
                        <p class="field-error" id="phone-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="city">City <span class="req">*</span></label>
                        <input type="text" id="city" name="city"
                               value="<?= e($buyer['city']) ?>"
                               autocomplete="address-level2" maxlength="60"
                               aria-describedby="city-error" required>
                        <p class="field-error" id="city-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="address">Address <span class="optional">(optional)</span></label>
                        <textarea id="address" name="address" rows="2"
                                  maxlength="200"><?= e($buyer['address']) ?></textarea>
                    </div>

                    <dl class="buy-summary">
                        <div><dt>Vehicles</dt><dd><?= $units ?></dd></div>
                        <div><dt>Models</dt><dd><?= count($lines) ?></dd></div>
                        <div class="buy-summary-total">
                            <dt>Total</dt><dd><?= e(money_exact($total)) ?></dd>
                        </div>
                    </dl>

                    <button class="btn btn-primary btn-lg btn-block" type="submit"
                            <?= $hasStockProblem ? 'disabled' : '' ?>>
                        Buy <?= $units ?> <?= $units === 1 ? 'vehicle' : 'vehicles' ?>
                    </button>

                    <?php if ($hasStockProblem): ?>
                        <p class="field-error" role="alert">
                            Fix the highlighted quantity before you can buy.
                        </p>
                    <?php endif; ?>

                    <p class="buy-smallprint">
                        Pressing Buy records your order, reduces our stock and empties
                        your cart. Your order number will be shown on the next page.
                    </p>
                </form>
            </section>

        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
