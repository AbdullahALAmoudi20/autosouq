<?php
/**
 * AutoSouq - shopping cart, held in the PHP session.
 *
 * $_SESSION['cart'] is a map of car_id => quantity. Only those two values
 * are stored; names, prices and pictures are re-read from the database by
 * cart_detailed(), so an admin price change shows straight away and a
 * customer cannot change a price by editing their own session.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/includes/cart.php
 * @task    Task 5 (add to cart), Task 6 (checkout), Task 7 (buy),
 *          Task 12 (past purchases cookie)
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Create the session cart key if it is missing. */
function cart_init(): void
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

/** The raw car_id => quantity map. */
function cart_raw(): array
{
    cart_init();
    return $_SESSION['cart'];
}

/** Total units in the cart (sum of the quantities). */
function cart_count(): int
{
    return array_sum(cart_raw());
}

/** Number of distinct models in the cart. */
function cart_lines(): int
{
    return count(cart_raw());
}

/** True when the cart is empty. */
function cart_is_empty(): bool
{
    return cart_raw() === [];
}

/**
 * The cart joined against the cars table. One row per line, with the car
 * details plus quantity, line_total and a stock_problem flag.
 */
function cart_detailed(): array
{
    $cart = cart_raw();
    if ($cart === []) {
        return [];
    }

    $ids          = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT c.car_id, c.car_name, c.picture, c.price, c.stock,
                c.model_year, b.brand_name, cat.category_name
           FROM cars c
           JOIN brands b       ON b.brand_id     = c.brand_id
           JOIN categories cat ON cat.category_id = c.category_id
          WHERE c.car_id IN ($placeholders)
       ORDER BY b.brand_name, c.car_name"
    );
    $stmt->execute($ids);

    $lines = [];
    foreach ($stmt->fetchAll() as $row) {
        $carId    = (int) $row['car_id'];
        $quantity = (int) $cart[$carId];

        $row['quantity']      = $quantity;
        $row['line_total']    = (float) $row['price'] * $quantity;
        $row['stock_problem'] = $quantity > (int) $row['stock'];

        $lines[] = $row;
    }

    return $lines;
}

/** Grand total of every line in the cart. */
function cart_total(): float
{
    $total = 0.0;
    foreach (cart_detailed() as $line) {
        $total += (float) $line['line_total'];
    }
    return $total;
}

/**
 * Task 5 - add a quantity of one car to the cart.
 *
 * The quantity is checked against the stock level in the database. If the
 * car is already in the cart the check uses the combined quantity,
 * otherwise repeated adds could get past the stock limit.
 *
 * @return array{ok: bool, message: string}
 */
function cart_add(int $carId, int $quantity): array
{
    cart_init();

    $car = get_car($carId);
    if ($car === null) {
        return ['ok' => false, 'message' => 'That vehicle is no longer in our catalogue.'];
    }

    if ($quantity < 1) {
        return ['ok' => false, 'message' => 'Please enter a quantity of at least 1.'];
    }
    if ($quantity > MAX_QTY_PER_ORDER) {
        return [
            'ok'      => false,
            'message' => 'For orders above ' . MAX_QTY_PER_ORDER
                         . ' units please contact our fleet sales desk.',
        ];
    }

    $stock      = (int) $car['stock'];
    $alreadyIn  = (int) ($_SESSION['cart'][$carId] ?? 0);
    $wantsTotal = $alreadyIn + $quantity;

    if ($stock === 0) {
        return [
            'ok'      => false,
            'message' => 'Sorry, the ' . $car['car_name'] . ' is currently out of stock.',
        ];
    }

    if ($wantsTotal > $stock) {
        $remaining = $stock - $alreadyIn;
        $message   = $alreadyIn > 0
            ? 'You already have ' . $alreadyIn . ' of this model in your cart. Only '
              . $stock . ' units exist, so you can add at most ' . max(0, $remaining) . ' more.'
            : 'Sorry, only ' . $stock . ' units of the ' . $car['car_name']
              . ' are available. Please reduce the quantity.';

        return ['ok' => false, 'message' => $message];
    }

    $_SESSION['cart'][$carId] = $wantsTotal;

    return [
        'ok'      => true,
        'message' => $quantity . ' x ' . $car['car_name'] . ' added to your cart.',
    ];
}

