<?php
/**
 * AutoSouq - JSON endpoint behind the chat window (js/assistant.js).
 *
 * POST only, JSON in and JSON out, never HTML. The request must carry the
 * page's CSRF token in an X-CSRF-Token header; a normal form post cannot
 * set that header, so another site cannot ask questions in a visitor's
 * name. Each visitor may ask AI_RATE_PER_HOUR questions an hour, counted
 * against a keyed hash of the IP address (the address itself is never
 * stored, and without RATE_LIMIT_SECRET the hash cannot be reversed).
 *
 * Request:  {"message": "..."}  or  {"action": "reset"}
 * Answer:   {"ok": true, "answer": "...", "sources": [...], "mode": "ai"|"search", "remaining": n}
 *           {"ok": true, "reset": true}
 *           {"ok": false, "error": "..."} with 400, 403, 405, 429 or 503
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/assistant_api.php
 * @task    Extra: AI assistant (RAG)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/rag.php';

/** Longest request body we read, in bytes. A 500 character question fits easily. */
const CHAT_MAX_BODY = 4096;
/** Longest question, in characters. */
const CHAT_MAX_MESSAGE = 500;

/** Send one JSON answer and stop. */
function chat_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * The rate-limit key for this visitor: an HMAC of the IP address with a
 * secret, so a copy of chat_rate cannot be turned back into addresses by
 * hashing all 4 billion IPv4 addresses. One IPv6 user usually owns a whole
 * /64 block and could rotate through it, so IPv6 is counted per /64.
 */
function chat_client_key(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return hash_hmac('sha256', 'unknown', RATE_LIMIT_SECRET);
    }

    $bin = (string) inet_pton($ip);
    if (strlen($bin) === 16) {
        // An IPv4 address written as IPv6 (::ffff:1.2.3.4) is still one
        // IPv4 visitor; its /64 would put every such visitor in one bucket.
        $mapped = str_repeat("\0", 10) . "\xff\xff";
        $bin = str_starts_with($bin, $mapped) ? substr($bin, 12) : substr($bin, 0, 8);
    }
    return hash_hmac('sha256', $bin, RATE_LIMIT_SECRET);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');   // answers depend on the session; never cache them

// Worst case is about 35 s (6 s embedding + 20 s Claude + connects), and
// assistant.js gives up at 45 s. The limit only stops a request that hangs.
set_time_limit(60);

/* 1. Only POST. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    chat_reply(405, ['ok' => false, 'error' => 'Please use the chat window to ask a question.']);
}

/* 2. CSRF. csrf_check() is not used because it stops with an HTML page. */
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
    chat_reply(403, ['ok' => false, 'error' => 'Your session has expired. Please reload the page and try again.']);
}

/* 3. Read the body: at most CHAT_MAX_BODY bytes of JSON. */
$raw = file_get_contents('php://input', false, null, 0, CHAT_MAX_BODY + 1);
if (!is_string($raw) || strlen($raw) > CHAT_MAX_BODY) {
    chat_reply(400, ['ok' => false, 'error' => 'That message is too long. Please keep it under '
                                               . CHAT_MAX_MESSAGE . ' characters.']);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    chat_reply(400, ['ok' => false, 'error' => 'Sorry, that message could not be read. Please try again.']);
}

/* 4. Start a new conversation. */
if (($input['action'] ?? '') === 'reset') {
    unset($_SESSION['chat_history']);
    chat_reply(200, ['ok' => true, 'reset' => true]);
}

/* 5. Check the question. Control characters are removed, line breaks kept. */
$message = $input['message'] ?? null;
$message = is_string($message)
    ? trim((string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $message))
    : '';
$length = mb_strlen($message);
if ($length < 1) {
    chat_reply(400, ['ok' => false, 'error' => 'Please type a question first.']);
}
if ($length > CHAT_MAX_MESSAGE) {
    chat_reply(400, ['ok' => false, 'error' => 'Please keep your question under '
                                               . CHAT_MAX_MESSAGE . ' characters.']);
}

try {
    /* 6. Rate limit, per visitor (see chat_client_key()). */
    $clientKey = chat_client_key();

    $count = db()->prepare(
        'SELECT COUNT(*) FROM chat_rate
          WHERE client_key = :client_key
            AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $count->execute([':client_key' => $clientKey]);
    $used = (int) $count->fetchColumn();

    if ($used >= AI_RATE_PER_HOUR) {
        chat_reply(429, [
            'ok'    => false,
            'error' => 'You have asked a lot of questions in the last hour. Please try again a '
                     . 'little later, or call the showroom on ' . SHOP_PHONE . '.',
        ]);
    }

    db()->prepare('INSERT INTO chat_rate (client_key) VALUES (:client_key)')
        ->execute([':client_key' => $clientKey]);
    $used++;

    // Old rows are only needed for one hour. Clearing them on roughly one
    // request in fifty keeps the table small without a scheduled job.
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM chat_rate WHERE created_at < NOW() - INTERVAL 1 DAY');
    }

    /* 7. Answer. The session is closed while waiting for the AI, which can
     * take a few seconds; otherwise PHP's session lock would freeze every
     * other page this visitor opens in the meantime. */
    $history = $_SESSION['chat_history'] ?? [];
    $history = is_array($history) ? $history : [];
    $sessionId = session_id();
    session_write_close();

    $result = ai_answer($message, $history);

    /* 8. Save the turn. While we waited, another tab may have signed in,
     * signed out or changed the password, which gives the visitor a new
     * session id and deletes this one. Reopening it must not bring it back
     * or send a cookie that replaces the new one, so it restarts without
     * cookies, and strict mode hands out a fresh id if the old one is gone.
     * In that case the history is not saved (the conversation was reset by
     * the sign-in change anyway) and the answer is still returned.
     * The @ keeps a session warning from printing into the JSON. */
    session_id($sessionId);
    if (@session_start(['use_cookies' => 0]) && session_id() === $sessionId) {
        $history   = is_array($_SESSION['chat_history'] ?? null) ? $_SESSION['chat_history'] : [];
        $history[] = ['q' => $message, 'a' => $result['answer']];
        $_SESSION['chat_history'] = array_slice($history, -AI_HISTORY_TURNS);
        session_write_close();
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();   // only the empty session strict mode just made
    }

    chat_reply(200, [
        'ok'        => true,
        'answer'    => $result['answer'],
        'sources'   => $result['sources'],
        'mode'      => $result['mode'],
        'remaining' => max(0, AI_RATE_PER_HOUR - $used),
    ]);
} catch (Throwable $ex) {
    ai_log('chat request failed', $ex->getMessage());
    $error = 'The assistant is not available right now. Please try again later, or call us on '
           . SHOP_PHONE . '.';
    if (APP_DEBUG) {
        $error .= ' (' . mb_substr($ex->getMessage(), 0, 150) . ')';
    }
    chat_reply(503, ['ok' => false, 'error' => $error]);
}
