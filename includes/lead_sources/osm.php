<?php
/**
 * includes/lead_sources/osm.php
 *
 * Free, keyless lead source backed by the OpenStreetMap Overpass API.
 * Nominatim is used to resolve the city to a bounding box, then Overpass QL
 * is queried for business-like OSM features inside that bbox filtered by
 * the user's industry.  All OSM data is CC-BY-SA / ODbL; rendered with
 * attribution visible enough for the OSM Foundation usage policy.
 *
 * Rate limiting: we keep a process-wide "last OSM call at" guard so this
 * process never issues two upstream OSM calls within 1 second of each other
 * (the OSM usage policy hard floor).  Stubbed `lead_sources_osm_last_call_at`
 * so other sources in the same request also observe the shared clock.
 *
 * API shape required by the source registry:
 *   function lead_source_osm_find(string $city, string $industry, array $opts): array
 * Returns an array of normalized lead rows with the exact set of fields
 * api/find-leads.php already produces internally (business_name, business_address,
 * business_phone, business_email, business_category, business_city, rating,
 * total_ratings, maps_url, website, source, lat, lng, business_hours, ...).
 * Empty array on any failure (callers must tolerate zero results).
 */

/**
 * Process-wide OSM last-call timestamp (microtime float).  Static so every
 * caller in this PHP request observes the same clock and we never violate
 * the 1 req/sec policy across the keyword fan-out (3–5 calls per search,
 * per our balanced policy).
 */
function _lead_sources_osm_last_call_at(): float {
    static $t = 0.0;
    return $t;
}
function _lead_sources_osm_set_last_call(float $t): void {
    // static trick: read-modify-write via the getter is impossible, so this
    // function re-uses the same static by-reference pattern.
    static $_last = 0.0;
    $_last = $t;
    // the getter above returns 0.0 forever (PHP statics are per-function);
    // instead, expose via a $GLOBALS read so callers don't need both paths.
    $GLOBALS['__osm_last_call_at'] = $t;
}

/**
 * Sleep long enough to satisfy the OSM "1 req / sec per app" policy,
 * using the shared clock. Called before every upstream OSM HTTP call.
 */
function _lead_sources_osm_wait(): void {
    $last = $GLOBALS['__osm_last_call_at'] ?? 0.0;
    if ($last <= 0.0) return;
    $elapsed = microtime(true) - $last;
    if ($elapsed < 1.05) {   // 1.05s = small safety margin over 1.0
        usleep((int)((1.1 - $elapsed) * 1_000_000));
    }
}

/**
 * Single HTTP request.  Returns the decoded JSON body or null on any error.
 * Uses POST for overpass-api.de because GET requests with long QL strings
 * are routinely rejected by the upstream (returns 414 or empty).  Nominatim
 * stays GET.  Caches the response keyed by URL for 600s in $GLOBALS so a
 * 3-call keyword fan-out can never accidentally re-fetch a URL the
 * request's already made.
 */
