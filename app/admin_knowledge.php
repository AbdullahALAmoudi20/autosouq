<?php
/**
 * AutoSouq - knowledge base for the AI assistant (managers only).
 *
 * Managers add, edit, hide and delete the help articles the chat answers
 * from (warranty, financing, delivery and so on). The cars themselves are
 * indexed straight from the cars table, so they are not edited here.
 * ?mode=list|add|edit picks the view, the same way admin_manage.php does.
 * The database work is in includes/knowledge.php.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/admin_knowledge.php
 * @task    Extra: AI assistant knowledge base
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';

require_admin();   // nothing below runs for a guest

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/knowledge.php';

/* 1. Handle the POST actions, then redirect (POST-redirect-GET). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = str_input('action');
    $faqId  = int_input('faq_id');

    if ($action === 'save') {
        $result = kb_save_article($faqId > 0 ? $faqId : null, $_POST);
        if (!$result['ok']) {
            // Keep what the manager typed, so one mistake does not throw
            // away a long article.
            $_SESSION['kb_form'] = [
                'faq_id'    => $faqId,
                'title'     => str_input('title'),
                'body'      => str_input('body'),
                'is_active' => empty($_POST['is_active']) ? 0 : 1,
            ];
            flash('error', $result['message']);
            redirect($faqId > 0 ? 'admin_knowledge.php?mode=edit&id=' . $faqId : 'admin_knowledge.php?mode=add');
        }
        flash('success', $result['message']);
        kb_refresh_after_change();
        redirect('admin_knowledge.php');
    }

    if ($action === 'toggle') {
        $result = kb_set_active($faqId, str_input('active') === '1');
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok']) {
            kb_refresh_after_change();
        }
        redirect('admin_knowledge.php');
    }

    if ($action === 'delete') {
        $result = kb_delete_article($faqId);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok']) {
            kb_refresh_after_change();
        }
        redirect('admin_knowledge.php');
    }

    redirect('admin_knowledge.php');
}

/* 2. Work out what to show. */
$mode = str_input('mode', 'list');
if (!in_array($mode, ['list', 'add', 'edit'], true)) {
    $mode = 'list';
}

$editing = null;
if ($mode === 'edit') {
    $editing = get_kb_article(int_input('id'));
    if ($editing === null) {
        flash('error', 'That article no longer exists.');
        redirect('admin_knowledge.php');
    }
}

// Input kept from a failed save, used once and only on the same form.
$old = $_SESSION['kb_form'] ?? null;
unset($_SESSION['kb_form']);
$editId = $editing !== null ? (int) $editing['faq_id'] : 0;
if (!is_array($old) || (int) ($old['faq_id'] ?? 0) !== $editId || $mode === 'list') {
    $old = null;
}

$formTitle  = (string) ($old['title'] ?? $editing['title'] ?? '');
$formBody   = (string) ($old['body'] ?? $editing['body'] ?? '');
$formActive = (int) ($old['is_active'] ?? $editing['is_active'] ?? 1) === 1;

$articles = $mode === 'list' ? get_kb_articles() : [];
$activeCount = count(array_filter($articles, static fn (array $a): bool => (int) $a['is_active'] === 1));

// The index summary is a nice-to-have, so a problem with it never stops the page.
$indexStatus = null;
if ($mode === 'list') {
    try {
        $indexStatus = kb_index_status();
    } catch (Throwable $ex) {
        $indexStatus = null;
    }
}

$pageTitle       = $mode === 'add' ? 'Add an article'
                 : ($mode === 'edit' ? 'Edit article' : 'Knowledge base');
$pageDescription = 'Help articles the AutoSouq assistant answers from.';
$bodyClass       = 'page-admin page-knowledge';

require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <header class="page-head page-head-admin">
        <div>
            <p class="admin-eyebrow">Manager area</p>
            <h1><?= e($pageTitle) ?></h1>
            <p class="page-lead">
                <?php if ($mode === 'list'): ?>
                    The chat assistant answers from these articles and from the cars in the showroom.
                <?php elseif ($mode === 'edit'): ?>
                    Editing <strong><?= e((string) $editing['title']) ?></strong>.
                <?php else: ?>
                    Write one topic per article, the way you would explain it to a customer.
                <?php endif; ?>
            </p>
        </div>
        <p class="page-head-actions">
            <?php if ($mode === 'list'): ?>
                <a class="btn btn-primary" href="admin_knowledge.php?mode=add">Add an article</a>
            <?php else: ?>
                <a class="btn btn-quiet" href="admin_knowledge.php">Back to the list</a>
            <?php endif; ?>
            <a class="btn btn-quiet" href="admin_dashboard.php">Dashboard</a>
        </p>
    </header>

