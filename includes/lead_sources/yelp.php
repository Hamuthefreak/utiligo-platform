<?php
/**
 * includes/lead_sources/yelp.php
 *
 * Lead source backed by the Yelp Fusion Business Search API — the
 * highest-quality structured local-business payload of the three new
 * engines (phone, rating, review count, price tier, coordinates).
 *
 * Requires a free API key exposed as YELP_API_KEY (config.php /
 * storage/config_overrides.php).  Free tier ≈ 5,000 calls/day.  When the
 * constant is unset or still a placeholder the source degrades to an empty
 * result set — search continues normally with the other engines.
 *
 * Note: the Fusion business-search payload does not include a website URL
 * or opening hours (those live on the business-details endpoint).  Rows are
 * therefore website-less placeholders that the enrichment pipeline
 * (website_finder) can later backfill.
 *
 * API shape required by the source registry:
 *   function lead_source_yelp_find(string $city, string $industry, array $opts): array
 */

require_once __DIR__ . '/_keywords.php';

function _lead_sources_yelp_http(string $url, int $timeout = 15): ?array {
    if (!defined('YELP_API_KEY')) return null;
    $key = (string)constant('YELP_API_KEY');
    if ($key === '' || stripos($key, 'YOUR_') !== false) return null;

    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Authorization: Bearer " . $key . "\r\n"
                          . "Accept: application/json\r\n"
                          . "User-Agent: Utiligo/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    return $json;
}

/**
 * Public entry point required by includes/lead_sources/_registry.php.
 *
 * @param string $city       City name (free text, e.g. "Austin, TX").
 * @param string $industry   Lowercase industry keyword (e.g. "restaurant").
 * @param array  $opts       Reserved: max_queries (default 2).
 * @return array             Normalized lead rows, deduped by Yelp business id.
 */
function lead_source_yelp_find(string $city, string $industry, array $opts = []): array {
    $max_queries = max(1, min(3, (int)($opts['max_queries'] ?? 2)));
    $terms = lead_sources_expand_keywords($industry, $max_queries);

    $all  = [];
    $seen = [];
    foreach ($terms as $term) {
        $url = 'https://api.yelp.com/v3/businesses/search?limit=50&sort_by=best_match'
             . '&term=' . rawurlencode($term)
             . '&location=' . rawurlencode($city);
        $res = _lead_sources_yelp_http($url);
        if (!$res || empty($res['businesses']) || !is_array($res['businesses'])) continue;

        foreach ($res['businesses'] as $b) {
            $id = (string)($b['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            // Skip checked-in-closed venues — they are dead leads.
            if (!empty($b['is_closed'])) continue;
            $seen[$id] = true;

            $loc   = $b['location'] ?? [];
            $coord = $b['coordinates'] ?? [];
            $cats  = $b['categories'] ?? [];
            $price = (string)($b['price'] ?? '');
            $lat = isset($coord['latitude'])  ? (float)$coord['latitude']  : null;
            $lng = isset($coord['longitude']) ? (float)$coord['longitude'] : null;

            $addr_parts = array_filter([
                (string)($loc['address1'] ?? ''),
                (string)($loc['address2'] ?? ''),
                implode(' ', array_filter([(string)($loc['city'] ?? ''), (string)($loc['postal_code'] ?? '')])),
            ]);

            $all[] = [
                'place_id'           => 'yelp_' . $id,
                'business_name'      => trim((string)($b['name'] ?? '')),
                'business_address'   => implode(', ', $addr_parts),
                'business_phone'     => (string)($b['display_phone'] ?? ''),
                'business_email'     => '',
                'business_category'  => trim((string)($cats[0]['title'] ?? '')) ?: $industry,
                'business_city'      => (string)($loc['city'] ?? $city),
                'country'            => (string)($loc['country'] ?? ''),
                'rating'             => isset($b['rating']) ? (float)$b['rating'] : null,
                'total_ratings'      => (int)($b['review_count'] ?? 0),
                'maps_url'           => (string)($b['url'] ?? ''),
                'website'            => '',
                'source'             => 'yelp',
                'lat'                => $lat,
                'lng'                => $lng,
                'business_hours'     => null,
                'price_level'        => ['$' => 1, '$$' => 2, '$$$' => 3, '$$$$' => 4][$price] ?? null,
                'international_phone'=> (string)($b['phone'] ?? ''),
                'raw_payload'        => ['yelp_id' => $id, 'is_closed' => !empty($b['is_closed'])],
                'opportunity_score'  => 0,
            ];
        }
    }
    return $all;
}