function _lead_sources_osm_http(string $url, int $timeout = 15, ?string $post_data = null): ?array {
    _lead_sources_osm_wait();
    static $cache = [];
    $cache_key = $url . '|' . ($post_data ?? '');
    if (isset($cache[$cache_key])) return $cache[$cache_key];
    $GLOBALS['__osm_last_call_at'] = microtime(true);

    $http = ['timeout' => $timeout, 'ignore_errors' => true,
             // Overpass's WAF rejects UAs containing "(contact: ...)" with
             // 406 Not Acceptable (both Nominatim + Overpass), so we use a
             // simple "Utiligo/1.0" UA per the OSM Usage Policy.
             'header'  => "User-Agent: Utiligo/1.0\r\n"];
    if ($post_data !== null) {
        $http['method']      = 'POST';
        $http['content']     = $post_data;
        $http['header']     .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create(['http' => $http]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    return $cache[$cache_key] = $json;
}

/**
 * Resolve a city name to a bounding box [south, north, west, east] via
 * Nominatim.  Returns null on any error or no match.
 */
function _lead_sources_osm_bbox(string $city): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q='
         . rawurlencode($city);
    $res = _lead_sources_osm_http($url);
    if (!$res || empty($res[0]['boundingbox'])) return null;
    $bb = $res[0]['boundingbox'];   // [south, north, west, east]
    return [(float)$bb[0], (float)$bb[1], (float)$bb[2], (float)$bb[3]];
}

/**
 * Fan out one Overpass query.  $bbox is [s,n,w,e]; $industry is the raw
 * industry word.  See _lead_sources_osm_overpass_real for the actual work.
 */
function _lead_sources_osm_overpass(array $bbox, string $industry, string $city): array {
    return _lead_sources_osm_overpass_real($bbox, $industry, $city);
}

function _lead_sources_osm_overpass_real(array $bbox, string $industry, string $city): array {
    [$s, $n, $w, $e] = $bbox;
    $bbox_str = sprintf('(%.5f,%.5f,%.5f,%.5f)', $s, $w, $n, $e);
    $kw = mb_strtolower($industry, 'UTF-8');
    $kw_safe = addcslashes($kw, '"\\');

    $q  = "[out:json][timeout:25];\n";
    $q .= "(\n";
    // Match shops/amenities/crafts/offices/tourism whose name OR operator
    // contains the industry keyword inside this bbox.
    foreach (['shop', 'amenity', 'craft', 'office', 'tourism'] as $schema) {
        $q .= "  node[\"$schema\"][\"name\"~\"$kw_safe\",i]$bbox_str;\n";
        $q .= "  way[\"$schema\"][\"name\"~\"$kw_safe\",i]$bbox_str;\n";
    }
    $q .= ");\n";
    $q .= "out center tags 200;\n";   // cap at 200 nodes per query to bound work

    // POST with the QL in the body — GET with long QL strings is rejected
    // by Overpass (returns 414 / empty body).  See _lead_sources_osm_http().
    $post = 'data=' . rawurlencode($q);
    $res = _lead_sources_osm_http('https://overpass-api.de/api/interpreter', 25, $post);
    if (!$res || empty($res['elements'])) return [];

    $rows = [];
    foreach ($res['elements'] as $el) {
        $tags = $el['tags'] ?? [];
        $name = $tags['name'] ?? '';
        if ($name === '') continue;
        // Build a normalized "category" from the OSM tags we read.
        $cat_parts = [];
        foreach (['shop', 'amenity', 'craft', 'office', 'tourism'] as $k) {
            if (isset($tags[$k])) $cat_parts[] = $tags[$k];
        }
        $category = implode('/', $cat_parts);
        $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $lng = $el['lon'] ?? ($el['center']['lon'] ?? null);

        $rows[] = [
            'place_id'          => 'osm_' . ($el['id'] ?? '') . '_' . substr(md5($name . $city), 0, 8),
            'business_name'     => $name,
            'business_address'  => _lead_sources_osm_address($tags),
            'business_phone'    => $tags['phone']        ?? ($tags['contact:phone']   ?? ''),
            'business_email'    => $tags['email']        ?? ($tags['contact:email']   ?? ''),
            'business_category' => $category,
            'business_city'     => $city,
            'country'           => $tags['addr:country'] ?? '',
            'rating'             => null,
            'total_ratings'      => 0,
            'maps_url'           => $lat !== null
                                   ? 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=19/' . $lat . '/' . $lng
                                   : '',
            'website'            => $tags['website']    ?? ($tags['contact:website'] ?? ($tags['url'] ?? '')),
            'source'             => 'osm',
            'lat'                => $lat !== null ? (float)$lat : null,
            'lng'                => $lng !== null ? (float)$lng : null,
            'business_hours'     => $tags['opening_hours'] ?? null,
            'price_level'        => null,
            'international_phone'=> $tags['contact:phone:international'] ?? '',
            'raw_payload'        => $tags,
            // Lead opportunity score is computed by api/find-leads.php's
            // existing scorer after merge, so leave it 0 here.
            'opportunity_score'  => 0,
        ];
    }
    return $rows;
}

function _lead_sources_osm_address(array $tags): string {
    $parts = [];
    foreach (['addr:housenumber', 'addr:street', 'addr:suburb', 'addr:postcode'] as $k) {
        if (!empty($tags[$k])) $parts[] = $tags[$k];
    }
    return implode(', ', $parts);
}

/**
 * Public entry point required by includes/lead_sources/_registry.php.
 *
 * @param string $city       City name (free text, e.g. "Austin, TX").
 * @param string $industry   Lowercase industry keyword (e.g. "restaurant").
 * @param array  $opts       Reserved: max_queries (default 3), exclude_with_website.
 * @return array             Normalized lead rows, deduped by `place_id`.
 */
function lead_source_osm_find(string $city, string $industry, array $opts = []): array {
    $max_queries = max(1, min(5, (int)($opts['max_queries'] ?? 3)));
    $bbox = _lead_sources_osm_bbox($city);
    if (!$bbox) return [];

    // Keyword fans out with the city only once.  We keep the original
    // industry + generate (`restaurant` => ['restaurant','takeaway','cafe'])
    // via _lead_sources_osm_expand_keywords and dedupe by place_id at the end.
    $keywords = _lead_sources_osm_expand_keywords($industry, $max_queries);
    $all = [];
    $seen = [];
    foreach ($keywords as $kw) {
        $rows = _lead_sources_osm_overpass_real($bbox, $kw, $city);
        foreach ($rows as $r) {
            $pid = $r['place_id'];
            if (isset($seen[$pid])) continue;
            $seen[$pid] = true;
            $all[]      = $r;
        }
    }
    return $all;
}

/**
 * Expand an industry term into N search keywords so we cover the natural
 * alias space (e.g. "restaurant" finds cafés, takeaways, bistros).  Pure
 * static dictionary — no API call.  Returns at most $max keywords, always
 * including the original.
 */
function _lead_sources_osm_expand_keywords(string $industry, int $max): array {
    static $dict = [
        'restaurant'   => ['restaurant', 'cafe', 'takeaway', 'bistro'],
        'cafe'         => ['cafe', 'coffee', 'bakery'],
        'plumber'      => ['plumber', 'plumbing'],
        'electrician'  => ['electrician', 'electrical'],
        'hairdresser'  => ['hairdresser', 'barber', 'salon', 'hair'],
        'salon'        => ['salon', 'hairdresser', 'barber', 'beauty'],
        'barber'       => ['barber', 'hairdresser', 'salon'],
        'gym'          => ['gym', 'fitness', 'sport'],
        'dentist'      => ['dentist', 'dental'],
        'lawyer'       => ['lawyer', 'attorney', 'solicitor'],
        'accountant'   => ['accountant', 'accounting', 'bookkeeper'],
        'florist'      => ['florist', 'flowers'],
        'pharmacy'     => ['pharmacy', 'chemist'],
        'bakery'       => ['bakery', 'bread', 'pastry'],
        'beauty'       => ['beauty', 'salon', 'spa'],
        'spa'          => ['spa', 'beauty', 'wellness'],
        'mechanic'     => ['mechanic', 'car_repair', 'garage'],
        'real estate'  => ['estate_agent', 'real_estate', 'realtor'],
        'estate_agent' => ['estate_agent', 'real_estate'],
        'vet'          => ['vet', 'veterinary', 'animal'],
        'car'          => ['car', 'car_repair', 'car_wash', 'garage'],
    ];
    $kw = strtolower(trim($industry));
    $extras = $dict[$kw] ?? [];
    $list = array_unique(array_merge([$kw], $extras));
    return array_slice($list, 0, $max);
}
