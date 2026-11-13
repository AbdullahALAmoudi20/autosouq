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
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @coauthor Ahmed Muaidi (Front-End) - page layout and styling
 * @file    app/product_details.php
 * @task    Task 4, Task 5, Task 14
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
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </article>

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
