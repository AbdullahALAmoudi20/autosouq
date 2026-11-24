<?php
/**
 * AutoSouq - compare up to three cars side by side.
 *
 * The cars come from the query string, either as ids=1,4,7 (the link in
 * the compare tray built by js/compare.js) or as c1, c2 and c3 (the picker
 * form at the top of this page, which works without JavaScript). Both are
 * reduced to at most three unique positive ids before anything is loaded.
 *
 * The table is a real data table: one column per car with a <th
 * scope="col">, one row per attribute with a <th scope="row">, so a screen
 * reader announces "Price, Toyota Camry LE, SAR 112,000" for each cell.
 * The best figure in a row is marked with a visible "Best" tag and the
 * hidden text "(best)", so it is not shown by colour alone.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/compare.php
 * @task    Extra: car comparison
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';

const COMPARE_MAX = 3;

/* Read the requested ids. The picker form wins when it was used, because
 * its select boxes always send c1, c2 and c3 (empty when not chosen). */
$rawIds = [];
if (isset($_GET['c1']) || isset($_GET['c2']) || isset($_GET['c3'])) {
    foreach (['c1', 'c2', 'c3'] as $key) {
        $rawIds[] = str_input($key);
    }
} else {
    $rawIds = explode(',', str_input('ids'));
}

