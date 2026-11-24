<?php
/**
 * AutoSouq - product management for signed-in managers.
 *
 * Task 9 adds a product; Task 10 searches, modifies and deletes.
 * Every function assumes the calling page has already run require_admin().
 *
 * Each car has one main picture (cars.picture) plus up to
 * MAX_GALLERY_IMAGES extra photos in car_images, and the manager can set
 * how the main picture is framed on the cards (fit, focus point, zoom).
 * After every successful add, edit or delete the AI assistant's search
 * index is refreshed (see admin_refresh_ai_index()).
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/includes/admin.php
 * @task    Task 9, Task 10
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rag.php';

/**
 * Refresh the AI assistant's index after a car was added, changed or
 * deleted, so the chat knows the new name, specs and description straight
 * away. (Price and stock are not in the index; the chat reads them live.)
 * The car is already saved when this runs, and a failure here (no
 * network, API down) must never undo or block that save, so it only ever
 * returns an extra sentence for the flash message.
 *
 * @return string '' when the index was refreshed without trouble
 */
function admin_refresh_ai_index(): string
{
    try {
        $stats = kb_rebuild_index();
        return $stats['errors'] > 0
            ? 'The AI assistant will use keyword search for this car until the index is rebuilt.'
            : '';
    } catch (Throwable $ex) {
        error_log('AutoSouq: AI index refresh failed: ' . $ex->getMessage());
        return 'The AI assistant index could not be refreshed; use "Rebuild AI index" on the dashboard.';
    }
}

/**
 * Task 10 - search the catalogue by name, brand or body style.
 * An empty term returns everything, so the manage page is not blank on
 * first load.
 */
function admin_search_cars(string $term = '', string $sort = 'name'): array
{
    return get_cars([
        'keyword' => $term,
        'sort'    => $sort === '' ? 'name' : $sort,
    ]);
}

/**
 * Server-side validation of the add / modify product form. The browser
 * checks the same rules in JavaScript (Task 13), but a form can be posted
 * with JavaScript off, so the checks are repeated here.
 *
 * @return string[] error messages, empty when the input is valid
 */
function validate_car_input(array $input): array
{
    $errors = [];

    $name = trim((string) ($input['car_name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Vehicle name is required.';
    } elseif (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
        $errors[] = 'Vehicle name must be between 3 and 100 characters.';
    }

    if (!is_numeric($input['price'] ?? null) || (float) $input['price'] <= 0) {
        $errors[] = 'Price must be a number greater than zero.';
    }

    if (!is_numeric($input['stock'] ?? null) || (int) $input['stock'] < 0) {
        $errors[] = 'Stock must be zero or a positive whole number.';
    }

    $year = (int) ($input['model_year'] ?? 0);
    if ($year < 1990 || $year > (int) date('Y') + 2) {
        $errors[] = 'Model year must be between 1990 and ' . ((int) date('Y') + 2) . '.';
    }

    if ((int) ($input['brand_id'] ?? 0) <= 0) {
        $errors[] = 'Please choose a brand.';
    }
    if ((int) ($input['category_id'] ?? 0) <= 0) {
        $errors[] = 'Please choose a body style.';
    }

    if (trim((string) ($input['engine'] ?? '')) === '') {
        $errors[] = 'Engine description is required.';
    }

    $hp = (int) ($input['horsepower'] ?? 0);
    if ($hp < 50 || $hp > 1500) {
        $errors[] = 'Horsepower must be between 50 and 1500.';
    }

    $seats = (int) ($input['seats'] ?? 0);
    if ($seats < 2 || $seats > 9) {
        $errors[] = 'Seats must be between 2 and 9.';
    }

    return $errors;
}

/**
 * Handle the picture upload from the add / modify form.
 *
 * Four checks: the extension must be on the whitelist, the size is
 * capped, raster files must pass getimagesize() so a .png that is really
 * a PHP script is rejected, and the stored name is generated here rather
 * than taken from the upload, so the caller cannot pick a path or
 * overwrite an existing file.
 *
 * @param array|null $file one entry of $_FILES
 * @return array{ok: bool, filename: ?string, message: string}
 */
function handle_upload(?array $file): array
{
    $none = ['ok' => true, 'filename' => null, 'message' => ''];

    if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $none;   // no new picture chosen: keep the existing one
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'The picture could not be uploaded (error code '
                          . (int) $file['error'] . ').',
        ];
    }

    if ((int) $file['size'] > MAX_UPLOAD_BYTES) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'The picture is larger than '
                          . (int) (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.',
        ];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed   = explode(',', ALLOWED_UPLOAD_EXT);

    if (!in_array($extension, $allowed, true)) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'Allowed picture types are: ' . ALLOWED_UPLOAD_EXT . '.',
        ];
    }

    // getimagesize() reads the real file header, so a script renamed to .jpg is refused.
    if (getimagesize($file['tmp_name']) === false) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'That file is not a valid image.',
        ];
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'The upload folder could not be created.',
        ];
    }

    // Name is generated here, not taken from the uploaded file.
    $storedName = 'upload_' . bin2hex(random_bytes(8)) . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
        return [
            'ok'       => false,
            'filename' => null,
            'message'  => 'The picture could not be saved to the images folder. '
                          . 'Check that app/images/uploads is writable.',
        ];
    }

    return ['ok' => true, 'filename' => $storedName, 'message' => 'Picture uploaded.'];
}

