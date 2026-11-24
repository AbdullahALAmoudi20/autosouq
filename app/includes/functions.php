<?php
/**
 * AutoSouq shared helpers.
 *
 * Section A holds presentation helpers (escaping, money formatting,
 * redirects, flash messages, CSRF tokens, image paths). Section B holds the
 * SELECT queries the pages need. Nothing here echoes HTML, the pages do that.
 *
 * @author  Nasser Alshammari (Database Developer)
 * @coauthor Abdullah Alamoudi (Back-End) - added the cart-facing query helpers
 * @file    app/includes/functions.php
 * @task    Task 16
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';


/* SECTION A: PRESENTATION HELPERS */

/**
 * Escape a value for output inside HTML. Every database value printed by
 * the project goes through here, which is what stops XSS.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a price, e.g. 112000 becomes "SAR 112,000". */
function money(float $amount): string
{
    return CURRENCY . ' ' . number_format($amount, 0, '.', ',');
}

/** Same, but with decimals, for the invoice lines. */
function money_exact(float $amount): string
{
    return CURRENCY . ' ' . number_format($amount, 2, '.', ',');
}

/**
 * Build the path to a car picture from the file name held in the database.
 * Names starting with "upload_" were added by an admin, so they sit in
 * images/uploads/ rather than images/cars/.
 */
function car_image(?string $fileName): string
{
    $fileName = trim((string) $fileName);
    if ($fileName === '') {
        return IMG_CARS . PLACEHOLDER_IMAGE;
    }
    if (str_starts_with($fileName, 'upload_')) {
        return IMG_UPLOADS . rawurlencode($fileName);
    }
    return IMG_CARS . rawurlencode($fileName);
}

/**
 * Author and licence of a car photo, read from images/cars/credits.json.
 * The photos come from Wikimedia Commons under Creative Commons licences,
 * which require the author to be credited next to the picture.
 * Returns null for pictures with no entry, such as manager uploads.
 */
function photo_credit(string $fileName): ?array
{
    static $credits = null;
    if ($credits === null) {
        $file = __DIR__ . '/../' . IMG_CARS . 'credits.json';
        $credits = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }
    return $credits[$fileName] ?? null;
}

/** Limits for the picture framing the manager sets on the add / edit form. */
const IMAGE_ZOOM_MIN = 100;
const IMAGE_ZOOM_MAX = 250;

/**
 * The framing a manager chose for a car's main picture, clamped to safe
 * values. Rows from queries that do not select the framing columns get
 * the defaults (fill the frame, centred, no zoom).
 *
 * @return array{fit: string, x: int, y: int, zoom: int}
 */
function image_framing(array $car): array
{
    $fit = ($car['image_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
    return [
        'fit'  => $fit,
        'x'    => max(0, min(100, (int) ($car['image_pos_x'] ?? 50))),
        'y'    => max(0, min(100, (int) ($car['image_pos_y'] ?? 50))),
        'zoom' => max(IMAGE_ZOOM_MIN, min(IMAGE_ZOOM_MAX, (int) ($car['image_zoom'] ?? 100))),
    ];
}

/**
 * Inline style that applies the framing to an <img>. Every value is an
 * integer clamped by image_framing(), so nothing from the request can end
 * up in the style attribute. The zoom goes through a CSS variable because
 * the card hover effect multiplies it (see .framed-img in style.css).
 */
function car_image_style(array $car): string
{
    $f = image_framing($car);
    return sprintf(
        'object-fit:%s;object-position:%d%% %d%%;transform-origin:%d%% %d%%;--zoom:%.2F',
        $f['fit'], $f['x'], $f['y'], $f['x'], $f['y'], $f['zoom'] / 100
    );
}

/**
 * Extra gallery photos of one car, in display order. The main picture is
 * not included; it stays in cars.picture.
 *
 * @return array<int, array{image_id: int, file_name: string}>
 */
function get_car_images(int $carId): array
{
    $stmt = db()->prepare(
        'SELECT image_id, file_name
           FROM car_images
          WHERE car_id = :id
       ORDER BY sort_order ASC, image_id ASC'
    );
    $stmt->execute([':id' => $carId]);
    return $stmt->fetchAll();
}

/**
 * Link to a CSS or JS file with its last-modified time added, e.g.
 * css/style.css?v=1762851234. Browsers and the host cache these files for
 * a long time, so without this a visitor could keep running an old copy
 * after the file is updated. The path is always one of ours, never input.
 */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
}

/** Same for a brand logo. */
function brand_logo(?string $fileName): string
{
    $fileName = trim((string) $fileName);
    return $fileName === ''
        ? IMG_BRANDS . PLACEHOLDER_IMAGE
        : IMG_BRANDS . rawurlencode($fileName);
}

/** Send a Location header and stop the script. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Queue a message that survives one redirect.
 * $type is success, error, info or warning.
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Return the pending flash messages and clear them. */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Per-session CSRF token. Only generated when missing, otherwise opening a
 * second tab would invalidate the form in the first one.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for the token, included in every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Check the posted token. A mismatch means the request did not come from
 * one of our own forms, so the script stops.
 */
function csrf_check(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(403);
        die('Security check failed. Please go back, reload the page and try again.');
    }
}

