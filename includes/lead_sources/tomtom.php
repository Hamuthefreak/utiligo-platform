<?php
/**
 * includes/lead_sources/tomtom.php
 *
 * Lead source backed by the TomTom Search API (poiSearch).  Resolves the
 * city to a centre point via the geocoding endpoint, then sweeps a POI
 * search around that centre for the industry keyword.
 *
 * Requires a free developer API key exposed as TOMTOM_API_KEY
 * (config.php / storage/config_overrides.php).  Free tier ≈ 2,500 req/day.
 * When the constant is unset or a placeholder the source degrades to an
 * empty result set — search continues with the other engines.
 *
 * API shape required by the source registry:
 *   function lead_source_tomtom_find(string $city, string $industry, array $opts): array
 */

require_once __DIR__ . '/_keywords.php';

function _lead_sources_tomtom_key(): ?string {
    if (!defined('TOMTOM_API_KEY')) return null;
    $key = (string)constant('TOMTOM_API_KEY');
    if ($key === '' || stripos($key, 'YOUR_') !== false) return null;
    return $key;
}

function _lead_sources_tomtom_http(string $url, int $timeout = 15): ?array {
    if (_lead_sources_tomtom_key() === null) return null;

    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\nUser-Agent: Utiligo/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    return $json;
}

/**
 * Resolve the city to [lat, lon] via TomTom geocoding.  Returns null on error.
 */
function _lead_sources_tomtom_position(string $city): ?array {
    $key = _lead_sources_tomtom_key();
    if ($key === null) return null;
    $url = 'https://api.tomtom.com/search/2/geocode/' . rawurlencode($city)
         . '.json?limit=1&view=Unified&key=' . rawurlencode($key);
    $res = _lead_sources_tomtom_http($url);
    if (!$res || empty($res['results'][0]['position'])) return null;
    $p = $res['results'][0]['position'];
    return [(float)($p['lat'] ?? 0), (float)($p['lon'] ?? 0)];
}

/**
 * Public entry point required by includes/lead_sources/_registry.php.
 *
 * @param string $city       City name (free text, e.g. "Austin, TX").
 * @param string $industry   Lowercase industry keyword (e.g. "restaurant").
 * @param array  $opts       Reserved: max_queries (default 2), radius metres.
 * @return array             Normalized lead rows, deduped by TomTom id/hash.
 */
function lead_source_tomtom_find(string $city, string $industry, array $opts = []): array {
    $max_queries = max(1, min(3, (int)($opts['max_queries'] ?? 2)));
    $radius      = max(1000, min(20000, (int)($opts['radius'] ?? 8000)));
    $pos = _lead_sources_tomtom_position($city);
    if (!$pos) return [];
    [$lat, $lon] = $pos;

    $terms = lead_sources_expand_keywords($industry, $max_queries);
    $key = _lead_sources_tomtom_key();
    $all  = [];
    $seen = [];

    foreach ($terms as $term) {
        $url = 'https://api.tomtom.com/search/2/poiSearch/' . rawurlencode($term) . '.json'
             . '?key=' . rawurlencode((string)$key)
             . '&lat=' . $lat . '&lon=' . $lon . '&radius=' . $radius
             . '&limit=100&view=Unified&relatedPois=off';
        $res = _lead_sources_tomtom_http($url);
        if (!$res || empty($res['results']) || !is_array($res['results'])) continue;

        foreach ($res['results'] as $r) {
            $poi = $r['poi'] ?? [];
            $name = trim((string)($poi['name'] ?? ''));
            if ($name === '') continue;

            $pid = isset($r['id']) ? (string)$r['id'] : '';
            if ($pid === '') {
                $pid = md5($name . '|' . ($r['address']['freeformAddress'] ?? ''));
            }
            $pid = 'tomtom_' . preg_replace('/[^A-Za-z0-9._-]/', '', $pid);
            if (isset($seen[$pid])) continue;
            $seen[$pid] = true;

            $pos2  = $r['position'] ?? [];
            $addr  = $r['address'] ?? [];
            $relat = isset($pos2['lat']) ? (float)$pos2['lat'] : null;
            $relon = isset($pos2['lon']) ? (float)$pos2['lon'] : null;
            $catRaw = (string)($poi['categories'][0] ?? '');
            $cat = $catRaw !== '' ? substr($catRaw, (int)strrpos($catRaw, ':') + 1) : $industry;

            $all[] = [
                'place_id'           => $pid,
                'business_name'      => $name,
                'business_address'   => trim((string)($addr['freeformAddress'] ?? '')),
                'business_phone'     => trim((string)($poi['phone'] ?? '')),
                'business_email'     => '',
                'business_category'  => $cat,
                'business_city'      => trim((string)($addr['municipality'] ?? ($addr['localName'] ?? $city))) ?: $city,
                'country'            => trim((string)($addr['country'] ?? '')),
                'rating'             => null,
                'total_ratings'      => 0,
                'maps_url'           => trim((string)($poi['url'] ?? '')) ?: (
                    ($relat !== null && $relon !== null)
                        ? 'https://www.openstreetmap.org/?mlat=' . $relat . '&mlon=' . $relon . '#map=19/' . $relat . '/' . $relon
                        : ''
                ),
                'website'            => '',
                'source'             => 'tomtom',
                'lat'                => $relat,
                'lng'                => $relon,
                'business_hours'     => null,
                'price_level'        => null,
                'international_phone'=> trim((string)($poi['phone'] ?? '')),
                'raw_payload'        => ['tomtom_id' => $r['id'] ?? null],
                'opportunity_score'  => 0,
            ];
        }
    }
    return $all;
}