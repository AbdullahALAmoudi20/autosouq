<?php
/**
 * AutoSouq - one small HTTP client for the outside services.
 *
 * The AI assistant talks to OpenAI and Anthropic, and Google sign-in talks
 * to Google, all with a JSON or form POST over HTTPS. This file holds that
 * request in one place, the same way recalls.php calls NHTSA: PHP cURL, a
 * short timeout, the certificate check left on, and never a fatal error -
 * the caller gets a status code and decides what to show.
 *
 * @author  Abdullah Alamoudi (Team Leader and Back-End)
 * @file    app/includes/http.php
 */

declare(strict_types=1);

/**
 * POST to a URL and decode the JSON answer.
 *
 * @param array<string,string> $headers e.g. ['x-api-key' => '...']
 * @param array|string         $body    an array is sent as JSON, unless
 *                                      $asForm is true (then as a form)
 * @return array{status: int, data: array|null, error: string}
 *         status 0 means the request never reached the server
 */
function http_post(string $url, array $headers, array|string $body, int $timeout = 20, bool $asForm = false): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'data' => null, 'error' => 'PHP cURL is not enabled on this server.'];
    }

    if (is_array($body)) {
        if ($asForm) {
            $payload = http_build_query($body);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }
    } else {
        $payload = $body;
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headerLines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'AutoSouq student project',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    // XAMPP ships a certificate bundle; use it when PHP has none configured.
    $bundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (ini_get('curl.cainfo') === '' && is_file($bundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $bundle);
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['status' => 0, 'data' => null, 'error' => $error !== '' ? $error : 'No answer.'];
    }

    $data = json_decode($raw, true);
    return [
        'status' => $status,
        'data'   => is_array($data) ? $data : null,
        'error'  => $status >= 200 && $status < 300 ? '' : http_error_text($data, $status),
    ];
}

/** A short, safe description of a failed call, for the log (not the page). */
function http_error_text(mixed $data, int $status): string
{
    if (is_array($data)) {
        $message = $data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? null;
        if (is_string($message) && $message !== '') {
            return 'HTTP ' . $status . ': ' . mb_substr($message, 0, 200);
        }
    }
    return 'HTTP ' . $status;
}
