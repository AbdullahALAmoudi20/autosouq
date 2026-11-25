<?php
/**
 * AutoSouq - home page.
 *
 * Task 12 (past purchases read from a cookie) plus the featured cars for
 * Task 3. The "Welcome back" panel only appears if this browser has an
 * order id in the cookie.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @coauthor Ahmed Muaidi (Front-End) - hero and layout markup
 * @file    app/index.php
 * @task    Task 12
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';

// "Forget my history" link in the panel below.
if (isset($_GET['forget'])) {
    forget_purchases();
    flash('info', 'Your purchase history was removed from this browser.');
    redirect('index.php');
}

$pastPurchases = get_past_purchases();          // Task 12, reads the cookie
$featuredCars  = get_cars(['featured_only' => 1, 'in_stock' => 1, 'limit' => 8]);
$brands        = get_brands();
$categories    = get_categories();
$stats         = get_dashboard_stats();
$heroCar       = get_car(7);          // the car pictured in the hero

$pageTitle       = 'Home';
$pageDescription = 'Six manufacturers, one showroom. Browse and reserve your next car online.';
$bodyClass       = 'page-home';

require __DIR__ . '/includes/header.php';
?>

<section class="hero grain" aria-labelledby="hero-heading">
    <div class="grid-field" aria-hidden="true"></div>
    <div class="glow glow-a" aria-hidden="true"></div>
    <div class="glow glow-b" aria-hidden="true"></div>

    <div class="container hero-inner">
        <div class="hero-text">
            <p class="eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span>Authorised dealer for six brands</p>
            <h1 id="hero-heading">Six great car brands.<br><span class="hero-dim">One showroom.</span></h1>
            <p class="hero-lead">
                Toyota, Ford, Chevrolet, Nissan, Hyundai and BMW side by side. Live stock,
                official safety recalls on every car, and a reservation in under a minute.
            </p>
            <p class="hero-actions">
                <a class="btn btn-primary btn-lg" href="products.php">Browse the showroom</a>
                <a class="btn btn-ghost btn-lg" href="contact.php">Visit us</a>
            </p>
            <dl class="hero-stats">
                <div><dt>Models</dt><dd><?= (int) $stats['cars'] ?></dd></div>
                <div><dt>Cars on the floor</dt><dd><?= (int) $stats['units'] ?></dd></div>
                <div><dt>Brands</dt><dd><?= (int) $stats['brands'] ?></dd></div>
            </dl>
        </div>

        <figure class="hero-art">
            <img src="images/cars/ford-mustang.jpg" alt="Ford Mustang GT in the showroom" width="900" height="560">
            <?php if ($heroCar !== null): ?>
                <figcaption class="hero-tag">
                    <span class="hero-tag-name"><?= e($heroCar['car_name']) ?></span>
                    <span class="hero-tag-price"><?= e(money((float) $heroCar['price'])) ?></span>
                    <a href="product_details.php?id=<?= (int) $heroCar['car_id'] ?>">View car</a>
                </figcaption>
            <?php endif; ?>
        </figure>
    </div>
</section>

<!-- The real brand logos, scrolling. The track holds two identical halves,
     each with the list three times, so one half is wider than any screen.
     The animation slides exactly one half to the left and starts again, so
     the logos that leave on the left are already coming in on the right
     and there is never a gap or a jump. Only the first list is read by
     screen readers and reachable with Tab. -->
<section class="marquee" aria-label="Brands we sell">
    <div class="marquee-track">
        <?php for ($i = 0; $i < 6; $i++): $copy = $i > 0; ?>
            <ul class="marquee-list"<?= $copy ? ' aria-hidden="true"' : '' ?>>
                <?php foreach ($brands as $brand): ?>
                    <li>
                        <a class="logo-plate" href="products.php?brand_id=<?= (int) $brand['brand_id'] ?>"<?= $copy ? ' tabindex="-1"' : '' ?>>
                            <img src="<?= e(brand_logo($brand['logo'])) ?>" alt="<?= $copy ? '' : e($brand['brand_name']) ?>" height="40">
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endfor; ?>
    </div>
</section>

<?php if ($pastPurchases !== []): ?>
<!-- Task 12: past purchases, shown only when the cookie holds an order id. -->
<section class="container section past-purchases" aria-labelledby="past-heading">
    <div class="section-head">
        <div>
            <h2 id="past-heading">Welcome back</h2>
            <p class="section-sub">
                We remembered these orders on this device.
                <?= count($pastPurchases) === 1 ? 'Here it is' : 'Here they are' ?>.
            </p>
        </div>
        <a class="btn btn-quiet btn-sm" href="index.php?forget=1">Forget my history</a>
    </div>

    <ul class="order-list">
        <?php foreach ($pastPurchases as $order): ?>
            <li class="order-card">
                <div class="order-card-head">
                    <h3>Order #<?= (int) $order['order_id'] ?></h3>
                    <span class="badge badge-<?= e(strtolower((string) $order['status'])) ?>">
                        <?= e($order['status']) ?>
                    </span>
                </div>
                <p class="order-meta">
                    <time datetime="<?= e(date('Y-m-d', strtotime((string) $order['order_date']))) ?>">
                        <?= e(date('j F Y', strtotime((string) $order['order_date']))) ?>
                    </time>
                    &middot; <?= e(money_exact((float) $order['total_amount'])) ?>
                </p>
                <ul class="order-items">
                    <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <img src="<?= e(car_image($item['picture'])) ?>" alt=""
                                 width="64" height="40" loading="lazy">
                            <a href="product_details.php?id=<?= (int) $item['car_id'] ?>">
                                <?= e($item['car_name']) ?>
                            </a>
                            <span class="qty">x<?= (int) $item['quantity'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="container section" aria-labelledby="brands-heading">
    <div class="section-head">
        <div>
            <h2 id="brands-heading">Shop by brand</h2>
            <p class="section-sub">Every car below is held in our own stock.</p>
        </div>
    </div>

    <ul class="brand-strip">
        <?php foreach ($brands as $brand): ?>
            <li>
                <a class="brand-tile" href="products.php?brand_id=<?= (int) $brand['brand_id'] ?>">
                    <img src="<?= e(brand_logo($brand['logo'])) ?>"
                         alt="<?= e($brand['brand_name']) ?> logo"
                         width="72" height="72" loading="lazy">
                    <span class="brand-tile-name"><?= e($brand['brand_name']) ?></span>
                    <span class="brand-tile-country"><?= e($brand['country']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="container section" aria-labelledby="body-heading">
    <div class="section-head">
        <div>
            <h2 id="body-heading">Shop by body style</h2>
            <p class="section-sub">Not sure which brand? Start from the shape you need.</p>
        </div>
    </div>

    <ul class="chip-row">
        <?php foreach ($categories as $category): ?>
            <li>
                <a class="chip" href="products.php?category_id=<?= (int) $category['category_id'] ?>">
                    <?= e($category['category_name']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<!-- Task 3: products read from the database. Featured ones only here,
     products.php lists the full showroom. -->
<section class="container section" aria-labelledby="featured-heading">
    <div class="section-head">
        <div>
            <h2 id="featured-heading">This month in the showroom</h2>
            <p class="section-sub">Click any car to see the full specification.</p>
        </div>
        <a class="btn btn-quiet btn-sm" href="products.php">See all <?= count(get_cars()) ?> cars</a>
    </div>

    <?php if ($featuredCars === []): ?>
        <p class="empty-state">No featured vehicles at the moment. Please see the full showroom.</p>
    <?php else: ?>
        <ul class="car-grid">
            <?php foreach ($featuredCars as $car): ?>
                <?php require __DIR__ . '/includes/car_card.php'; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="container section why-us" aria-labelledby="why-heading">
    <h2 id="why-heading">Why buy from AutoSouq</h2>
    <ul class="feature-grid">
        <li>
            <h3>Live stock, not guesses</h3>
            <p>The number you see is the number on our floor. If it says three units,
               three units exist, and the count drops the second someone buys one.</p>
        </li>
        <li>
            <h3>Six brands, one visit</h3>
            <p>Compare a Land Cruiser against a Patrol and a Tahoe without driving
               to three different dealerships.</p>
        </li>
        <li>
            <h3>Manufacturer warranty</h3>
            <p>Every car is sold with its full factory warranty, from three years
               up to eight on the electric models.</p>
        </li>
        <li>
            <h3>Reserve online</h3>
            <p>Put a car in your cart, check out, and we hold it for you.
               No deposit is taken on this website.</p>
        </li>
    </ul>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