/** True when $file is the running script. Used to highlight the nav link. */
function is_page(string $file): bool
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $file;
}

/** True on the manager pages, where the shop widgets are not shown. */
function is_admin_page(): bool
{
    return str_starts_with(basename($_SERVER['PHP_SELF'] ?? ''), 'admin_');
}

/** Read an integer from GET or POST, clamped between $min and $max. */
function int_input(string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

/** Read and trim a string from GET or POST. */
function str_input(string $key, string $default = ''): string
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($raw) ? trim($raw) : $default;
}

/**
 * Read a POST value exactly as typed, with no trim. Used for passwords,
 * where spaces count. A field sent as an array (password[]=x) gives '',
 * instead of an "Array to string conversion" warning on the page.
 */
function raw_input(string $key): string
{
    $raw = $_POST[$key] ?? '';
    return is_string($raw) ? $raw : '';
}


/* SECTION B: DATA ACCESS
 * Every query below uses a prepared statement. No user value is
 * concatenated into an SQL string. */

/** All brands, alphabetical. Used by the filter bar and the admin form. */
function get_brands(): array
{
    return db()->query(
        'SELECT brand_id, brand_name, country, logo, founded
           FROM brands
       ORDER BY brand_name'
    )->fetchAll();
}

/** All body-style categories. */
function get_categories(): array
{
    return db()->query(
        'SELECT category_id, category_name, description
           FROM categories
       ORDER BY category_name'
    )->fetchAll();
}

/** All colours in the catalogue. */
function get_colors(): array
{
    return db()->query(
        'SELECT color_id, color_name, hex_code FROM colors ORDER BY color_name'
    )->fetchAll();
}

/**
 * Catalogue query behind products.php (Task 3).
 *
 * @param array $filters brand_id, category_id, keyword, sort, featured_only,
 *                       in_stock, limit
 * @return array car rows with brand_name and category_name joined in
 */