$ids = [];
foreach ($rawIds as $raw) {
    $raw = trim($raw);
    // Digits only, so "7abc" or "-3" are ignored rather than cast to a number.
    if ($raw === '' || !preg_match('/^\d{1,9}$/', $raw)) {
        continue;
    }
    $id = (int) $raw;
    if ($id > 0 && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
    if (count($ids) === COMPARE_MAX) {
        break;
    }
}

/* Load each car with the same helpers the car page uses. A car deleted
 * since it was picked is skipped and the visitor is told. */
$cars = [];
foreach ($ids as $id) {
    $row = get_car($id);
    if ($row !== null) {
        $row['colors'] = get_car_colors($id);
        $cars[] = $row;
    }
}
$missingCount = count($ids) - count($cars);
$shownIds     = array_map(static fn (array $c): int => (int) $c['car_id'], $cars);

// Did this request name the cars (tray link, picker, Remove or Clear)? Then
// js/compare.js copies the result into its saved list, even when 0 or 1 cars
// are left. A bare compare.php (the nav link) leaves the saved list alone.
$explicitList = isset($_GET['ids']) || isset($_GET['c1']) || isset($_GET['c2']) || isset($_GET['c3']);

/** Link to this page with one car taken out, used by the remove links.
 *  "ids=" (empty) rather than a bare compare.php, so the last removal also
 *  empties the saved list. */
$withoutLink = static function (int $removeId) use ($shownIds): string {
    $rest = array_values(array_filter($shownIds, static fn (int $id): bool => $id !== $removeId));
    return 'compare.php?ids=' . implode(',', $rest);
};

/* Which cars hold the best value in each numeric row. Nothing is marked
 * when every car has the same figure, because "best" would mean nothing. */
$best = [];
if (count($cars) >= 2) {
    $rules = [
        'price'        => 'min',
        'horsepower'   => 'max',
        'seats'        => 'max',
        'warranty_yrs' => 'max',
        'model_year'   => 'max',
    ];
    foreach ($rules as $column => $direction) {
        $values = array_map(static fn (array $c): float => (float) $c[$column], $cars);
        if (max($values) === min($values)) {
            $best[$column] = [];
            continue;
        }
        $target = $direction === 'min' ? min($values) : max($values);
        $best[$column] = array_keys(array_filter($values, static fn (float $v): bool => $v === $target));
    }
}

/** Visible tag plus hidden text for the best cell of a row. */
$bestMark = static function (string $column, int $index) use ($best): string {
    if (!in_array($index, $best[$column] ?? [], true)) {
        return '';
    }
    return ' <span class="best-tag" aria-hidden="true">Best</span><span class="sr-only"> (best)</span>';
};
$bestClass = static function (string $column, int $index) use ($best): string {
    return in_array($index, $best[$column] ?? [], true) ? ' class="is-best"' : '';
};

/* Every car for the picker, grouped by brand in the select boxes. */
$carsByBrand = [];
foreach (get_cars(['sort' => 'brand']) as $option) {
    $carsByBrand[(string) $option['brand_name']][] = $option;
}

$pageTitle       = 'Compare cars';
$pageDescription = 'Put up to three of our cars side by side: price, power, seats, warranty and more.';
$bodyClass       = 'page-compare';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head">
        <h1>Compare cars</h1>
        <p class="page-lead">
            Put two or three cars side by side. Press <strong>Compare</strong> on any car
            in the showroom, or choose them in the boxes below.
        </p>
    </header>

    <!-- Plain GET form, so the page works without JavaScript. -->
    <form class="filter-bar compare-picker" method="get" action="compare.php">
        <?php for ($slot = 1; $slot <= COMPARE_MAX; $slot++): ?>
            <?php $selectedId = $shownIds[$slot - 1] ?? 0; ?>
            <div class="field">
                <label for="c<?= $slot ?>">Car <?= $slot ?></label>
                <select id="c<?= $slot ?>" name="c<?= $slot ?>">
                    <option value="">No car</option>
                    <?php foreach ($carsByBrand as $brandName => $brandCars): ?>
                        <optgroup label="<?= e($brandName) ?>">
                            <?php foreach ($brandCars as $option): ?>
                                <option value="<?= (int) $option['car_id'] ?>"
                                    <?= (int) $option['car_id'] === $selectedId ? 'selected' : '' ?>>
                                    <?= e($option['car_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endfor; ?>
        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Compare</button>
            <?php if ($shownIds !== []): ?>
                <a class="btn btn-quiet" href="compare.php?ids=">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($missingCount > 0): ?>
        <p class="notice notice-info" role="status">
            <?= $missingCount === 1
                ? 'One of the cars you picked is no longer in our showroom, so it was left out.'
                : 'Some of the cars you picked are no longer in our showroom, so they were left out.' ?>
        </p>
    <?php endif; ?>

    <?php if (count($cars) < 2): ?>
        <?php if ($explicitList): ?>
            <!-- No table to read, so this hidden marker tells js/compare.js
                 which cars are left (an empty list after Clear). -->
            <div hidden data-compare-ids="<?= e(implode(',', $shownIds)) ?>">
                <?php foreach ($cars as $car): ?>
                    <span data-car-id="<?= (int) $car['car_id'] ?>" data-car-name="<?= e($car['car_name']) ?>"></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="empty-state empty-state-lg">
            <h2>Pick at least two cars</h2>
            <?php if (count($cars) === 1): ?>
                <p>You have picked the <?= e($cars[0]['car_name']) ?>. Choose one or two more to see them side by side.</p>
            <?php else: ?>
                <p>Choose two or three cars in the boxes above, or press <strong>Compare</strong>
                   on the cars you like in the showroom.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="products.php">Browse the showroom</a></p>
        </div>
    <?php else: ?>
        <!-- The wrapper scrolls sideways on a phone. It can take keyboard
             focus so the scroll also works without a mouse, and
             data-compare-ids lets js/compare.js match its saved list to
             what is on screen. -->
        <div class="compare-scroll" role="region" aria-labelledby="compare-caption" tabindex="0"
             data-compare-ids="<?= e(implode(',', $shownIds)) ?>">
            <table class="compare-table">
                <caption id="compare-caption">
                    Comparing <?= count($cars) ?> cars. The best figure in each row is marked Best.
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="compare-corner"><span class="sr-only">Detail</span></th>
                        <?php foreach ($cars as $car): ?>
                            <th scope="col" class="compare-car"
                                data-car-id="<?= (int) $car['car_id'] ?>"
                                data-car-name="<?= e($car['car_name']) ?>">
                                <div class="compare-photo">
                                    <img class="framed-img" src="<?= e(car_image($car['picture'])) ?>"
                                         alt="" style="<?= e(car_image_style($car)) ?>"
                                         width="240" height="150" loading="lazy">
                                </div>
                                <a class="compare-name" href="product_details.php?id=<?= (int) $car['car_id'] ?>">
                                    <?= e($car['car_name']) ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <!-- The Remove links have their own row. Inside the column
                         headers they would be read out with every cell. -->
                    <tr class="compare-remove-row">
                        <th scope="row"><span class="sr-only">Remove</span></th>
                        <?php foreach ($cars as $car): ?>
                            <td>
                                <a class="compare-remove" href="<?= e($withoutLink((int) $car['car_id'])) ?>">
                                    Remove<span class="sr-only"> <?= e($car['car_name']) ?> from comparison</span>
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Price</th>
                        <?php foreach ($cars as $i => $car): ?>
                            <td<?= $bestClass('price', $i) ?>><?= e(money((float) $car['price'])) ?><?= $bestMark('price', $i) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Brand</th>
                        <?php foreach ($cars as $car): ?>
                            <td><?= e($car['brand_name']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Body style</th>
                        <?php foreach ($cars as $car): ?>
                            <td><?= e($car['category_name']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Model year</th>
                        <?php foreach ($cars as $i => $car): ?>
                            <td<?= $bestClass('model_year', $i) ?>><?= (int) $car['model_year'] ?><?= $bestMark('model_year', $i) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Engine</th>
                        <?php foreach ($cars as $car): ?>
                            <td><?= e($car['engine']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Horsepower</th>
                        <?php foreach ($cars as $i => $car): ?>
                            <td<?= $bestClass('horsepower', $i) ?>><?= (int) $car['horsepower'] ?> hp<?= $bestMark('horsepower', $i) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Transmission</th>
                        <?php foreach ($cars as $car): ?>
                            <td><?= e($car['transmission']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Fuel</th>
                        <?php foreach ($cars as $car): ?>
                            <td><?= e($car['fuel_type']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Seats</th>
                        <?php foreach ($cars as $i => $car): ?>
                            <td<?= $bestClass('seats', $i) ?>><?= (int) $car['seats'] ?><?= $bestMark('seats', $i) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Warranty (years)</th>
                        <?php foreach ($cars as $i => $car): ?>
                            <td<?= $bestClass('warranty_yrs', $i) ?>><?= (int) $car['warranty_yrs'] ?><?= $bestMark('warranty_yrs', $i) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">In stock</th>
                        <?php foreach ($cars as $car): ?>
                            <?php $carStock = (int) $car['stock']; ?>
                            <td class="<?= $carStock > 0 ? 'compare-stock' : 'compare-stock is-zero' ?>">
                                <?= $carStock > 0
                                    ? 'Yes, ' . $carStock . ' ' . ($carStock === 1 ? 'unit' : 'units')
                                    : 'No, sold out' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Colours</th>
                        <?php foreach ($cars as $car): ?>
                            <td>
                                <?php if ($car['colors'] === []): ?>
                                    Ask the showroom
                                <?php else: ?>
                                    <ul class="compare-colours">
                                        <?php foreach ($car['colors'] as $colour): ?>
                                            <li>
                                                <span class="swatch" style="background: <?= e($colour['hex_code']) ?>"
                                                      aria-hidden="true"></span>
                                                <?= e($colour['color_name']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="compare-foot">
            Prices are in <?= e(CURRENCY) ?> and include the manufacturer warranty.
            Open a car to add it to your cart.
        </p>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
