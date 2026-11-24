<?php
/**
 * AutoSouq - help articles for the AI assistant (kb_faq).
 *
 * The assistant answers from the cars table and from these articles, so
 * this is where managers write down policies such as warranty, financing
 * or delivery. Hidden articles stay in the table but are left out of the
 * assistant's index. Every function assumes the calling page has already
 * run require_admin().
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/includes/knowledge.php
 * @task    Extra: AI assistant knowledge base
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rag.php';

/** Length limits, also printed as hints on the form. */
const KB_TITLE_MIN = 5;
const KB_TITLE_MAX = 150;    // matches kb_faq.title VARCHAR(150)
const KB_BODY_MIN  = 20;
const KB_BODY_MAX  = 4000;

/** Every article, or only the ones the assistant uses. */
function get_kb_articles(bool $activeOnly = false): array
{
    $sql = 'SELECT faq_id, title, body, is_active, updated_at FROM kb_faq';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    return db()->query($sql . ' ORDER BY title ASC, faq_id ASC')->fetchAll();
}

/** One article, or null if the id is unknown. */
function get_kb_article(int $faqId): ?array
{
    $stmt = db()->prepare(
        'SELECT faq_id, title, body, is_active, updated_at FROM kb_faq WHERE faq_id = :id'
    );
    $stmt->execute([':id' => $faqId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Server-side checks for the article form. The browser checks the lengths
 * too (minlength / maxlength), but a request can be sent without the form.
 *
 * @return string[] error messages, empty when the input is valid
 */
function validate_kb_article(array $input): array
{
    $errors = [];

    $title = trim((string) ($input['title'] ?? ''));
    $body  = trim((string) ($input['body'] ?? ''));

    if ($title === '') {
        $errors[] = 'Please give the article a title.';
    } elseif (mb_strlen($title) < KB_TITLE_MIN || mb_strlen($title) > KB_TITLE_MAX) {
        $errors[] = 'The title must be between ' . KB_TITLE_MIN . ' and ' . KB_TITLE_MAX . ' characters.';
    }

    if ($body === '') {
        $errors[] = 'Please write the article text.';
    } elseif (mb_strlen($body) < KB_BODY_MIN || mb_strlen($body) > KB_BODY_MAX) {
        $errors[] = 'The article text must be between ' . KB_BODY_MIN . ' and '
                  . number_format(KB_BODY_MAX) . ' characters.';
    }

    return $errors;
}

/**
 * Add an article ($faqId null) or save changes to one.
 *
 * @return array{ok: bool, message: string, faq_id: int|null}
 */
function kb_save_article(?int $faqId, array $input): array
{
    $errors = validate_kb_article($input);
    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors), 'faq_id' => $faqId];
    }

    // A title is one line; line breaks pasted into it are turned into spaces.
    $title    = trim((string) preg_replace('/\s+/u', ' ', (string) $input['title']));
    $body     = trim(str_replace("\r\n", "\n", (string) $input['body']));
    $isActive = empty($input['is_active']) ? 0 : 1;

    try {
        if ($faqId === null) {
            db()->prepare(
                'INSERT INTO kb_faq (title, body, is_active) VALUES (:title, :body, :is_active)'
            )->execute([':title' => $title, ':body' => $body, ':is_active' => $isActive]);

            return [
                'ok'      => true,
                'message' => 'Added the article "' . $title . '".',
                'faq_id'  => (int) db()->lastInsertId(),
            ];
        }

        if (get_kb_article($faqId) === null) {
            return ['ok' => false, 'message' => 'That article no longer exists.', 'faq_id' => null];
        }

        // updated_at is set by hand so the list shows the save even when
        // nothing in the text changed.
        db()->prepare(
            'UPDATE kb_faq
                SET title = :title, body = :body, is_active = :is_active, updated_at = NOW()
              WHERE faq_id = :faq_id'
        )->execute([':title' => $title, ':body' => $body, ':is_active' => $isActive, ':faq_id' => $faqId]);

        return ['ok' => true, 'message' => 'Saved changes to "' . $title . '".', 'faq_id' => $faqId];
    } catch (Throwable $ex) {
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Saving failed: ' . $ex->getMessage()
                                   : 'The article could not be saved.',
            'faq_id'  => $faqId,
        ];
    }
}

/** @return array{ok: bool, message: string} */
function kb_delete_article(int $faqId): array
{
    $article = get_kb_article($faqId);
    if ($article === null) {
        return ['ok' => false, 'message' => 'That article no longer exists.'];
    }

    try {
        db()->prepare('DELETE FROM kb_faq WHERE faq_id = :id')->execute([':id' => $faqId]);
        return ['ok' => true, 'message' => 'Deleted the article "' . $article['title'] . '".'];
    } catch (Throwable $ex) {
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Delete failed: ' . $ex->getMessage()
                                   : 'The article could not be deleted.',
        ];
    }
}

/**
 * Show an article to the assistant or hide it. Hiding is the safe choice
 * for a policy that is only paused, since the text is kept.
 *
 * @return array{ok: bool, message: string}
 */
function kb_set_active(int $faqId, bool $active): array
{
    $article = get_kb_article($faqId);
    if ($article === null) {
        return ['ok' => false, 'message' => 'That article no longer exists.'];
    }

    try {
        db()->prepare('UPDATE kb_faq SET is_active = :active WHERE faq_id = :id')
            ->execute([':active' => $active ? 1 : 0, ':id' => $faqId]);

        return [
            'ok'      => true,
            'message' => $active
                ? 'The assistant now uses "' . $article['title'] . '".'
                : '"' . $article['title'] . '" is now hidden from the assistant.',
        ];
    } catch (Throwable $ex) {
        return [
            'ok'      => false,
            'message' => APP_DEBUG ? 'Update failed: ' . $ex->getMessage()
                                   : 'The article could not be updated.',
        ];
    }
}

/**
 * Refresh the assistant's index after an article changed and say how it
 * went. The article is already saved at this point, so a failure here
 * (no network, API down) is only a warning, never an error.
 */
function kb_refresh_after_change(): void
{
    try {
        $stats = kb_rebuild_index();
        flash($stats['errors'] > 0 ? 'warning' : 'info', kb_rebuild_message($stats));
    } catch (Throwable $ex) {
        error_log('AutoSouq: AI index refresh failed: ' . $ex->getMessage());
        flash('warning', 'The article is saved, but the AI assistant index could not be refreshed. '
                       . 'Use "Rebuild AI index" on the dashboard to try again.');
    }
}