/** Most extra photos one car can have, on top of its main picture. */
const MAX_GALLERY_IMAGES = 8;

/**
 * Framing values from the form, clamped by image_framing() so a tampered
 * request cannot store anything outside the allowed ranges.
 *
 * @return array{fit: string, x: int, y: int, zoom: int}
 */
function framing_from_input(array $input): array
{
    return image_framing([
        'image_fit'   => (string) ($input['image_fit'] ?? 'cover'),
        'image_pos_x' => is_numeric($input['image_pos_x'] ?? null) ? (int) $input['image_pos_x'] : 50,
        'image_pos_y' => is_numeric($input['image_pos_y'] ?? null) ? (int) $input['image_pos_y'] : 50,
        'image_zoom'  => is_numeric($input['image_zoom'] ?? null) ? (int) $input['image_zoom'] : 100,
    ]);
}

/**
 * Turn the multiple-file input gallery[] into a list of single $_FILES
 * entries that handle_upload() understands. Empty slots and malformed
 * entries are skipped.
 *
 * @return array<int, array>
 */
function gallery_files(?array $files): array
{
    if ($files === null || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $list = [];
    foreach ($files['name'] as $i => $name) {
        if (!is_string($name) || !is_string($files['tmp_name'][$i] ?? null)) {
            continue;
        }
        $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = [
            'name'     => $name,
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $error,
            'size'     => (int) ($files['size'][$i] ?? 0),
        ];
    }
    return $list;
}

/**
 * Save the new gallery photos through handle_upload(), stopping once the
 * car has no room left.
 *
 * @return array{saved: string[], errors: string[]}
 */
function save_gallery_uploads(array $entries, int $room): array
{
    $saved  = [];
    $errors = [];

    foreach ($entries as $entry) {
        if (count($saved) >= $room) {
            $errors[] = 'A car can have ' . MAX_GALLERY_IMAGES
                      . ' extra photos, so some of the new ones were not added.';
            break;
        }
        $result = handle_upload($entry);
        if (!$result['ok']) {
            $errors[] = $entry['name'] . ': ' . $result['message'];
            continue;
        }
        if ($result['filename'] !== null) {
            $saved[] = $result['filename'];
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/** Append gallery rows for one car after its current last photo. */
function insert_gallery_rows(int $carId, array $fileNames): void
{
    if ($fileNames === []) {
        return;
    }

    $pdo = db();
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM car_images WHERE car_id = :id');
    $max->execute([':id' => $carId]);
    $sort = (int) $max->fetchColumn();

    $insert = $pdo->prepare(
        'INSERT INTO car_images (car_id, file_name, sort_order)
              VALUES (:car_id, :file_name, :sort_order)'
    );
    foreach ($fileNames as $fileName) {
        $insert->execute([':car_id' => $carId, ':file_name' => $fileName, ':sort_order' => ++$sort]);
    }
}

/**
 * Delete a file that a manager uploaded. The bundled photos in
 * images/cars are never touched, and basename() stops a stored name from
 * pointing outside the uploads folder.
 */
function delete_upload_file(string $fileName): void
{
    if ($fileName !== basename($fileName) || !str_starts_with($fileName, 'upload_')) {
        return;
    }
    if (is_file(UPLOAD_DIR . $fileName)) {
        @unlink(UPLOAD_DIR . $fileName);
    }
}

/** Replace the colour list of one car inside the junction table. */
function set_car_colors(int $carId, array $colorIds): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM car_colors WHERE car_id = :id')->execute([':id' => $carId]);

    if ($colorIds === []) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO car_colors (car_id, color_id) VALUES (:car_id, :color_id)'
    );
    foreach (array_unique(array_map('intval', $colorIds)) as $colorId) {
        if ($colorId > 0) {
            $insert->execute([':car_id' => $carId, ':color_id' => $colorId]);
        }
    }
}

/**
 * Task 9 - insert a new vehicle.
 *
 * @return array{ok: bool, message: string, car_id: int|null}
 */
function admin_create_car(array $input, ?array $file, ?array $galleryFiles = null): array
{
    $errors = validate_car_input($input);
    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors), 'car_id' => null];
    }

    $upload = handle_upload($file);
    if (!$upload['ok']) {
        return ['ok' => false, 'message' => $upload['message'], 'car_id' => null];
    }

    // Without a main picture the first gallery photo becomes the main one,
    // so one extra file fits.
    $room    = MAX_GALLERY_IMAGES + ($upload['filename'] === null ? 1 : 0);
    $gallery = save_gallery_uploads(gallery_files($galleryFiles), $room);

    // No main picture but some gallery photos: the first one becomes the main.
    $mainPicture = $upload['filename'];
    if ($mainPicture === null && $gallery['saved'] !== []) {
        $mainPicture = array_shift($gallery['saved']);
    }

    $framing = framing_from_input($input);
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO cars
                (car_name, picture, image_fit, image_pos_x, image_pos_y, image_zoom,
                 stock, price, brand_id, category_id, model_year,
                 engine, horsepower, transmission, fuel_type, seats, warranty_yrs,
                 description, is_featured)
             VALUES
                (:car_name, :picture, :image_fit, :image_pos_x, :image_pos_y, :image_zoom,
                 :stock, :price, :brand_id, :category_id, :model_year,
                 :engine, :horsepower, :transmission, :fuel_type, :seats, :warranty_yrs,
                 :description, :is_featured)'
        );

        $stmt->execute([
            ':car_name'     => trim((string) $input['car_name']),
            ':picture'      => $mainPicture ?? PLACEHOLDER_IMAGE,
            ':image_fit'    => $framing['fit'],
            ':image_pos_x'  => $framing['x'],
            ':image_pos_y'  => $framing['y'],
            ':image_zoom'   => $framing['zoom'],
            ':stock'        => (int) $input['stock'],
            ':price'        => (float) $input['price'],
            ':brand_id'     => (int) $input['brand_id'],
            ':category_id'  => (int) $input['category_id'],
            ':model_year'   => (int) $input['model_year'],
            ':engine'       => trim((string) $input['engine']),
            ':horsepower'   => (int) $input['horsepower'],
            ':transmission' => (string) ($input['transmission'] ?? 'Automatic'),
            ':fuel_type'    => (string) ($input['fuel_type'] ?? 'Petrol'),
            ':seats'        => (int) $input['seats'],
            ':warranty_yrs' => (int) ($input['warranty_yrs'] ?? 3),
            ':description'  => trim((string) ($input['description'] ?? '')),
            ':is_featured'  => empty($input['is_featured']) ? 0 : 1,
        ]);

        $carId = (int) $pdo->lastInsertId();
        set_car_colors($carId, (array) ($input['colors'] ?? []));
        insert_gallery_rows($carId, $gallery['saved']);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Nothing was stored, so the files saved for this request go too.
        delete_upload_file((string) $mainPicture);
        foreach ($gallery['saved'] as $fileName) {
            delete_upload_file($fileName);
        }
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Insert failed: ' . $ex->getMessage()
                                   : 'The vehicle could not be added.',
            'car_id'  => null,
        ];
    }

    // Outside the try block on purpose: the car is committed by now, and
    // the catch above would delete its photos.
    $aiNote = admin_refresh_ai_index();

    return [
        'ok'      => true,
        'message' => trim('Added "' . $input['car_name'] . '" to the showroom. '
                     . implode(' ', $gallery['errors']) . ' ' . $aiNote),
        'car_id'  => $carId,
    ];
}

