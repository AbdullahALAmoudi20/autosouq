<?php
/**
 * AutoSouq - safety recalls from the NHTSA public API.
 *
 * NHTSA (the US National Highway Traffic Safety Administration) publishes
 * every vehicle recall through a free JSON API that needs no key:
 *   https://api.nhtsa.gov/recalls/recallsByVehicle?make=..&model=..&modelYear=..
 *
 * The product details page shows the recalls for the car being viewed, so a
 * customer can see any known safety issue before buying. Answers are cached
 * for 24 hours in app/cache/ so we do not call NHTSA on every page view, and
 * a slow or failed call never stops the page from loading.
 *
 * @author  Ali Alshehri (Catalogue and JavaScript)
 * @file    app/includes/recalls.php
 * @task    Task 4 (extra: external API)
 */

declare(strict_types=1);

const NHTSA_URL       = 'https://api.nhtsa.gov/recalls/recallsByVehicle';
const NHTSA_CACHE_DIR = __DIR__ . '/../cache/';
const NHTSA_CACHE_TTL = 24 * 60 * 60;
const NHTSA_TIMEOUT   = 5;

/** Model names that are more than one word. Everything else uses the first word. */
const NHTSA_MULTI_WORD = ['Land Cruiser', 'Ioniq 5', 'F-150'];

/** Turn "Toyota Land Cruiser GXR" into the model name NHTSA expects. */
function nhtsa_model(string $carName, string $brand): string
{
    $rest = trim((string) preg_replace('/^' . preg_quote($brand, '/') . '\s+/i', '', $carName));
    foreach (NHTSA_MULTI_WORD as $model) {
        if (stripos($rest, $model) === 0) {
            return $model;
        }
    }
    return explode(' ', $rest)[0];
}

/**
 * Recalls for one make, model and year.
 *
 * @return array|null list of recalls (possibly empty), or null when NHTSA
 *                    could not be reached and nothing is cached
 */
function get_recalls(string $make, string $model, int $year): ?array
{
    $cacheFile = NHTSA_CACHE_DIR . 'recalls_' . md5(strtolower("$make|$model|$year")) . '.json';

    if (is_file($cacheFile) && time() - filemtime($cacheFile) < NHTSA_CACHE_TTL) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = NHTSA_URL . '?' . http_build_query(['make' => $make, 'model' => $model, 'modelYear' => $year]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => NHTSA_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => NHTSA_TIMEOUT,
        CURLOPT_USERAGENT      => 'AutoSouq student project',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    // XAMPP ships a certificate bundle; use it when PHP has none configured.
    $bundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (ini_get('curl.cainfo') === '' && is_file($bundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $bundle);
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // NHTSA answers HTTP 400 (with a normal body) when a model has no
    // recalls, so the body is checked rather than the status code.
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status >= 500 || $status === 0 || !is_array($data) || !isset($data['results'])) {
        // Fall back to an old cached answer rather than showing nothing.
        if (is_file($cacheFile)) {
            $old = json_decode((string) file_get_contents($cacheFile), true);
            return is_array($old) ? $old : null;
        }
        return null;
    }

    // Keep only the fields the page shows.
    $recalls = [];
    foreach ($data['results'] as $r) {
        $recalls[] = [
            'campaign'  => (string) ($r['NHTSACampaignNumber'] ?? ''),
            'date'      => (string) ($r['ReportReceivedDate'] ?? ''),
            'component' => (string) ($r['Component'] ?? ''),
            'summary'   => (string) ($r['Summary'] ?? ''),
            'remedy'    => (string) ($r['Remedy'] ?? ''),
        ];
    }

    if (is_dir(NHTSA_CACHE_DIR) || @mkdir(NHTSA_CACHE_DIR, 0775, true)) {
        @file_put_contents($cacheFile, json_encode($recalls));
    }
    return $recalls;
}
