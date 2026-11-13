<?php
/**
 * One car tile. A partial, not a page: index.php and products.php include
 * it inside their foreach loop so the card markup is written once (Task 16).
 *
 * Expects $car to be in scope, holding one row from get_cars() or
 * get_related_cars().
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/includes/car_card.php
 * @task    Task 3, Task 16
 */

declare(strict_types=1);

/** @var array $car */
$carId    = (int) $car['car_id'];
$stock    = (int) $car['stock'];
$detailUrl = 'product_details.php?id=' . $carId;

// Single accessible name for the whole card, e.g. "Toyota Camry LE,
// SAR 112,000, 8 in stock", so the card reads as one link.
$cardLabel = $car['car_name'] . ', ' . money((float) $car['price']) . ', '
           . ($stock > 0 ? $stock . ' in stock' : 'out of stock');
?>
<li class="car-card<?= $stock === 0 ? ' is-sold-out' : '' ?>">
    <a class="car-card-link" href="<?= e($detailUrl) ?>"
       aria-label="<?= e($cardLabel) ?>">

        <div class="car-card-media">
            <!-- The framing (fit, focus point, zoom) is set by the manager on
                 the edit form; car_image_style() only emits clamped numbers. -->
            <img class="framed-img" src="<?= e(car_image($car['picture'])) ?>"
                 alt="<?= e($car['car_name']) ?>"
                 style="<?= e(car_image_style($car)) ?>"
                 width="360" height="220" loading="lazy">

            <?php if ($stock === 0): ?>
                <span class="ribbon ribbon-danger">Sold out</span>
            <?php elseif ($stock <= LOW_STOCK_THRESHOLD): ?>
                <span class="ribbon ribbon-warn">Only <?= $stock ?> left</span>
            <?php elseif (!empty($car['is_featured'])): ?>
                <span class="ribbon ribbon-accent">Featured</span>
            <?php endif; ?>
        </div>

        <div class="car-card-body">
            <p class="car-card-brand">
                <?= e($car['brand_name'] ?? '') ?>
                <?php if (!empty($car['category_name'])): ?>
                    <span class="dot" aria-hidden="true">&middot;</span>
                    <?= e($car['category_name']) ?>
                <?php endif; ?>
            </p>

            <h3 class="car-card-name"><?= e($car['car_name']) ?></h3>

            <?php if (!empty($car['engine'])): ?>
                <p class="car-card-spec">
                    <?= e($car['engine']) ?>
                    <?php if (!empty($car['horsepower'])): ?>
                        &middot; <?= (int) $car['horsepower'] ?> hp
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <p class="car-card-foot">
                <span class="car-card-price"><?= e(money((float) $car['price'])) ?></span>
                <span class="car-card-stock<?= $stock === 0 ? ' is-zero' : '' ?>">
                    <?= $stock > 0 ? $stock . ' in stock' : 'Out of stock' ?>
                </span>
            </p>
        </div>
    </a>
</li>
