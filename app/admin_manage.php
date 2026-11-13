<?php
/**
 * AutoSouq - manage vehicles (managers only).
 *
 * Task 9 adds a product with its picture upload; Task 10 searches, then
 * modifies or deletes. One page covers all three because they share the
 * same form and list, and it keeps the project within the ten page limit.
 * ?mode=list|add|edit picks the view. The database work is in
 * includes/admin.php; this file routes the request and renders the HTML.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/admin_manage.php
 * @task    Task 9, Task 10
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';

require_admin();   // nothing below runs for a guest

require_once __DIR__ . '/includes/cart.php';

/* 1. Handle the POST actions. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When the uploads together go over post_max_size, PHP throws the whole
    // body away, so $_POST arrives empty and the CSRF check would fail with
    // a confusing message. Catch that case first.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Those photos are too large to send in one go. '
                     . 'Please add fewer photos at a time.');
        redirect('admin_manage.php' . (int_input('id') > 0 ? '?mode=edit&id=' . int_input('id') : '?mode=add'));
    }

    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {                       // Task 9
        $result = admin_create_car($_POST, $_FILES['picture'] ?? null, $_FILES['gallery'] ?? null);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect($result['ok'] ? 'admin_manage.php' : 'admin_manage.php?mode=add');
    }

    if ($action === 'update') {                       // Task 10 (modify)
        $carId  = int_input('car_id');
        $result = admin_update_car($carId, $_POST, $_FILES['picture'] ?? null, $_FILES['gallery'] ?? null);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect($result['ok'] ? 'admin_manage.php' : 'admin_manage.php?mode=edit&id=' . $carId);
    }

    if ($action === 'delete') {                       // Task 10 (delete)
        $result = admin_delete_car(int_input('car_id'));
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('admin_manage.php');
    }
}

/* 2. Work out what to show. */
$mode    = str_input('mode', 'list');
$search  = str_input('q');
$sort    = str_input('sort', 'name');
$editId  = int_input('id');

$editing = null;
if ($mode === 'edit') {
    $editing = get_car($editId);
    if ($editing === null) {
        flash('error', 'That vehicle no longer exists.');
        redirect('admin_manage.php');
    }
}

$brands        = get_brands();
$categories    = get_categories();
$colors        = get_colors();
$selectedColors = $editing !== null ? get_car_color_ids($editId) : [];
$galleryImages = $editing !== null ? get_car_images($editId) : [];
$framing       = image_framing($editing ?? []);
$cars          = $mode === 'list' ? admin_search_cars($search, $sort) : [];

$pageTitle       = $mode === 'add' ? 'Add a vehicle'
                 : ($mode === 'edit' ? 'Edit vehicle' : 'Manage vehicles');
$pageDescription = 'Add, modify and remove the vehicles in the showroom.';
$bodyClass       = 'page-admin page-manage';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head page-head-admin">
        <div>
            <p class="admin-eyebrow">Manager area</p>
            <h1><?= e($pageTitle) ?></h1>
            <p class="page-lead">
                <?php if ($mode === 'add'): ?>
                    Fill in the specification and upload the photos.
                <?php elseif ($mode === 'edit'): ?>
                    Editing <strong><?= e((string) $editing['car_name']) ?></strong>.
                    Leave the picture box empty to keep the current photos.
                <?php else: ?>
                    Search for a vehicle, then modify or delete it.
                <?php endif; ?>
            </p>
        </div>
        <p class="page-head-actions">
            <?php if ($mode === 'list'): ?>
                <a class="btn btn-primary" href="admin_manage.php?mode=add">Add a vehicle</a>
            <?php else: ?>
                <a class="btn btn-quiet" href="admin_manage.php">Back to the list</a>
            <?php endif; ?>
            <a class="btn btn-quiet" href="admin_dashboard.php">Dashboard</a>
        </p>
    </header>