function get_cars(array $filters = []): array
{
    $sql = 'SELECT c.car_id, c.car_name, c.picture, c.image_fit, c.image_pos_x,
                   c.image_pos_y, c.image_zoom, c.stock, c.price,
                   c.model_year, c.engine, c.horsepower, c.transmission,
                   c.fuel_type, c.seats, c.warranty_yrs, c.description,
                   c.is_featured, c.brand_id, c.category_id,
                   b.brand_name, b.logo AS brand_logo,
                   cat.category_name
              FROM cars c
              JOIN brands b       ON b.brand_id     = c.brand_id
              JOIN categories cat ON cat.category_id = c.category_id
             WHERE 1 = 1';

    $params = [];

    if (!empty($filters['brand_id'])) {
        $sql .= ' AND c.brand_id = :brand_id';
        $params[':brand_id'] = (int) $filters['brand_id'];
    }
    if (!empty($filters['category_id'])) {
        $sql .= ' AND c.category_id = :category_id';
        $params[':category_id'] = (int) $filters['category_id'];
    }
    if (!empty($filters['keyword'])) {
        $sql .= ' AND (c.car_name          LIKE :kw_name
                       OR b.brand_name     LIKE :kw_brand
                       OR cat.category_name LIKE :kw_cat)';

        // Three separate placeholders for the same term. Emulated prepares
        // are off, and a real server-side prepared statement cannot reuse one
        // named placeholder, so a single :kw would fail with
        // SQLSTATE[HY093] Invalid parameter number.
        $term = '%' . $filters['keyword'] . '%';
        $params[':kw_name']  = $term;
        $params[':kw_brand'] = $term;
        $params[':kw_cat']   = $term;
    }
    if (!empty($filters['featured_only'])) {
        $sql .= ' AND c.is_featured = 1';
    }
    if (!empty($filters['in_stock'])) {
        $sql .= ' AND c.stock > 0';
    }

    // ORDER BY cannot be a bound parameter, so the sort option is looked up
    // in this list instead of being taken from the query string.
    $sortMap = [
        'price_asc'  => 'c.price ASC',
        'price_desc' => 'c.price DESC',
        'name'       => 'c.car_name ASC',
        'newest'     => 'c.model_year DESC, c.car_id DESC',
        'brand'      => 'b.brand_name ASC, c.car_name ASC',
    ];
    $requested = $filters['sort'] ?? '';
    $sql .= ' ORDER BY ' . ($sortMap[$requested] ?? 'b.brand_name ASC, c.car_name ASC');

    if (!empty($filters['limit'])) {
        $sql .= ' LIMIT ' . (int) $filters['limit'];   // cast to int, never raw input
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** One car with its brand and category (Task 4). Null if the id is unknown. */
function get_car(int $carId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*, b.brand_name, b.country AS brand_country, b.logo AS brand_logo,
                cat.category_name, cat.description AS category_description
           FROM cars c
           JOIN brands b       ON b.brand_id     = c.brand_id
           JOIN categories cat ON cat.category_id = c.category_id
          WHERE c.car_id = :id'
    );
    $stmt->execute([':id' => $carId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Colours one car is available in, read through the junction table. */
function get_car_colors(int $carId): array
{
    $stmt = db()->prepare(
        'SELECT co.color_id, co.color_name, co.hex_code
           FROM car_colors cc
           JOIN colors co ON co.color_id = cc.color_id
          WHERE cc.car_id = :id
       ORDER BY co.color_name'
    );
    $stmt->execute([':id' => $carId]);
    return $stmt->fetchAll();
}

/** Other cars from the same brand, shown under the car details page. */
function get_related_cars(int $carId, int $brandId, int $limit = 3): array
{
    $stmt = db()->prepare(
        'SELECT c.car_id, c.car_name, c.picture, c.image_fit, c.image_pos_x,
                c.image_pos_y, c.image_zoom, c.price, c.stock, b.brand_name
           FROM cars c
           JOIN brands b ON b.brand_id = c.brand_id
          WHERE c.brand_id = :brand AND c.car_id <> :id AND c.stock > 0
       ORDER BY RAND()
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':brand' => $brandId, ':id' => $carId]);
    return $stmt->fetchAll();
}

/** Current stock for one car, checked before adding to the cart (Task 5). */
function get_stock(int $carId): int
{
    $stmt = db()->prepare('SELECT stock FROM cars WHERE car_id = :id');
    $stmt->execute([':id' => $carId]);
    $stock = $stmt->fetchColumn();
    return $stock === false ? 0 : (int) $stock;
}

/**
 * Load orders with their items. index.php calls this with the ids kept in
 * the past-purchases cookie (Task 12).
 *
 * @param int[] $orderIds
 */
function get_orders_by_ids(array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if ($orderIds === []) {
        return [];
    }

    // One "?" per id, so IN (...) still goes through a prepared statement.
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = db()->prepare(
        "SELECT o.order_id, o.order_date, o.total_amount, o.status,
                cu.full_name AS customer_name
           FROM orders o
           JOIN customers cu ON cu.customer_id = o.customer_id
          WHERE o.order_id IN ($placeholders)
       ORDER BY o.order_date DESC"
    );
    $stmt->execute($orderIds);
    $orders = $stmt->fetchAll();

    if ($orders === []) {
        return [];
    }

    $itemStmt = db()->prepare(
        "SELECT oi.order_id, oi.quantity, oi.unit_price,
                c.car_id, c.car_name, c.picture
           FROM order_items oi
           JOIN cars c ON c.car_id = oi.car_id
          WHERE oi.order_id IN ($placeholders)
       ORDER BY oi.order_item_id"
    );
    $itemStmt->execute($orderIds);

    // Attach each line item to its order.
    $itemsByOrder = [];
    foreach ($itemStmt->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }
    foreach ($orders as &$order) {
        $order['items'] = $itemsByOrder[(int) $order['order_id']] ?? [];
    }
    unset($order);

    return $orders;
}

/** Totals shown across the top of the admin dashboard. */
function get_dashboard_stats(): array
{
    $pdo = db();
    return [
        'cars'        => (int)   $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn(),
        'units'       => (int)   $pdo->query('SELECT COALESCE(SUM(stock),0) FROM cars')->fetchColumn(),
        'brands'      => (int)   $pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn(),
        'orders'      => (int)   $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'customers'   => (int)   $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'revenue'     => (float) $pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM orders')->fetchColumn(),
        'stock_value' => (float) $pdo->query('SELECT COALESCE(SUM(stock * price),0) FROM cars')->fetchColumn(),
    ];
}

/** Cars at or below LOW_STOCK_THRESHOLD, so managers can reorder. */
function get_low_stock_cars(): array
{
    $stmt = db()->prepare(
        'SELECT c.car_id, c.car_name, c.stock, c.picture, b.brand_name
           FROM cars c
           JOIN brands b ON b.brand_id = c.brand_id
          WHERE c.stock <= :threshold
       ORDER BY c.stock ASC, c.car_name ASC'
    );
    $stmt->execute([':threshold' => LOW_STOCK_THRESHOLD]);
    return $stmt->fetchAll();
}

/** The most recent orders, for the dashboard table. */
function get_recent_orders(int $limit = 8): array
{
    $stmt = db()->prepare(
        'SELECT o.order_id, o.order_date, o.total_amount, o.status,
                cu.full_name, cu.city,
                (SELECT COALESCE(SUM(oi.quantity),0)
                   FROM order_items oi
                  WHERE oi.order_id = o.order_id) AS units
           FROM orders o
           JOIN customers cu ON cu.customer_id = o.customer_id
       ORDER BY o.order_date DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}