<?php if ($mode === 'list'): ?>

    <p class="result-count" role="status">
        <?= count($articles) ?> <?= count($articles) === 1 ? 'article' : 'articles' ?>,
        <?= $activeCount ?> used by the assistant.
        <?php if ($indexStatus !== null): ?>
            The assistant's index holds <?= (int) $indexStatus['articles'] ?>
            <?= (int) $indexStatus['articles'] === 1 ? 'article' : 'articles' ?> and
            <?= (int) $indexStatus['cars'] ?> <?= (int) $indexStatus['cars'] === 1 ? 'car' : 'cars' ?>.
        <?php endif; ?>
    </p>

    <?php if ($articles === []): ?>
        <div class="empty-state empty-state-lg">
            <h2>No articles yet</h2>
            <p>The assistant can still answer questions about the cars.
               <a href="admin_knowledge.php?mode=add">Add the first article</a>
               to cover topics such as warranty or delivery.</p>
        </div>
    <?php else: ?>
        <table class="data-table data-table-manage kb-table">
            <caption class="sr-only">Help articles, with edit, hide and delete controls</caption>
            <thead>
                <tr>
                    <th scope="col">Article</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last updated</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($articles as $article): ?>
                <?php
                $articleId = (int) $article['faq_id'];
                $isActive  = (int) $article['is_active'] === 1;
                ?>
                <tr class="<?= $isActive ? '' : 'kb-row-hidden' ?>">
                    <th scope="row" class="kb-cell-title">
                        <span>
                            <a href="admin_knowledge.php?mode=edit&amp;id=<?= $articleId ?>">
                                <?= e($article['title']) ?>
                            </a>
                            <small><?= e(mb_strimwidth((string) $article['body'], 0, 110, '...')) ?></small>
                        </span>
                    </th>
                    <td data-label="Status">
                        <span class="kb-state <?= $isActive ? 'is-on' : 'is-off' ?>">
                            <?= $isActive ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td data-label="Last updated">
                        <time datetime="<?= e(date('c', strtotime((string) $article['updated_at']))) ?>">
                            <?= e(date('j M Y', strtotime((string) $article['updated_at']))) ?>
                        </time>
                    </td>
                    <td data-label="Actions" class="cell-actions kb-actions">
                        <a class="btn btn-quiet btn-sm"
                           href="admin_knowledge.php?mode=edit&amp;id=<?= $articleId ?>">
                            Edit<span class="sr-only"> <?= e($article['title']) ?></span>
                        </a>

                        <form method="post" action="admin_knowledge.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="faq_id" value="<?= $articleId ?>">
                            <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                            <button class="btn btn-quiet btn-sm" type="submit">
                                <?= $isActive ? 'Hide' : 'Enable' ?><span class="sr-only"> <?= e($article['title']) ?></span>
                            </button>
                        </form>

                        <form method="post" action="admin_knowledge.php"
                              data-confirm="Delete the article &quot;<?= e($article['title']) ?>&quot; permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="faq_id" value="<?= $articleId ?>">
                            <button class="btn btn-danger btn-sm" type="submit">
                                Delete<span class="sr-only"> <?= e($article['title']) ?></span>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else: ?>

    <form class="kb-form" method="post" action="admin_knowledge.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing !== null): ?>
            <input type="hidden" name="faq_id" value="<?= (int) $editing['faq_id'] ?>">
        <?php endif; ?>

        <div class="kb-form-grid">
            <fieldset class="form-block">
                <legend>Article</legend>

                <div class="field">
                    <label for="kb-title">Title <span class="req">*</span></label>
                    <input type="text" id="kb-title" name="title"
                           minlength="<?= KB_TITLE_MIN ?>" maxlength="<?= KB_TITLE_MAX ?>"
                           value="<?= e($formTitle) ?>"
                           aria-describedby="kb-title-hint" required>
                    <p class="field-hint" id="kb-title-hint">
                        <?= KB_TITLE_MIN ?> to <?= KB_TITLE_MAX ?> characters. Use the words a customer
                        would type, e.g. "Delivery outside Riyadh".
                    </p>
                </div>

                <div class="field">
                    <label for="kb-body">Article text <span class="req">*</span></label>
                    <textarea id="kb-body" name="body" rows="10"
                              minlength="<?= KB_BODY_MIN ?>" maxlength="<?= KB_BODY_MAX ?>"
                              aria-describedby="kb-body-hint" required><?= e($formBody) ?></textarea>
                    <p class="field-hint" id="kb-body-hint">
                        <?= KB_BODY_MIN ?> to <?= e(number_format(KB_BODY_MAX)) ?> characters.
                        Plain sentences with the real figures (prices in SAR, days, years).
                        The assistant only repeats what is written here, so keep it accurate.
                    </p>
                </div>

                <div class="field field-check">
                    <input type="checkbox" id="kb-active" name="is_active" value="1"
                           <?= $formActive ? 'checked' : '' ?>>
                    <label for="kb-active">Let the assistant use this article</label>
                </div>
            </fieldset>

            <aside class="kb-tips" aria-labelledby="kb-tips-heading">
                <h2 id="kb-tips-heading">Writing tips</h2>
                <ul>
                    <li>One topic per article works best: the assistant picks the closest
                        few articles for each question.</li>
                    <li>Write the facts in full sentences. "Delivery in Riyadh is free" is
                        easier to find than a bare "Free".</li>
                    <li>Car prices and stock come from the vehicle list, so there is no need
                        to repeat them here.</li>
                    <li>Hide an article instead of deleting it when a policy is only paused.</li>
                </ul>
            </aside>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary btn-lg" type="submit">
                <?= $editing !== null ? 'Save changes' : 'Add this article' ?>
            </button>
            <a class="btn btn-quiet btn-lg" href="admin_knowledge.php">Cancel</a>
        </div>
    </form>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