/**
 * Task 10 - update an existing vehicle.
 * If no new picture is chosen the current one is kept. The same request
 * can also add gallery photos, remove some, or promote one of them to be
 * the main picture (the old main picture then takes its place in the
 * gallery). Gallery ids are always matched against this car's own rows,
 * so a tampered form cannot touch another car's photos.
 *
 * @return array{ok: bool, message: string}
 */
function admin_update_car(int $carId, array $input, ?array $file, ?array $galleryFiles = null): array
{
    $existing = get_car($carId);
    if ($existing === null) {
        return ['ok' => false, 'message' => 'That vehicle no longer exists.'];
    }

    $errors = validate_car_input($input);
    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $upload = handle_upload($file);
    if (!$upload['ok']) {
        return ['ok' => false, 'message' => $upload['message']];
    }

    // Current gallery of this car, keyed by image id.
    $galleryById = [];
    foreach (get_car_images($carId) as $row) {
        $galleryById[(int) $row['image_id']] = (string) $row['file_name'];
    }

    // A gallery photo chosen as the new main picture. A newly uploaded main
    // picture wins over this choice.
    $mainChoice = (int) ($input['main_image'] ?? 0);
    if ($upload['filename'] !== null || !isset($galleryById[$mainChoice])) {
        $mainChoice = 0;
    }

    // Photos to remove: only ids that belong to this car, and never the one
    // that is being promoted.
    // array_unique: a repeated id must not count as two removals.
    $removeIds = array_values(array_diff(
        array_unique(array_intersect(
            array_map('intval', (array) ($input['remove_images'] ?? [])),
            array_keys($galleryById)
        )),
        [$mainChoice]
    ));

    $oldMain          = (string) $existing['picture'];
    $oldIsPlaceholder = $oldMain === '' || $oldMain === PLACEHOLDER_IMAGE;

    // A car still on the placeholder takes its first new gallery photo as
    // the main picture, the same as when a car is created.
    $fillsMain = $oldIsPlaceholder && $upload['filename'] === null && $mainChoice === 0;

    $room = MAX_GALLERY_IMAGES - (count($galleryById) - count($removeIds));
    if ($oldIsPlaceholder && $mainChoice > 0) {
        $room++;                // the promoted row is deleted, not swapped
    }
    if ($fillsMain) {
        $room++;                // one new photo goes to the main picture
    }
    $gallery = save_gallery_uploads(gallery_files($galleryFiles), max(0, $room));

    $picture   = $upload['filename'] ?? $oldMain;
    $newMainUp = null;          // a new gallery file used as the main picture
    if ($mainChoice > 0) {
        $picture = $galleryById[$mainChoice];
    } elseif ($fillsMain && $gallery['saved'] !== []) {
        $newMainUp = array_shift($gallery['saved']);
        $picture   = $newMainUp;
    }

    $framing = framing_from_input($input);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE cars SET
                car_name     = :car_name,
                picture      = :picture,
                image_fit    = :image_fit,
                image_pos_x  = :image_pos_x,
                image_pos_y  = :image_pos_y,
                image_zoom   = :image_zoom,
                stock        = :stock,
                price        = :price,
                brand_id     = :brand_id,
                category_id  = :category_id,
                model_year   = :model_year,
                engine       = :engine,
                horsepower   = :horsepower,
                transmission = :transmission,
                fuel_type    = :fuel_type,
                seats        = :seats,
                warranty_yrs = :warranty_yrs,
                description  = :description,
                is_featured  = :is_featured
             WHERE car_id = :car_id'
        );

        $stmt->execute([
            ':car_name'     => trim((string) $input['car_name']),
            ':picture'      => $picture,
            ':image_fit'    => $framing['fit'],
            ':image_pos_x'  => $framing['x'],
            ':image_pos_y'  => $framing['y'],
            ':image_zoom'   => $framing['zoom'],
            ':stock'        => (int) $input['stock'],
            ':price'        => (float) $input['price'],
            ':brand_id'     => (int) $input['brand_id'],
            ':category_id'  => (int) $input['category_id'],
            ':model_year'   => (int) $input['model_year'],
            ':engine'       => trim((string) $input['engine']),
            ':horsepower'   => (int) $input['horsepower'],
            ':transmission' => (string) ($input['transmission'] ?? 'Automatic'),
            ':fuel_type'    => (string) ($input['fuel_type'] ?? 'Petrol'),
            ':seats'        => (int) $input['seats'],
            ':warranty_yrs' => (int) ($input['warranty_yrs'] ?? 3),
            ':description'  => trim((string) ($input['description'] ?? '')),
            ':is_featured'  => empty($input['is_featured']) ? 0 : 1,
            ':car_id'       => $carId,
        ]);

        set_car_colors($carId, (array) ($input['colors'] ?? []));

        // Promoting a gallery photo: the old main picture moves into its
        // slot, unless it was only the placeholder.
        if ($mainChoice > 0) {
            if ($oldIsPlaceholder) {
                $pdo->prepare('DELETE FROM car_images WHERE image_id = :id AND car_id = :car_id')
                    ->execute([':id' => $mainChoice, ':car_id' => $carId]);
            } else {
                $pdo->prepare('UPDATE car_images SET file_name = :file_name
                                WHERE image_id = :id AND car_id = :car_id')
                    ->execute([':file_name' => $oldMain, ':id' => $mainChoice, ':car_id' => $carId]);
            }
        }

        $remove = $pdo->prepare('DELETE FROM car_images WHERE image_id = :id AND car_id = :car_id');
        foreach ($removeIds as $imageId) {
            $remove->execute([':id' => $imageId, ':car_id' => $carId]);
        }

        insert_gallery_rows($carId, $gallery['saved']);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Nothing changed in the database, so drop the files saved for it.
        delete_upload_file((string) $upload['filename']);
        delete_upload_file((string) $newMainUp);
        foreach ($gallery['saved'] as $fileName) {
            delete_upload_file($fileName);
        }
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Update failed: ' . $ex->getMessage()
                                   : 'The vehicle could not be updated.',
        ];
    }

    // The database is updated; now remove the files nothing points to.
    if ($upload['filename'] !== null) {
        delete_upload_file($oldMain);               // replaced main picture
    }
    foreach ($removeIds as $imageId) {
        delete_upload_file($galleryById[$imageId]);
    }

    $message = 'Saved changes to "' . $input['car_name'] . '".';
    if ($newMainUp !== null) {
        $message .= ' The first new photo is now the main picture.';
    }
    if ($gallery['saved'] !== []) {
        $count    = count($gallery['saved']);
        $message .= ' Added ' . $count . ' gallery ' . ($count === 1 ? 'photo' : 'photos') . '.';
    }
    if ($removeIds !== []) {
        $count    = count($removeIds);
        $message .= ' Removed ' . $count . ' ' . ($count === 1 ? 'photo' : 'photos') . '.';
    }

    $aiNote = admin_refresh_ai_index();

    return ['ok' => true, 'message' => trim($message . ' ' . implode(' ', $gallery['errors']) . ' ' . $aiNote)];
}

