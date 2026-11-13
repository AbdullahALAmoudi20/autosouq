<?php
/**
 * AutoSouq - showroom / product listing page.
 *
 * Task 3: reads every car from the database and shows each one as an
 * image linking to its detail page. The brand, body style, keyword and
 * sort filters are all optional and go to get_cars(), which builds one
 * prepared statement from them.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @coauthor Ahmed Muaidi (Front-End) - filter bar markup and styling
 * @file    app/products.php
 * @task    Task 3
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';

$selectedBrand    = int_input('brand_id');
$selectedCategory = int_input('category_id');
$keyword          = str_input('q');
$sort             = str_input('sort', 'brand');
$stockOnly        = isset($_GET['in_stock']);

$cars       = get_cars([
    'brand_id'    => $selectedBrand,
    'category_id' => $selectedCategory,
    'keyword'     => $keyword,
    'sort'        => $sort,
    'in_stock'    => $stockOnly,
]);
$brands     = get_brands();
$categories = get_categories();

$anyFilterActive = $selectedBrand || $selectedCategory || $keyword !== '' || $stockOnly;

$pageTitle       = 'Showroom';
$pageDescription = 'Every Toyota, Ford, Chevrolet, Nissan, Hyundai and BMW we hold in stock.';
$bodyClass       = 'page-products';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head">
        <h1>The showroom</h1>
        <p class="page-lead">
            <?= count($cars) ?>
            <?= count($cars) === 1 ? 'vehicle' : 'vehicles' ?>
            <?= $anyFilterActive ? 'match your filters' : 'currently on our floor' ?>.
            Click any car to open its full specification.
        </p>
    </header>

    <!-- Filter bar. GET so a filtered showroom has its own shareable URL
         and the back button still works. -->
    <form class="filter-bar" method="get" action="products.php"
          data-validate="search" aria-label="Filter the showroom">

        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($keyword) ?>"
                   placeholder="Camry, pickup, BMW..." maxlength="60">
            <p class="field-error" id="q-error" role="alert"></p>
        </div>

        <div class="field">
            <label for="brand_id">Brand</label>
            <select id="brand_id" name="brand_id">
                <option value="0">All brands</option>
                <?php foreach ($brands as $brand): ?>
                    <option value="<?= (int) $brand['brand_id'] ?>"
                        <?= $selectedBrand === (int) $brand['brand_id'] ? 'selected' : '' ?>>
                        <?= e($brand['brand_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="category_id">Body style</label>
            <select id="category_id" name="category_id">
                <option value="0">All styles</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['category_id'] ?>"
                        <?= $selectedCategory === (int) $category['category_id'] ? 'selected' : '' ?>>
                        <?= e($category['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="sort">Sort by</label>
            <select id="sort" name="sort">
                <option value="brand"      <?= $sort === 'brand'      ? 'selected' : '' ?>>Brand</option>
                <option value="name"       <?= $sort === 'name'       ? 'selected' : '' ?>>Name</option>
                <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price, low to high</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price, high to low</option>
                <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest model year</option>
            </select>
        </div>

        <div class="field field-check">
            <input type="checkbox" id="in_stock" name="in_stock" value="1"
                   <?= $stockOnly ? 'checked' : '' ?>>
            <label for="in_stock">In stock only</label>
        </div>

        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Apply</button>
            <?php if ($anyFilterActive): ?>
                <a class="btn btn-quiet" href="products.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Task 3 - the cars themselves, each shown as an image. -->
    <?php if ($cars === []): ?>
        <div class="empty-state empty-state-lg">
            <h2>No vehicles match those filters</h2>
            <p>Try widening your search, or
               <a href="products.php">show the whole showroom</a>.</p>
        </div>
    <?php else: ?>
        <ul class="car-grid car-grid-lg">
            <?php foreach ($cars as $car): ?>
                <?php require __DIR__ . '/includes/car_card.php'; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