/**
 * Task 6 - change the quantity of one line. A quantity of 0 removes it.
 *
 * @return array{ok: bool, message: string}
 */
function cart_update(int $carId, int $quantity): array
{
    cart_init();

    if (!isset($_SESSION['cart'][$carId])) {
        return ['ok' => false, 'message' => 'That vehicle is not in your cart.'];
    }

    if ($quantity < 1) {
        return cart_remove($carId);
    }

    $stock = get_stock($carId);
    if ($quantity > $stock) {
        return [
            'ok'      => false,
            'message' => 'Only ' . $stock . ' units are in stock, so the quantity was not changed.',
        ];
    }

    $_SESSION['cart'][$carId] = $quantity;
    return ['ok' => true, 'message' => 'Quantity updated.'];
}

/**
 * Task 6 - delete a single line from the cart.
 *
 * @return array{ok: bool, message: string}
 */
function cart_remove(int $carId): array
{
    cart_init();

    if (!isset($_SESSION['cart'][$carId])) {
        return ['ok' => false, 'message' => 'That vehicle is not in your cart.'];
    }

    $car = get_car($carId);
    unset($_SESSION['cart'][$carId]);

    $name = $car === null ? 'The vehicle' : $car['car_name'];
    return ['ok' => true, 'message' => $name . ' was removed from your cart.'];
}

/**
 * Task 6 - empty the whole cart.
 *
 * @return array{ok: bool, message: string}
 */
function cart_clear(): array
{
    $_SESSION['cart'] = [];
    return ['ok' => true, 'message' => 'Your cart is now empty.'];
}

/**
 * Task 7 - buy everything in the cart.
 *
 * Wrapped in a transaction so the purchase either completes or leaves the
 * database unchanged. Steps:
 *   1. re-read the stock of each car with SELECT ... FOR UPDATE
 *   2. abort if a line no longer has enough stock
 *   3. insert the customer, the order and the order_items
 *   4. subtract the sold units from cars.stock
 *   5. commit, empty the cart, store the order id in a cookie (Task 12)
 *
 * @param array $customer full_name, email, phone, city, address
 * @return array{ok: bool, message: string, order_id: int|null}
 */
