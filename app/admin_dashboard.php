<?php
/**
 * AutoSouq - manager dashboard.
 *
 * Shows stock and order figures. require_admin() runs before any output,
 * so a guest is redirected before the page is generated.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/admin_dashboard.php
 * @task    Task 8 (security)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';

require_admin();   // nothing below runs for a guest

require_once __DIR__ . '/includes/cart.php';

/* AI assistant: a full rebuild of its search index, by hand. Useful after
 * adding the API keys, since the automatic refresh after a car or article
 * change only re-embeds what changed. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (in_array(str_input('action'), ['confirm_drive','cancel_drive'], true)) {
        require_once __DIR__ . '/includes/customer_showroom.php';
        update_test_drive(int_input('drive_id'), str_input('action'), null);
        redirect('admin_dashboard.php#test-drives');
    }
    if (str_input('action') === 'rebuild_index') {
        try {
            $rebuild = kb_rebuild_index(true);
            flash($rebuild['errors'] > 0 ? 'warning' : 'success', kb_rebuild_message($rebuild));
        } catch (Throwable $ex) {
            error_log('AutoSouq: AI index rebuild failed: ' . $ex->getMessage());
            flash('error', APP_DEBUG ? 'The AI index could not be rebuilt: ' . $ex->getMessage()
                                     : 'The AI index could not be rebuilt. Please try again later.');
        }
    }
    redirect('admin_dashboard.php');
}

// The kb tables come from database/upgrade_v3.sql. If they are missing the
// card explains that instead of breaking the whole dashboard.
$aiStatus = ai_status();
$kbStatus = null;
try {
    $kbStatus = kb_index_status();
} catch (Throwable $ex) {
    $aiStatus = [
        'mode'   => 'off',
        'label'  => 'Off',
        'reason' => 'The knowledge base tables are missing from the database. Import '
                  . 'database/upgrade_v3.sql, then rebuild the index.',
    ];
}

$stats        = get_dashboard_stats();
$lowStock     = get_low_stock_cars();
$recentOrders = get_recent_orders();
$brandFigures = get_brand_figures();

// Passed to js/dashboard-charts.js through a data attribute, so the page
// needs no inline script (the Content-Security-Policy blocks inline scripts).
$chartData = json_encode([
    'brands' => array_column($brandFigures, 'brand_name'),
    'sales'  => array_map('floatval', array_column($brandFigures, 'sales')),
    'stock'  => array_map('intval', array_column($brandFigures, 'stock')),
]);

$pageTitle       = 'Dashboard';
$pageDescription = 'Showroom overview for AutoSouq managers.';
$bodyClass       = 'page-admin page-dashboard';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head page-head-admin">
        <div>
            <p class="admin-eyebrow">Manager area</p>
            <h1>Good to see you, <?= e(admin_name()) ?></h1>
            <p class="page-lead">
                Here is where the showroom stands right now.
            </p>
        </div>
        <p class="page-head-actions">
            <a class="btn btn-primary" href="admin_manage.php?mode=add">Add a vehicle</a>
            <a class="btn btn-quiet" href="admin_manage.php">Manage vehicles</a>
            <a class="btn btn-quiet" href="#test-drives">Test drives</a>
        </p>
    </header>

    <section aria-labelledby="stats-heading">
        <h2 id="stats-heading" class="sr-only">Key numbers</h2>
        <ul class="stat-grid">
            <li class="stat">
                <span class="stat-value"><?= (int) $stats['cars'] ?></span>
                <span class="stat-label">models listed</span>
            </li>
            <li class="stat">
                <span class="stat-value"><?= (int) $stats['units'] ?></span>
                <span class="stat-label">units on the floor</span>
            </li>
            <li class="stat">
                <span class="stat-value"><?= (int) $stats['brands'] ?></span>
                <span class="stat-label">brands represented</span>
            </li>
            <li class="stat">
                <span class="stat-value"><?= (int) $stats['orders'] ?></span>
                <span class="stat-label">orders placed</span>
            </li>
            <li class="stat stat-wide">
                <span class="stat-value"><?= e(money((float) $stats['revenue'])) ?></span>
                <span class="stat-label">total sales value</span>
            </li>
            <li class="stat stat-wide">
                <span class="stat-value"><?= e(money((float) $stats['stock_value'])) ?></span>
                <span class="stat-label">value of unsold stock</span>
            </li>
        </ul>
    </section>

    <!-- AI assistant: which mode the chat runs in, and what its index holds -->
    <?php
    require_once __DIR__ . '/includes/customer_showroom.php';
    $manageDrives = true;
    $drives = list_test_drives(null);
    require __DIR__ . '/includes/test_drive_list.php';
    ?>
    <section class="panel ai-panel" aria-labelledby="ai-heading">
        <div class="ai-panel-head">
            <div>
                <h2 id="ai-heading">AI assistant</h2>
                <p class="panel-sub">The chat window customers see on every shop page.</p>
            </div>
            <span class="ai-mode ai-mode-<?= e($aiStatus['mode']) ?>">
                <span class="sr-only">Mode: </span><?= e($aiStatus['label']) ?>
            </span>
        </div>

        <p class="ai-reason"><?= e($aiStatus['reason']) ?></p>

        <?php if ($kbStatus !== null): ?>
            <dl class="ai-facts">
                <div>
                    <dt>Cars indexed</dt>
                    <dd><?= (int) $kbStatus['cars'] ?></dd>
                </div>
                <div>
                    <dt>Articles indexed</dt>
                    <dd><?= (int) $kbStatus['articles'] ?></dd>
                </div>
                <div>
                    <dt>Index last changed</dt>
                    <dd>
                        <?php if ($kbStatus['last_updated'] === null): ?>
                            Not built yet
                        <?php else: ?>
                            <time datetime="<?= e(date('c', strtotime($kbStatus['last_updated']))) ?>">
                                <?= e(date('j M Y, H:i', strtotime($kbStatus['last_updated']))) ?>
                            </time>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>

            <?php if (embeddings_enabled() && $kbStatus['missing_vectors'] > 0): ?>
                <p class="ai-note">
                    <?= (int) $kbStatus['missing_vectors'] ?>
                    <?= (int) $kbStatus['missing_vectors'] === 1 ? 'entry has' : 'entries have' ?>
                    no embedding yet, so keyword search is used for
                    <?= (int) $kbStatus['missing_vectors'] === 1 ? 'it' : 'them' ?>.
                    Rebuilding the index fixes this.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="ai-actions">
            <form method="post" action="admin_dashboard.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rebuild_index">
                <button class="btn btn-secondary btn-sm" type="submit"
                        aria-describedby="ai-rebuild-hint"
                        <?= $aiStatus['mode'] === 'off' ? 'disabled' : '' ?>>
                    Rebuild AI index
                </button>
            </form>
            <a class="btn btn-quiet btn-sm" href="admin_knowledge.php">Edit the knowledge base</a>
        </div>
        <p class="field-hint" id="ai-rebuild-hint">
            The index also refreshes by itself whenever a car or an article changes.
        </p>
    </section>

    <div class="dashboard-columns">

        <section class="panel" aria-labelledby="low-heading">
            <h2 id="low-heading">Needs restocking</h2>
            <p class="panel-sub">
                <?= LOW_STOCK_THRESHOLD ?> units or fewer remaining.
            </p>

            <?php if ($lowStock === []): ?>
                <p class="empty-state">Every model is comfortably in stock.</p>
            <?php else: ?>
                <ul class="mini-list">
                    <?php foreach ($lowStock as $car): ?>
                        <li>
                            <img src="<?= e(car_image($car['picture'])) ?>" alt=""
                                 width="64" height="40" loading="lazy">
                            <span class="mini-list-text">
                                <a href="admin_manage.php?mode=edit&amp;id=<?= (int) $car['car_id'] ?>">
                                    <?= e($car['car_name']) ?>
                                </a>
                                <small><?= e($car['brand_name']) ?></small>
                            </span>
                            <span class="pill <?= (int) $car['stock'] === 0 ? 'pill-danger' : 'pill-warn' ?>">
                                <?= (int) $car['stock'] ?> left
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="orders-heading">
            <h2 id="orders-heading">Latest orders</h2>
            <p class="panel-sub">Newest first.</p>

            <?php if ($recentOrders === []): ?>
                <p class="empty-state">No orders have been placed yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <caption class="sr-only">The most recent customer orders</caption>
                    <thead>
                        <tr>
                            <th scope="col">Order</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Units</th>
                            <th scope="col">Value</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <th scope="row">
                                    #<?= (int) $order['order_id'] ?>
                                    <small>
                                        <?= e(date('j M Y', strtotime((string) $order['order_date']))) ?>
                                    </small>
                                </th>
                                <td data-label="Customer">
                                    <?= e($order['full_name']) ?>
                                    <small><?= e($order['city']) ?></small>
                                </td>
                                <td data-label="Units"><?= (int) $order['units'] ?></td>
                                <td data-label="Value">
                                    <?= e(money((float) $order['total_amount'])) ?>
                                </td>
                                <td data-label="Status">
                                    <span class="badge badge-<?= e(strtolower((string) $order['status'])) ?>">
                                        <?= e($order['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    </div>

    <div class="dashboard-columns chart-panel">
        <section class="panel" aria-labelledby="sales-chart-heading">
            <h2 id="sales-chart-heading">Sales by brand</h2>
            <p class="panel-sub">Total value of every order, in SAR.</p>
            <div class="chart-box">
                <canvas id="sales-chart" data-chart="<?= e((string) $chartData) ?>"
                        role="img" aria-label="Bar chart of sales value for each brand"></canvas>
            </div>
        </section>
        <section class="panel" aria-labelledby="stock-chart-heading">
            <h2 id="stock-chart-heading">Stock by brand</h2>
            <p class="panel-sub">Units on the showroom floor right now.</p>
            <div class="chart-box">
                <canvas id="stock-chart" role="img" aria-label="Doughnut chart of units in stock for each brand"></canvas>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