<?php if ($mode === 'list'): ?>

    <!-- Task 10 - search. The term goes to admin_search_cars(), which
         puts the LIKE inside a prepared statement, so a search such as
         a' OR 1=1 -- is treated as text and matches nothing. -->
    <form class="filter-bar filter-bar-admin" method="get" action="admin_manage.php"
          data-validate="search" aria-label="Search the catalogue">

        <div class="field field-grow">
            <label for="q">Search by name, brand or body style</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Camry, BMW, pickup..." maxlength="60">
            <p class="field-error" id="q-error" role="alert"></p>
        </div>

        <div class="field">
            <label for="sort">Sort by</label>
            <select id="sort" name="sort">
                <option value="name"       <?= $sort === 'name'       ? 'selected' : '' ?>>Name</option>
                <option value="brand"      <?= $sort === 'brand'      ? 'selected' : '' ?>>Brand</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Most expensive</option>
                <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Cheapest</option>
                <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest model year</option>
            </select>
        </div>

        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-quiet" href="admin_manage.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="result-count" role="status">
        <?= count($cars) ?> <?= count($cars) === 1 ? 'vehicle' : 'vehicles' ?>
        <?= $search === '' ? 'in the catalogue' : 'match "' . e($search) . '"' ?>.
    </p>

    <?php if ($cars === []): ?>
        <div class="empty-state empty-state-lg">
            <h2>Nothing found</h2>
            <p>No vehicle matches that search. Try a shorter term, or
               <a href="admin_manage.php">show everything</a>.</p>
        </div>
    <?php else: ?>
        <table class="data-table data-table-manage">
            <caption class="sr-only">Every vehicle, with edit and delete controls</caption>
            <thead>
                <tr>
                    <th scope="col">Vehicle</th>
                    <th scope="col">Brand</th>
                    <th scope="col">Body</th>
                    <th scope="col">Price</th>
                    <th scope="col">Stock</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cars as $row): ?>
                <tr>
                    <th scope="row" class="cell-car">
                        <img src="<?= e(car_image($row['picture'])) ?>" alt=""
                             width="72" height="46" loading="lazy">
                        <span>
                            <a href="product_details.php?id=<?= (int) $row['car_id'] ?>">
                                <?= e($row['car_name']) ?>
                            </a>
                            <small>#<?= (int) $row['car_id'] ?>
                                &middot; <?= (int) $row['model_year'] ?></small>
                        </span>
                    </th>
                    <td data-label="Brand"><?= e($row['brand_name']) ?></td>
                    <td data-label="Body"><?= e($row['category_name']) ?></td>
                    <td data-label="Price"><?= e(money((float) $row['price'])) ?></td>
                    <td data-label="Stock">
                        <span class="pill <?= (int) $row['stock'] === 0 ? 'pill-danger'
                              : ((int) $row['stock'] <= LOW_STOCK_THRESHOLD ? 'pill-warn' : '') ?>">
                            <?= (int) $row['stock'] ?>
                        </span>
                    </td>
                    <td data-label="Actions" class="cell-actions">
                        <a class="btn btn-quiet btn-sm"
                           href="admin_manage.php?mode=edit&amp;id=<?= (int) $row['car_id'] ?>">
                            Modify<span class="sr-only"> <?= e($row['car_name']) ?></span>
                        </a>

                        <form method="post" action="admin_manage.php"
                              data-confirm="Delete the <?= e($row['car_name']) ?> permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="car_id" value="<?= (int) $row['car_id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">
                                Delete<span class="sr-only"> <?= e($row['car_name']) ?></span>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else: ?>

    <!-- Task 9 (add) and Task 10 (modify) share this form. $editing is
         null when adding and holds the row when editing. enctype is
         multipart because of the picture upload. -->
    <?php
    $isEdit = $editing !== null;
    $val = static function (string $field, $fallback = '') use ($editing) {
        return $editing !== null ? $editing[$field] : $fallback;
    };
    ?>

    <!-- The id in the action URL lets the page return to this car even when
         an oversized upload makes PHP drop the posted fields. -->
    <form class="car-form" method="post"
          action="admin_manage.php<?= $isEdit ? '?id=' . (int) $editing['car_id'] : '' ?>"
          enctype="multipart/form-data" data-validate="car" novalidate>

        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="car_id" value="<?= (int) $editing['car_id'] ?>">
        <?php endif; ?>

        <div class="car-form-grid">

            <fieldset class="form-block">
                <legend>Identity</legend>

                <div class="field">
                    <label for="car_name">Vehicle name <span class="req">*</span></label>
                    <input type="text" id="car_name" name="car_name" maxlength="100"
                           value="<?= e((string) $val('car_name')) ?>"
                           aria-describedby="car_name-hint car_name-error" required>
                    <p class="field-hint" id="car_name-hint">
                        Include the brand and the trim, e.g. Toyota Camry LE.
                    </p>
                    <p class="field-error" id="car_name-error" role="alert"></p>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="brand_id">Brand <span class="req">*</span></label>
                        <select id="brand_id" name="brand_id"
                                aria-describedby="brand_id-error" required>
                            <option value="">Choose a brand</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int) $brand['brand_id'] ?>"
                                    <?= (int) $val('brand_id', 0) === (int) $brand['brand_id'] ? 'selected' : '' ?>>
                                    <?= e($brand['brand_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="brand_id-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="category_id">Body style <span class="req">*</span></label>
                        <select id="category_id" name="category_id"
                                aria-describedby="category_id-error" required>
                            <option value="">Choose a body style</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['category_id'] ?>"
                                    <?= (int) $val('category_id', 0) === (int) $category['category_id'] ? 'selected' : '' ?>>
                                    <?= e($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="category_id-error" role="alert"></p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="model_year">Model year <span class="req">*</span></label>
                        <input type="number" id="model_year" name="model_year"
                               min="1990" max="<?= (int) date('Y') + 2 ?>" step="1"
                               value="<?= e((string) $val('model_year', (string) date('Y'))) ?>"
                               aria-describedby="model_year-error" required>
                        <p class="field-error" id="model_year-error" role="alert"></p>
                    </div>

                    <div class="field field-check">
                        <input type="checkbox" id="is_featured" name="is_featured" value="1"
                               <?= (int) $val('is_featured', 0) === 1 ? 'checked' : '' ?>>
                        <label for="is_featured">Show on the home page</label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-block">
                <legend>Commercial</legend>

                <div class="form-row">
                    <div class="field">
                        <label for="price">Price in <?= e(CURRENCY) ?>
                            <span class="req">*</span></label>
                        <input type="number" id="price" name="price"
                               min="1" step="0.01"
                               value="<?= e((string) $val('price')) ?>"
                               aria-describedby="price-error" required>
                        <p class="field-error" id="price-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="stock">Units in stock <span class="req">*</span></label>
                        <input type="number" id="stock" name="stock"
                               min="0" step="1"
                               value="<?= e((string) $val('stock', '0')) ?>"
                               aria-describedby="stock-hint stock-error" required>
                        <p class="field-hint" id="stock-hint">
                            Set to 0 to hide a car from customers without deleting it.
                        </p>
                        <p class="field-error" id="stock-error" role="alert"></p>
                    </div>
                </div>

                <div class="field">
                    <label for="warranty_yrs">Warranty in years</label>
                    <input type="number" id="warranty_yrs" name="warranty_yrs"
                           min="0" max="10" step="1"
                           value="<?= e((string) $val('warranty_yrs', '3')) ?>">
                </div>
            </fieldset>

            <fieldset class="form-block">
                <legend>Mechanical</legend>

                <div class="field">
                    <label for="engine">Engine <span class="req">*</span></label>
                    <input type="text" id="engine" name="engine" maxlength="60"
                           value="<?= e((string) $val('engine')) ?>"
                           placeholder="2.5L 4-Cylinder"
                           aria-describedby="engine-error" required>
                    <p class="field-error" id="engine-error" role="alert"></p>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="horsepower">Horsepower <span class="req">*</span></label>
                        <input type="number" id="horsepower" name="horsepower"
                               min="50" max="1500" step="1"
                               value="<?= e((string) $val('horsepower', '150')) ?>"
                               aria-describedby="horsepower-error" required>
                        <p class="field-error" id="horsepower-error" role="alert"></p>
                    </div>

                    <div class="field">
                        <label for="seats">Seats <span class="req">*</span></label>
                        <input type="number" id="seats" name="seats"
                               min="2" max="9" step="1"
                               value="<?= e((string) $val('seats', '5')) ?>"
                               aria-describedby="seats-error" required>
                        <p class="field-error" id="seats-error" role="alert"></p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="transmission">Transmission</label>
                        <select id="transmission" name="transmission">
                            <?php foreach (['Automatic', 'Manual', 'CVT', 'Dual-Clutch'] as $option): ?>
                                <option value="<?= e($option) ?>"
                                    <?= (string) $val('transmission', 'Automatic') === $option ? 'selected' : '' ?>>
                                    <?= e($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="fuel_type">Fuel</label>
                        <select id="fuel_type" name="fuel_type">
                            <?php foreach (['Petrol', 'Diesel', 'Hybrid', 'Electric'] as $option): ?>
                                <option value="<?= e($option) ?>"
                                    <?= (string) $val('fuel_type', 'Petrol') === $option ? 'selected' : '' ?>>
                                    <?= e($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-block">
                <legend>Colours</legend>

                <fieldset class="colour-picker">
                    <legend>Available colours</legend>
                    <div class="colour-options">
                        <?php foreach ($colors as $colour): ?>
                            <div class="colour-option">
                                <input type="checkbox"
                                       id="colour-<?= (int) $colour['color_id'] ?>"
                                       name="colors[]"
                                       value="<?= (int) $colour['color_id'] ?>"
                                       <?= in_array((int) $colour['color_id'], $selectedColors, true) ? 'checked' : '' ?>>
                                <label for="colour-<?= (int) $colour['color_id'] ?>">
                                    <span class="swatch"
                                          style="background: <?= e($colour['hex_code']) ?>"
                                          aria-hidden="true"></span>
                                    <?= e($colour['color_name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </fieldset>

            <!-- Task 9 - photos: the main picture, how it is framed on the
                 cards, and the extra gallery photos. js/admin-images.js
                 gives the live preview; without JavaScript the controls
                 still post normal values. -->
            <fieldset class="form-block form-block-wide photo-block">
                <legend>Photos</legend>

                <div class="photo-layout">

                    <div class="photo-main">
                        <h3 class="photo-subhead">Main picture</h3>

                        <div class="frame-preview car-card-media" id="frame-preview">
                            <img class="framed-img" id="frame-preview-img"
                                 src="<?= e(car_image($isEdit ? (string) $editing['picture'] : '')) ?>"
                                 alt="Preview of how the main picture looks on the showroom cards"
                                 style="<?= e(car_image_style($editing ?? [])) ?>"
                                 width="360" height="225">
                        </div>
                        <p class="field-hint">This is how the picture appears on the showroom cards
                            and on the car page.</p>

                        <div class="field">
                            <label for="picture">
                                <?= $isEdit ? 'Replace the main picture' : 'Main picture' ?>
                                <?php if (!$isEdit): ?><span class="optional">(optional)</span><?php endif; ?>
                            </label>
                            <input type="file" id="picture" name="picture"
                                   accept=".jpg,.jpeg,.png,.webp"
                                   aria-describedby="picture-hint picture-error">
                            <p class="field-hint" id="picture-hint">
                                <?= e(strtoupper(str_replace(',', ', ', ALLOWED_UPLOAD_EXT))) ?>,
                                up to <?= (int) (MAX_UPLOAD_BYTES / 1024 / 1024) ?> MB.
                                Only the file name is stored in the database.
                            </p>
                            <p class="field-error" id="picture-error" role="alert"></p>
                        </div>
                    </div>

                    <div class="photo-framing">
                        <h3 class="photo-subhead">Fit and position</h3>

                        <div class="field">
                            <label for="image_fit">Fit</label>
                            <select id="image_fit" name="image_fit" data-frame="fit">
                                <option value="cover" <?= $framing['fit'] === 'cover' ? 'selected' : '' ?>>
                                    Fill the frame (crops the edges)</option>
                                <option value="contain" <?= $framing['fit'] === 'contain' ? 'selected' : '' ?>>
                                    Show the whole photo</option>
                            </select>
                        </div>

                        <div class="field range-field">
                            <label for="image_pos_x">Left / right
                                <output for="image_pos_x" id="image_pos_x-out"><?= $framing['x'] ?>%</output></label>
                            <input type="range" id="image_pos_x" name="image_pos_x"
                                   min="0" max="100" step="1" value="<?= $framing['x'] ?>" data-frame="x">
                        </div>

                        <div class="field range-field">
                            <label for="image_pos_y">Up / down
                                <output for="image_pos_y" id="image_pos_y-out"><?= $framing['y'] ?>%</output></label>
                            <input type="range" id="image_pos_y" name="image_pos_y"
                                   min="0" max="100" step="1" value="<?= $framing['y'] ?>" data-frame="y">
                        </div>

                        <div class="field range-field">
                            <label for="image_zoom">Zoom
                                <output for="image_zoom" id="image_zoom-out"><?= $framing['zoom'] ?>%</output></label>
                            <input type="range" id="image_zoom" name="image_zoom"
                                   min="<?= IMAGE_ZOOM_MIN ?>" max="<?= IMAGE_ZOOM_MAX ?>" step="5"
                                   value="<?= $framing['zoom'] ?>" data-frame="zoom">
                        </div>

                        <button type="button" class="btn btn-quiet btn-sm" id="frame-reset" hidden>
                            Reset to centre
                        </button>
                    </div>
                </div>

                <div class="gallery-manager">
                    <h3 class="photo-subhead">Gallery
                        <span class="optional">(up to <?= MAX_GALLERY_IMAGES ?> extra photos)</span></h3>

                    <?php if ($galleryImages !== []): ?>
                        <fieldset class="gallery-admin-group">
                        <legend class="sr-only">Choose the main picture, or remove gallery photos</legend>
                        <ul class="gallery-admin-list">
                            <li class="gallery-admin-item">
                                <img src="<?= e(car_image((string) $editing['picture'])) ?>" alt=""
                                     width="132" height="82" loading="lazy">
                                <div class="gallery-admin-controls">
                                    <input type="radio" id="main-0" name="main_image" value="0" checked
                                           data-src="<?= e(car_image((string) $editing['picture'])) ?>">
                                    <label for="main-0">Main picture<span class="sr-only">: keep the current one</span></label>
                                </div>
                            </li>
                            <?php foreach ($galleryImages as $index => $image): ?>
                                <?php $imageId = (int) $image['image_id']; ?>
                                <li class="gallery-admin-item">
                                    <img src="<?= e(car_image((string) $image['file_name'])) ?>"
                                         alt="Gallery photo <?= $index + 1 ?>"
                                         width="132" height="82" loading="lazy">
                                    <div class="gallery-admin-controls">
                                        <input type="radio" id="main-<?= $imageId ?>" name="main_image"
                                               value="<?= $imageId ?>"
                                               data-src="<?= e(car_image((string) $image['file_name'])) ?>">
                                        <label for="main-<?= $imageId ?>">Make main<span class="sr-only">: use gallery photo <?= $index + 1 ?> as the main picture</span></label>
                                    </div>
                                    <div class="gallery-admin-controls">
                                        <input type="checkbox" id="remove-<?= $imageId ?>"
                                               name="remove_images[]" value="<?= $imageId ?>">
                                        <label for="remove-<?= $imageId ?>">Remove<span class="sr-only"> gallery photo <?= $index + 1 ?></span></label>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        </fieldset>
                    <?php endif; ?>

                    <div class="field">
                        <label for="gallery">Add gallery photos</label>
                        <input type="file" id="gallery" name="gallery[]" multiple
                               accept=".jpg,.jpeg,.png,.webp"
                               data-room="<?= MAX_GALLERY_IMAGES - count($galleryImages) ?>"
                               data-placeholder="<?= !$isEdit || in_array((string) $editing['picture'], ['', PLACEHOLDER_IMAGE], true) ? '1' : '0' ?>"
                               aria-describedby="gallery-hint gallery-error">
                        <p class="field-hint" id="gallery-hint">
                            Pick photos one at a time or several at once; they add up until you
                            save. Each one up to
                            <?= (int) (MAX_UPLOAD_BYTES / 1024 / 1024) ?> MB.
                            <?= $isEdit ? 'Room for ' . (MAX_GALLERY_IMAGES - count($galleryImages)) . ' more.' : '' ?>
                        </p>
                        <p class="field-error" id="gallery-error" role="alert"></p>
                        <ul class="gallery-new-list" id="gallery-new-list" aria-live="polite"></ul>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-block form-block-wide">
                <legend>Description</legend>
                <div class="field">
                    <label for="description">Sales description</label>
                    <textarea id="description" name="description" rows="4"
                              maxlength="800"><?= e((string) $val('description')) ?></textarea>
                    <p class="field-hint">
                        Shown on the product detail page. Two or three sentences is plenty.
                    </p>
                </div>
            </fieldset>

        </div>

        <div class="form-actions">
            <button class="btn btn-primary btn-lg" type="submit">
                <?= $isEdit ? 'Save changes' : 'Add this vehicle' ?>
            </button>
            <a class="btn btn-quiet btn-lg" href="admin_manage.php">Cancel</a>
        </div>
    </form>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