function cart_buy(array $customer): array
{
    cart_init();

    if (cart_is_empty()) {
        return ['ok' => false, 'message' => 'Your cart is empty.', 'order_id' => null];
    }

    $pdo  = db();
    $cart = cart_raw();

    try {
        $pdo->beginTransaction();

        // Steps 1 and 2: re-check the stock under a row lock.
        $lock  = $pdo->prepare(
            'SELECT car_id, car_name, price, stock FROM cars WHERE car_id = :id FOR UPDATE'
        );
        $lines = [];
        $total = 0.0;

        foreach ($cart as $carId => $quantity) {
            $lock->execute([':id' => (int) $carId]);
            $car = $lock->fetch();

            if ($car === false) {
                $pdo->rollBack();
                return [
                    'ok'       => false,
                    'message'  => 'One of the vehicles in your cart no longer exists. '
                                  . 'Please review your cart.',
                    'order_id' => null,
                ];
            }

            if ((int) $quantity > (int) $car['stock']) {
                $pdo->rollBack();
                return [
                    'ok'       => false,
                    'message'  => 'Only ' . (int) $car['stock'] . ' units of the '
                                  . $car['car_name'] . ' remain. Please adjust your cart.',
                    'order_id' => null,
                ];
            }

            $lineTotal = (float) $car['price'] * (int) $quantity;
            $total    += $lineTotal;

            $lines[] = [
                'car_id'     => (int) $car['car_id'],
                'quantity'   => (int) $quantity,
                'unit_price' => (float) $car['price'],
            ];
        }

        // Step 3: customer row.
        $insCustomer = $pdo->prepare(
            'INSERT INTO customers (full_name, email, phone, city, address)
             VALUES (:full_name, :email, :phone, :city, :address)'
        );
        $insCustomer->execute([
            ':full_name' => $customer['full_name'],
            ':email'     => $customer['email'],
            ':phone'     => $customer['phone'],
            ':city'      => $customer['city'],
            ':address'   => $customer['address'] ?? null,
        ]);
        $customerId = (int) $pdo->lastInsertId();

        // Step 3: order header.
        $insOrder = $pdo->prepare(
            'INSERT INTO orders (customer_id, total_amount, status)
             VALUES (:customer_id, :total, :status)'
        );
        $insOrder->execute([
            ':customer_id' => $customerId,
            ':total'       => $total,
            ':status'      => 'Placed',
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // Steps 3 and 4: order lines, then take the units off the stock.
        $insItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, car_id, quantity, unit_price)
             VALUES (:order_id, :car_id, :quantity, :unit_price)'
        );
        $decStock = $pdo->prepare(
            'UPDATE cars SET stock = stock - :quantity WHERE car_id = :car_id'
        );

        foreach ($lines as $line) {
            $insItem->execute([
                ':order_id'   => $orderId,
                ':car_id'     => $line['car_id'],
                ':quantity'   => $line['quantity'],
                ':unit_price' => $line['unit_price'],
            ]);
            $decStock->execute([
                ':quantity' => $line['quantity'],
                ':car_id'   => $line['car_id'],
            ]);
        }

        // Step 5: commit, then clear the cart and record the order id.
        $pdo->commit();

        cart_clear();
        remember_purchase($orderId);

        return [
            'ok'       => true,
            'message'  => 'Thank you. Your order number is ' . $orderId . '.',
            'order_id' => $orderId,
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (APP_DEBUG) {
            return [
                'ok'       => false,
                'message'  => 'Purchase failed: ' . $ex->getMessage(),
                'order_id' => null,
            ];
        }
        return [
            'ok'       => false,
            'message'  => 'We could not complete your purchase. Nothing was charged. '
                          . 'Please try again.',
            'order_id' => null,
        ];
    }
}

/*
 * Task 12 - past purchases, stored in a cookie rather than the session so
 * they survive the browser being closed. Only the order ids are written to
 * the visitor's machine; the order details are looked up from the database
 * when the home page renders.
 */

/** How many order ids the cookie keeps. */
const PAST_PURCHASE_KEEP = 5;

/** Add one order id to the past-purchase cookie. Called by cart_buy(). */
function remember_purchase(int $orderId): void
{
    $ids = get_remembered_order_ids();

    // Newest first, no duplicates, capped list.
    array_unshift($ids, $orderId);
    $ids = array_values(array_unique($ids));
    $ids = array_slice($ids, 0, PAST_PURCHASE_KEEP);

    setcookie(PAST_PURCHASE_COOKIE, implode(',', $ids), [
        'expires'  => time() + COOKIE_LIFETIME,
        'path'     => '/',
        'httponly' => false,   // order ids only, so JS may read them
        'samesite' => 'Lax',
    ]);

    // $_COOKIE is only refreshed on the next request, so set it here as
    // well or the rest of this request would still see the old list.
    $_COOKIE[PAST_PURCHASE_COOKIE] = implode(',', $ids);
}

/**
 * Read the order ids out of the cookie. The cookie value can be edited by
 * the user, so anything that is not a positive integer is thrown away.
 *
 * @return int[]
 */
function get_remembered_order_ids(): array
{
    $raw = $_COOKIE[PAST_PURCHASE_COOKIE] ?? '';
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $ids = [];
    foreach (explode(',', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece !== '' && ctype_digit($piece) && (int) $piece > 0) {
            $ids[] = (int) $piece;
        }
    }

    return array_slice(array_unique($ids), 0, PAST_PURCHASE_KEEP);
}

/** Orders for the panel at the top of index.php. [] for a new visitor. */
function get_past_purchases(): array
{
    $ids = get_remembered_order_ids();
    return $ids === [] ? [] : get_orders_by_ids($ids);
}

/** Expire the cookie so the visitor can clear their own history. */
function forget_purchases(): void
{
    setcookie(PAST_PURCHASE_COOKIE, '', [
        'expires' => time() - 3600,
        'path'    => '/',
    ]);
    unset($_COOKIE[PAST_PURCHASE_COOKIE]);
}
