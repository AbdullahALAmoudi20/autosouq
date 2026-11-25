<?php
/**
 * AutoSouq - product detail page.
 *
 * Task 4  show the selected car with a quantity box
 * Task 5  add the chosen quantity to the cart after checking stock
 * Task 14 pop-up help window, opened from the button next to the quantity
 *         box (JavaScript in js/help.js)
 *
 * Photos: the main picture uses the framing set by the manager, extra
 * gallery photos appear as thumbnails, and any photo opens full screen in
 * a <dialog> viewer (js/gallery.js). Without JavaScript the thumbnails and
 * the main photo are plain links to the image files.
 *
 * The add-to-cart POST answers with a redirect back to this page
 * (post-redirect-get), so refreshing the browser cannot add the same car
 * twice.
 *
 * Extras: a Compare button (js/compare.js) and a monthly payment estimate
 * (js/finance.js). The estimate is worked out here as well with the
 * default inputs, so the figures are still there without JavaScript.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @coauthor Ahmed Muaidi (Front-End) - page layout and styling
 * @file    app/product_details.php
 * @task    Task 4, Task 5, Task 14, Extra: car comparison, Extra: finance calculator
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/recalls.php';

$carId = int_input('id');
$car   = $carId > 0 ? get_car($carId) : null;

if ($car === null) {
    http_response_code(404);
    flash('error', 'That vehicle could not be found in our showroom.');
    redirect('products.php');
}

/* Task 5 - handle the add-to-cart submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();

    $quantity = int_input('quantity', 1, 0, 999);
    $result   = cart_add($carId, $quantity);

    flash($result['ok'] ? 'success' : 'error', $result['message']);

    // Straight to the checkout if the customer asked to buy now.
    if ($result['ok'] && ($_POST['then'] ?? '') === 'checkout') {
        redirect('checkout.php');
    }
    redirect('product_details.php?id=' . $carId);
}

require_once __DIR__ . '/includes/customer_auth.php';

$reviewPageUrl = 'product_details.php?id=' . $carId;
require_once __DIR__ . '/includes/customer_showroom.php';
$reviewer = current_user();
$driveFeedback = $_SESSION['drive_feedback'][$carId] ?? null;
unset($_SESSION['drive_feedback'][$carId]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(str_input('action'), ['save_favorite','remove_favorite','book_drive'], true)) {
    require_customer($reviewPageUrl);
    csrf_check();
    $action = str_input('action');
    if ($action === 'book_drive') {
        $error = request_test_drive((int) $reviewer['user_id'], $carId, str_input('day'), str_input('hour'));
        if ($error !== null) {
            $_SESSION['drive_feedback'][$carId] = ['error' => $error, 'day' => str_input('day'), 'hour' => str_input('hour')];
            redirect($reviewPageUrl . '#book-drive');
        }
        flash('success', 'Test drive requested. Check My account for confirmation.');
        redirect('account.php#test-drives');
    }
    $sql = $action === 'save_favorite'
        ? 'INSERT INTO customer_favorites (user_id, car_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE car_id=VALUES(car_id)'
        : 'DELETE FROM customer_favorites WHERE user_id=? AND car_id=?';
    db()->prepare($sql)->execute([$reviewer['user_id'], $carId]);
    flash('success', $action === 'save_favorite' ? 'Car saved to My account.' : 'Car removed from favorites.');
    redirect($reviewPageUrl);
}
$reviewError = '';
$rating = 0;
$comment = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    require_customer($reviewPageUrl);
    csrf_check();
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT) ?: 0;
    $comment = is_string($_POST['comment'] ?? null) ? trim($_POST['comment']) : '';
    if ($rating < 1 || $rating > 5 || $comment === '' || mb_strlen($comment) > 2000) {
        $reviewError = 'Choose 1 to 5 stars and write a comment of up to 2,000 characters.';
    } else {
        $save = db()->prepare('INSERT INTO car_reviews (car_id, user_id, rating, comment)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating),
            comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP');
        $save->execute([$carId, $reviewer['user_id'], $rating, $comment]);
        redirect($reviewPageUrl . '#reviews');
    }
}
$ownReview = false;
if ($reviewer !== null) {
    $own = db()->prepare('SELECT rating, comment FROM car_reviews WHERE car_id = ? AND user_id = ?');
    $own->execute([$carId, $reviewer['user_id']]);
    $ownReview = $own->fetch();
    if ($ownReview && $reviewError === '') {
        $rating = (int) $ownReview['rating'];
        $comment = $ownReview['comment'];
    }
}
$summary = db()->prepare('SELECT COUNT(*) AS total, AVG(rating) AS average FROM car_reviews WHERE car_id = ?');
$summary->execute([$carId]);
$reviewStats = $summary->fetch();
$reviewCount = (int) $reviewStats['total'];
$reviewPages = max(1, (int) ceil($reviewCount / 10));
$reviewPage = min($reviewPages, max(1, (int) filter_input(INPUT_GET, 'reviews_page', FILTER_VALIDATE_INT)));
$offset = ($reviewPage - 1) * 10;
$list = db()->prepare('SELECT r.rating, r.comment, r.updated_at, u.full_name FROM car_reviews r
    JOIN users u ON u.user_id = r.user_id WHERE r.car_id = ?
    ORDER BY r.updated_at DESC, r.user_id DESC LIMIT 10 OFFSET ' . $offset);
$list->execute([$carId]);
$reviews = $list->fetchAll();

$colors       = get_car_colors($carId);
$relatedCars  = get_related_cars($carId, (int) $car['brand_id']);

// Safety recalls for this model from the NHTSA API (null = API not reachable).
$recallModel  = nhtsa_model((string) $car['car_name'], (string) $car['brand_name']);
$recalls      = get_recalls((string) $car['brand_name'], $recallModel, (int) $car['model_year']);
$photoCredit  = photo_credit((string) $car['picture']);

// Main picture first, then the gallery. Gallery photos are shown whole
// (contain) because the framing controls only apply to the main picture.
$photos = [[
    'src'   => car_image((string) $car['picture']),
    'style' => car_image_style($car),
    'alt'   => $car['car_name'] . ', ' . $car['category_name'],
    'credit' => $photoCredit,
]];
foreach (get_car_images($carId) as $image) {
    $photos[] = [
        'src'   => car_image((string) $image['file_name']),
        'style' => 'object-fit:contain',
        'alt'   => '',
        // Bundled Commons photos keep their credit wherever they are shown.
        'credit' => photo_credit((string) $image['file_name']),
    ];
}
$photoCount = count($photos);
foreach ($photos as $i => $photo) {
    if ($photo['alt'] === '') {
        $photos[$i]['alt'] = $car['car_name'] . ', photo ' . ($i + 1) . ' of ' . $photoCount;
    }
}
$carName = (string) $car['car_name'];   // $car is reused by the related-cars loop below
$stock        = (int) $car['stock'];
$inCartAlready = (int) (cart_raw()[$carId] ?? 0);
$maxAddable   = max(0, min($stock - $inCartAlready, MAX_QTY_PER_ORDER));

// Finance estimate with the default inputs (20% down, 60 months, 4.5% a
// year). A flat-rate Murabaha profit is charged on the amount financed for
// every year of the term; js/finance.js uses the same formula.
$finPrice     = (float) $car['price'];
$finDownPct   = 20;
$finTerm      = 60;
$finRate      = 4.5;
$finDown      = $finPrice * $finDownPct / 100;
$finFinanced  = $finPrice - $finDown;
$finProfit    = $finFinanced * ($finRate / 100) * ($finTerm / 12);
$finMonthly   = ($finFinanced + $finProfit) / $finTerm;
$finTotal     = $finPrice + $finProfit;
// Same format as the script ("93,360 SAR"), so nothing jumps when it runs.
$sar = static fn (float $amount): string => number_format(round($amount), 0, '.', ',') . ' ' . CURRENCY;

$pageTitle       = (string) $car['car_name'];
$pageDescription = mb_substr(strip_tags((string) $car['description']), 0, 150);
$bodyClass       = 'page-detail';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <nav class="breadcrumb" aria-label="Breadcrumb">
        <ol>
            <li><a href="index.php">Home</a></li>
            <li><a href="products.php">Showroom</a></li>
            <li><a href="products.php?brand_id=<?= (int) $car['brand_id'] ?>">
                <?= e($car['brand_name']) ?></a></li>
            <li aria-current="page"><?= e($car['car_name']) ?></li>
        </ol>
    </nav>

    <article class="detail-layout">

        <div class="detail-media">
            <a class="detail-main" id="detail-main" href="<?= e($photos[0]['src']) ?>"
               data-photo-index="0" target="_blank" rel="noopener"
               aria-label="Enlarge photo 1 of <?= $photoCount ?>">
                <img class="framed-img" id="detail-main-img"
                     src="<?= e($photos[0]['src']) ?>"
                     alt="<?= e($photos[0]['alt']) ?>"
                     style="<?= e($photos[0]['style']) ?>"
                     width="720" height="450">
                <span class="enlarge-hint" aria-hidden="true">Enlarge</span>
            </a>

            <?php if ($photoCount > 1): ?>
                <ul class="detail-thumbs" aria-label="All photos of the <?= e($carName) ?>">
                    <?php foreach ($photos as $i => $photo): ?>
                        <li>
                            <a class="detail-thumb<?= $i === 0 ? ' is-active' : '' ?>"
                               href="<?= e($photo['src']) ?>"
                               data-photo-index="<?= $i ?>"
                               data-photo-style="<?= e($photo['style']) ?>"
                               data-photo-alt="<?= e($photo['alt']) ?>"
                               <?= $i === 0 ? 'aria-current="true"' : '' ?>
                               target="_blank" rel="noopener">
                                <img src="<?= e($photo['src']) ?>" alt="Show photo <?= $i + 1 ?> of <?= $photoCount ?>"
                                     width="112" height="70" loading="lazy">
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Always present so js/gallery.js can show the credit of
                 whichever photo is on screen; hidden when it has none. -->
            <p class="photo-credit" id="photo-credit"<?= $photoCredit === null ? ' hidden' : '' ?>>
                <?php if ($photoCredit !== null): ?>
                    Photo: <?= e($photoCredit['author']) ?>,
                    <a href="<?= e($photoCredit['source']) ?>" target="_blank" rel="noopener noreferrer"><?= e($photoCredit['license']) ?></a>,
                    via Wikimedia Commons
                <?php endif; ?>
            </p>

            <?php if ($colors !== []): ?>
                <section class="colour-strip" aria-labelledby="colours-heading">
                    <h2 id="colours-heading" class="colour-heading">Available colours</h2>
                    <ul>
                        <?php foreach ($colors as $colour): ?>
                            <li>
                                <span class="swatch"
                                      style="background: <?= e($colour['hex_code']) ?>"
                                      aria-hidden="true"></span>
                                <span class="swatch-name"><?= e($colour['color_name']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>

        <div class="detail-info">

            <p class="detail-brand">
                <img src="<?= e(brand_logo($car['brand_logo'])) ?>" alt=""
                     width="28" height="28">
                <?= e($car['brand_name']) ?>
                <span class="dot" aria-hidden="true">&middot;</span>
                <?= e($car['category_name']) ?>
                <span class="dot" aria-hidden="true">&middot;</span>
                <?= (int) $car['model_year'] ?>
            </p>

            <h1><?= e($car['car_name']) ?></h1>

            <p class="detail-price"><?= e(money((float) $car['price'])) ?></p>

            <p class="detail-stock <?= $stock === 0 ? 'is-zero' : ($stock <= LOW_STOCK_THRESHOLD ? 'is-low' : '') ?>">
                <?php if ($stock === 0): ?>
                    Out of stock - ask us about the next shipment
                <?php elseif ($stock <= LOW_STOCK_THRESHOLD): ?>
                    Only <?= $stock ?> <?= $stock === 1 ? 'unit' : 'units' ?> left on our floor
                <?php else: ?>
                    <?= $stock ?> units in stock
                <?php endif; ?>
            </p>

            <?php if ($car['description'] !== null && $car['description'] !== ''): ?>
                <p class="detail-description"><?= e($car['description']) ?></p>
            <?php endif; ?>

            <div class="detail-rating">
                <strong>Ratings</strong>
                <a href="#reviews">
                    <?= rating_stars($reviewCount ? (float) $reviewStats['average'] : null) ?>
                    <?php if ($reviewCount > 0): ?>
                        (<?= $reviewCount ?> <?= $reviewCount === 1 ? 'review' : 'reviews' ?>)
                    <?php else: ?>
                        No reviews yet
                    <?php endif; ?>
                </a>
                <?php require __DIR__ . '/includes/favorite_button.php'; ?>
            </div>

            <!-- Specification, read from the cars table -->
            <h2 class="spec-heading">Specification</h2>
            <dl class="spec-list">
                <div><dt>Engine</dt><dd><?= e($car['engine']) ?></dd></div>
                <div><dt>Power</dt><dd><?= (int) $car['horsepower'] ?> hp</dd></div>
                <div><dt>Transmission</dt><dd><?= e($car['transmission']) ?></dd></div>
                <div><dt>Fuel</dt><dd><?= e($car['fuel_type']) ?></dd></div>
                <div><dt>Seats</dt><dd><?= (int) $car['seats'] ?></dd></div>
                <div><dt>Warranty</dt><dd><?= (int) $car['warranty_yrs'] ?> years</dd></div>
                <div><dt>Model year</dt><dd><?= (int) $car['model_year'] ?></dd></div>
                <div><dt>Built in</dt><dd><?= e($car['brand_country']) ?></dd></div>
            </dl>

            <!-- Task 5 - quantity box and add to cart. js/validation.js
                 reads data-max to reject an impossible quantity before the
                 form is sent; cart_add() checks the same limit server side. -->
            <?php if ($stock === 0): ?>
                <p class="notice notice-warn" role="status">
                    This model is sold out. Please
                    <a href="contact.php">contact the showroom</a>
                    and we will tell you when the next one lands.
                </p>
                <a class="btn btn-secondary btn-lg" href="#book-drive" data-dialog="book-drive">Book a test drive</a>
            <?php else: ?>
                <form class="buy-form" method="post"
                      action="product_details.php?id=<?= (int) $car['car_id'] ?>"
                      data-validate="add-to-cart" novalidate>

                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">

                    <?php if ($inCartAlready > 0): ?>
                        <p class="notice notice-info" role="status">
                            You already have <?= $inCartAlready ?> of these in your cart.
                        </p>
                    <?php endif; ?>

                    <div class="qty-row">
                        <div class="field">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity"
                                   value="1" min="1" max="<?= $maxAddable ?>"
                                   step="1" inputmode="numeric"
                                   data-max="<?= $maxAddable ?>"
                                   data-stock="<?= $stock ?>"
                                   aria-describedby="quantity-hint quantity-error"
                                   required>
                            <p class="field-hint" id="quantity-hint">
                                You may add up to <?= $maxAddable ?>
                                <?= $maxAddable === 1 ? 'unit' : 'units' ?>.
                            </p>
                            <p class="field-error" id="quantity-error" role="alert"></p>
                        </div>

                        <!-- Task 14 - opens the pop-up help window -->
                        <button type="button" class="btn btn-help" id="help-button"
                                aria-haspopup="dialog">
                            <span aria-hidden="true">?</span>
                            <span class="btn-help-text">How does buying work</span>
                        </button>
                    </div>

                    <div class="buy-actions">
                        <button class="btn btn-primary btn-lg" type="submit"
                                name="then" value="stay">Add to cart</button>
                        <button class="btn btn-secondary btn-lg" type="submit"
                                name="then" value="checkout">Add and check out</button>
                        <a class="btn btn-secondary btn-lg" href="#book-drive" data-dialog="book-drive">Book a test drive</a>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Compare toggle, shown by js/compare.js (it needs JavaScript) -->
            <div class="detail-compare">
                <button type="button" class="compare-toggle btn btn-sm btn-quiet"
                        data-compare-id="<?= (int) $car['car_id'] ?>"
                        data-compare-name="<?= e($carName) ?>"
                        aria-pressed="false" hidden>
                    <span class="compare-toggle-label">Compare</span><span class="sr-only"> <?= e($carName) ?></span>
                </button>
            </div>

        </div>
    </article>

    <dialog open class="action-dialog booking-dialog" id="book-drive" aria-labelledby="drive-title">
        <h2 id="drive-title" tabindex="-1">Book a test drive</h2>
        <p><strong><?= e($carName) ?></strong> &middot; One hour at the showroom</p>
        <p><?= e(SHOP_ADDRESS) ?></p>
        <p>Choose a start time in Riyadh time. Track confirmation or cancel in <strong>My account → Test drives</strong>.</p>
        <?php if ($driveFeedback): ?><p role="alert" tabindex="-1"><?= e($driveFeedback['error']) ?></p><?php endif; ?>
        <?php if (!$reviewer): ?>
            <a href="login.php?next=<?= rawurlencode($reviewPageUrl) ?>">Sign in to request a test drive</a>
        <?php else: ?>
            <p>Start times are 09:00–20:00, or 16:00–20:00 on Fridays.</p>
            <form method="post" action="<?= e($reviewPageUrl) ?>" class="drive-form">
                <?= csrf_field() ?>
                <div class="field"><label for="drive-day">Date</label>
                    <input type="date" id="drive-day" name="day" min="<?= date('Y-m-d') ?>" value="<?= e($driveFeedback['day'] ?? '') ?>" required></div>
                <div class="field"><label for="drive-hour">Start time</label>
                    <select id="drive-hour" name="hour" required><option value="">Select a time</option>
                        <?php for ($h = 9; $h <= 20; $h++): $slot = sprintf('%02d:00', $h); ?>
                            <option <?= ($driveFeedback['hour'] ?? '') === $slot ? 'selected' : '' ?>><?= $slot ?></option>
                        <?php endfor; ?>
                    </select></div>
                <button class="btn btn-primary" name="action" value="book_drive">Request test drive</button>
            </form>
        <?php endif; ?>
        <button class="btn btn-quiet dialog-close" type="button" data-close>Close</button>
    </dialog>

    <?php if ($finPrice > 0): ?>
        <!-- Monthly payment estimate. js/finance.js reads the raw price from
             data-price and recalculates as the inputs change. Without
             JavaScript the outputs keep the example worked out in PHP. -->
        <section class="finance" id="finance" aria-labelledby="finance-heading"
                 data-price="<?= e((string) $finPrice) ?>">
            <div class="finance-head">
                <h2 id="finance-heading">Estimate your monthly payment</h2>
                <p class="finance-method">
                    Flat-rate (Murabaha) estimate: profit = amount financed &times; rate &times; years.
                    The bank confirms the final figure.
                </p>
            </div>

            <div class="finance-grid">
                <!-- No submit button: the figures update as you type. The
                     inputs have no names, so if a browser submits the form
                     anyway it just reloads this car. -->
                <form class="finance-form" id="finance-form" method="get"
                      action="product_details.php#finance" novalidate>
                    <input type="hidden" name="id" value="<?= (int) $car['car_id'] ?>">

                    <div class="field">
                        <label for="fin-down">Down payment (%)</label>
                        <div class="finance-pair">
                            <input type="number" id="fin-down" min="10" max="50" step="5"
                                   value="<?= $finDownPct ?>" inputmode="numeric"
                                   aria-describedby="fin-down-hint fin-down-error">
                            <label class="sr-only" for="fin-down-range">Down payment slider (%)</label>
                            <input type="range" id="fin-down-range" min="10" max="50" step="5"
                                   value="<?= $finDownPct ?>">
                        </div>
                        <p class="field-hint" id="fin-down-hint">
                            Between 10% and 50%. That is
                            <span id="fin-down-amount"><?= e($sar($finDown)) ?></span> up front.
                        </p>
                        <!-- Polite, not role="alert": finance.js only fills it
                             when the visitor leaves the box, and it should not
                             cut into what the screen reader is saying. -->
                        <p class="field-error" id="fin-down-error" aria-live="polite"></p>
                    </div>

                    <div class="field">
                        <label for="fin-term">Term</label>
                        <select id="fin-term">
                            <?php foreach ([12, 24, 36, 48, 60] as $months): ?>
                                <option value="<?= $months ?>" <?= $months === $finTerm ? 'selected' : '' ?>>
                                    <?= $months ?> months (<?= $months / 12 ?> <?= $months === 12 ? 'year' : 'years' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="fin-rate">Annual profit rate (%)</label>
                        <!-- type="text" with a decimal keypad, not type="number":
                             a number box throws away "5,5" (comma decimal, common
                             in Arabic and European keyboards) before the script
                             can read it. finance.js checks the value itself. -->
                        <input type="text" id="fin-rate" value="<?= e((string) $finRate) ?>"
                               inputmode="decimal" autocomplete="off" spellcheck="false"
                               aria-describedby="fin-rate-hint fin-rate-error">
                        <p class="field-hint" id="fin-rate-hint">Between 0% and 15%, for example 4.5 or 4,5.</p>
                        <p class="field-error" id="fin-rate-error" aria-live="polite"></p>
                    </div>
                </form>

                <div class="finance-results-panel">
                    <dl class="finance-results">
                        <div class="finance-result is-main">
                            <dt>Monthly payment</dt>
                            <dd><output id="fin-monthly" for="fin-down fin-term fin-rate"
                                        aria-live="polite"><?= e($sar($finMonthly)) ?></output></dd>
                        </div>
                        <div class="finance-result">
                            <dt>Amount financed</dt>
                            <dd><output id="fin-financed" for="fin-down"
                                        aria-live="polite"><?= e($sar($finFinanced)) ?></output></dd>
                        </div>
                        <div class="finance-result">
                            <dt>Total profit</dt>
                            <dd><output id="fin-profit" for="fin-down fin-term fin-rate"
                                        aria-live="polite"><?= e($sar($finProfit)) ?></output></dd>
                        </div>
                        <div class="finance-result">
                            <dt>Total you pay</dt>
                            <dd><output id="fin-total" for="fin-down fin-term fin-rate"
                                        aria-live="polite"><?= e($sar($finTotal)) ?></output></dd>
                        </div>
                    </dl>
                    <p class="finance-basis" id="fin-basis">
                        Based on <?= $finDownPct ?>% down over <?= $finTerm ?> months at
                        <?= e((string) $finRate) ?>% a year, for a price of <?= e($sar($finPrice)) ?>.
                    </p>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="recalls" aria-labelledby="recalls-heading">
        <h2 id="recalls-heading">Safety recalls</h2>
        <p class="recalls-sub">
            Live data from the NHTSA recall database for the
            <?= (int) $car['model_year'] ?> <?= e($car['brand_name']) ?> <?= e($recallModel) ?>.
            NHTSA covers cars sold in the United States.
        </p>

        <?php if ($recalls === null): ?>
            <p class="notice notice-info" role="status">
                The recall database could not be reached just now. Please try again later.
            </p>
        <?php elseif ($recalls === []): ?>
            <p class="recalls-none">No recalls on record for this model and year.</p>
        <?php else: ?>
            <p class="recalls-count">
                <?= count($recalls) ?> <?= count($recalls) === 1 ? 'recall' : 'recalls' ?> on record.
                Every one is repaired free of charge by the dealer.
            </p>
            <ul class="recall-list">
                <?php foreach (array_slice($recalls, 0, 3) as $recall): ?>
                    <li>
                        <p class="recall-head">
                            <strong><?= e($recall['component']) ?></strong>
                            <span><?= e($recall['campaign']) ?> &middot; <?= e($recall['date']) ?></span>
                        </p>
                        <p><?= e(mb_strimwidth($recall['summary'], 0, 260, '...')) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($recalls) > 3): ?>
                <p class="recalls-more">
                    <a href="https://www.nhtsa.gov/recalls" target="_blank" rel="noopener noreferrer">
                        See all <?= count($recalls) ?> recalls on nhtsa.gov
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php require __DIR__ . '/includes/car_reviews.php'; ?>

    <?php if ($relatedCars !== []): ?>
        <section class="section" aria-labelledby="related-heading">
            <div class="section-head">
                <div>
                    <h2 id="related-heading">More from <?= e($car['brand_name']) ?></h2>
                    <p class="section-sub">Other models we hold in stock right now.</p>
                </div>
            </div>
            <ul class="car-grid">
                <?php foreach ($relatedCars as $car): ?>
                    <?php require __DIR__ . '/includes/car_card.php'; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

</div>

<!-- Task 14 - pop-up help window. The markup lives here but stays hidden
     until js/help.js opens it. Using a real <dialog> means the browser
     handles the focus trap and the Escape key, so the pop-up stays usable
     from the keyboard (Task 15). -->
<dialog id="help-dialog" class="help-dialog" aria-labelledby="help-title">
    <div class="help-dialog-inner">

        <div class="help-dialog-head">
            <h2 id="help-title">How buying a car here works</h2>
            <button type="button" class="help-close" id="help-close"
                    aria-label="Close help window">&times;</button>
        </div>

        <ol class="help-steps">
            <li>
                <strong>Choose a quantity.</strong>
                Most customers buy one car. Businesses buying a fleet can enter up to
                <?= MAX_QTY_PER_ORDER ?> units, as long as we hold that many in stock.
            </li>
            <li>
                <strong>Add it to your cart.</strong>
                The cart badge at the top of every page updates immediately, so you
                always see how many vehicles you have selected and what they cost.
            </li>
            <li>
                <strong>Review the cart.</strong>
                On the checkout page you can change any quantity, remove one car or
                empty the whole cart. Nothing is final until you press Buy.
            </li>
            <li>
                <strong>Buy.</strong>
                We record the order, reduce our stock and give you an order number.
                No payment is taken on this website; you pay at the showroom.
            </li>
        </ol>

        <h3 class="help-sub">Good to know</h3>
        <ul class="help-notes">
            <li>If you ask for more units than we hold, the site tells you exactly
                how many are available instead of silently reducing your order.</li>
            <li>Your cart is kept for as long as your browser stays open.</li>
            <li>After you buy, your order appears on the home page the next time you
                visit from this device.</li>
            <li>Prices are in <?= e(CURRENCY) ?> and include the manufacturer warranty.</li>
        </ul>

        <p class="help-foot">
            Still unsure? Call us on <?= e(SHOP_PHONE) ?> or
            <a href="contact.php">visit the showroom</a>.
        </p>
    </div>
</dialog>

<!-- Full-screen photo viewer, opened by js/gallery.js from the main photo
     or a thumbnail. <dialog> handles Escape and keeps focus inside it; the
     arrow keys move between photos. -->
<dialog id="photo-viewer" class="photo-viewer" aria-label="Photos of the <?= e($carName) ?>"
        data-photos="<?= e((string) json_encode(array_map(
            static fn (array $photo): array => [
                'src'    => $photo['src'],
                'alt'    => $photo['alt'],
                'credit' => $photo['credit'] === null ? null : [
                    'author'  => (string) $photo['credit']['author'],
                    'license' => (string) $photo['credit']['license'],
                    'source'  => (string) $photo['credit']['source'],
                ],
            ],
            $photos
        ), JSON_UNESCAPED_SLASHES)) ?>">
    <div class="photo-viewer-inner">
        <figure class="photo-viewer-figure">
            <img id="photo-viewer-img" src="<?= e($photos[0]['src']) ?>" alt="<?= e($photos[0]['alt']) ?>">
            <figcaption>
                <span id="photo-viewer-count" aria-live="polite">1 / <?= $photoCount ?></span>
                <span class="photo-viewer-credit" id="photo-viewer-credit"></span>
            </figcaption>
        </figure>
        <?php if ($photoCount > 1): ?>
            <button type="button" class="photo-viewer-nav is-prev" id="photo-viewer-prev"
                    aria-label="Previous photo">&#8249;</button>
            <button type="button" class="photo-viewer-nav is-next" id="photo-viewer-next"
                    aria-label="Next photo">&#8250;</button>
        <?php endif; ?>
        <button type="button" class="photo-viewer-close" id="photo-viewer-close"
                aria-label="Close the photo viewer">&times;</button>
    </div>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