/**
 * Task 10 - delete a vehicle.
 *
 * order_items.car_id is ON DELETE RESTRICT, so a car that has been sold
 * cannot be removed without losing the order history. The check below
 * catches that case and suggests setting the stock to zero instead.
 *
 * @return array{ok: bool, message: string}
 */
function admin_delete_car(int $carId): array
{
    $car = get_car($carId);
    if ($car === null) {
        return ['ok' => false, 'message' => 'That vehicle no longer exists.'];
    }

    // Check first, so the manager sees a useful message instead of a raw
    // foreign key error.
    $sold = db()->prepare(
        'SELECT COUNT(*) FROM order_items WHERE car_id = :id'
    );
    $sold->execute([':id' => $carId]);

    if ((int) $sold->fetchColumn() > 0) {
        return [
            'ok'      => false,
            'message' => 'The ' . $car['car_name'] . ' appears in past orders, so it cannot '
                         . 'be deleted without destroying the sales history. '
                         . 'Set its stock to 0 instead to hide it from customers.',
        ];
    }

    $galleryNames = array_column(get_car_images($carId), 'file_name');

    try {
        // car_colors and car_images are ON DELETE CASCADE, so their rows go
        // with the car.
        db()->prepare('DELETE FROM cars WHERE car_id = :id')->execute([':id' => $carId]);

        delete_upload_file((string) $car['picture']);
        foreach ($galleryNames as $fileName) {
            delete_upload_file((string) $fileName);
        }

        // admin_refresh_ai_index() never throws, so it is safe inside the try.
        $aiNote = admin_refresh_ai_index();

        return ['ok' => true, 'message' => trim('Deleted "' . $car['car_name'] . '". ' . $aiNote)];
    } catch (Throwable $ex) {
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Delete failed: ' . $ex->getMessage()
                                   : 'The vehicle could not be deleted.',
        ];
    }
}

/** Colour ids currently attached to a car, for pre-ticking the edit form. */
function get_car_color_ids(int $carId): array
{
    $stmt = db()->prepare('SELECT color_id FROM car_colors WHERE car_id = :id');
    $stmt->execute([':id' => $carId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Sales value and units in stock for every brand, used by the dashboard
 * charts. Brands with no sales still appear, with a value of 0.
 */
function get_brand_figures(): array
{
    return db()->query(
        'SELECT b.brand_name,
                COALESCE((SELECT SUM(oi.quantity * oi.unit_price)
                            FROM order_items oi
                            JOIN cars c2 ON c2.car_id = oi.car_id
                           WHERE c2.brand_id = b.brand_id), 0) AS sales,
                COALESCE((SELECT SUM(c3.stock) FROM cars c3
                           WHERE c3.brand_id = b.brand_id), 0) AS stock
           FROM brands b
       ORDER BY b.brand_name'
    )->fetchAll();
}
