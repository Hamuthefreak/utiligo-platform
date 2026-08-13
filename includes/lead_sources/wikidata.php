<?php
/**
 * includes/lead_sources/wikidata.php
 *
 * Lead source backed by Wikidata's SPARQL query service — free, keyless,
 * CC0 / CC-BY-SA structured business data.
 *
 * WDQS gateway lessons observed in practice (this is the flaky bit, and the
 * reason the engine is built as two minimal queries instead of one rich one):
 *   - POST the query body (long GET URLs are dropped — same lesson as the
 *     OSM Overpass engine).
 *   - The optimizer is nondeterministic on medium-complexity queries: forms
 *     like ?item wdt:P31 ?cls . ?cls wdt:P279* ... combined with VALUES lists,
 *     GROUP BY/SAMPLE, 3+ OPTIONALs, or a combined LANG&&CONTAINS FILTER
 *     intermittently return 502 / dropped connections.  The two shapes below
 *     (single-step property path + separate FILTERs, and a VALUES ?item
 *     detail fetch) consistently return 200.
 *   - Therefore: query (A) finds candidate businesses by label keyword inside
 *     the city bbox; query (B) enriches the found QIDs in one cheap VALUES
 *     call.  Results are deduped by QID in PHP.  Low-to-moderate recall is a
 *     feature of the data (Wikipedia usually documents the notable subset);
 *     this is deliberately a "signal" source, not a firehose.
 *
 * API shape required by the source registry:
 *   function lead_source_wikidata_find(string $city, string $industry, array $opts): array
 * City → bbox resolution reuses osm.php's Nominatim helper + shared clock.
 */

require_once __DIR__ . '/osm.php';        // _lead_sources_osm_bbox + n/a
require_once __DIR__ . '/_keywords.php';

/**
 * POST one SPARQL query (long query strings ride in the POST body).
 * Returns decoded JSON or null.
 */
function _lead_sources_wikidata_http(string $sparql, int $timeout = 8): ?array {
    static $last = 0.0;
    $elapsed = microtime(true) - $last;
    if ($elapsed < 1.0) usleep((int)((1.0 - $elapsed) * 1_000_000));   // 1 req/s
    $last = microtime(true);

    $url = 'https://query.wikidata.org/sparql';
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                          . "User-Agent: Utiligo/1.0 (contact: privacy@utiligo.app)\r\n"
                          . "Accept: application/sparql-results+json\r\n",
        'content'       => 'query=' . rawurlencode($sparql) . '&format=json',
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['results']['bindings'])) return null;
    return $json;
}

/**
 * Query A — candidate businesses in the bbox whose English label contains
 * the industry keyword.  Deliberately two-step (?item wdt:P31 ?cls .
 * ?cls wdt:P279* ...) with separate LANG/CONTAINS FILTER lines, a single
 * OPTIONAL, and NO sitelinks join — this exact light shape is the most
 * reliably accepted by the WDQS gateway (heavier forms intermittently 502 /
 * drop the connection).
 */
function _lead_sources_wikidata_query_candidates(array $bbox, string $kw, int $limit): string {
    [$s, $n, $w, $e] = $bbox;
    $esc = addcslashes(mb_strtolower(trim($kw), 'UTF-8'), "'\"\\");

    return <<<SPARQL
SELECT DISTINCT ?item ?itemLabel ?website ?lat ?lon WHERE {
  ?item wdt:P31 ?cls .
  ?cls wdt:P279* wd:Q4830453 .
  ?item wdt:P625 ?coord .
  BIND(geof:latitude(?coord) AS ?lat)
  BIND(geof:longitude(?coord) AS ?lon)
  FILTER(?lat > {$s} && ?lat < {$n} && ?lon > {$w} && ?lon < {$e})
  ?item rdfs:label ?itemLabel .
  FILTER(LANG(?itemLabel) = "en")
  OPTIONAL { ?item wdt:P856 ?website . }
  FILTER(CONTAINS(LCASE(?itemLabel), "{$esc}"))
}
LIMIT {$limit}
SPARQL;
}

/**
 * Run a query with one retry after a short pause — the WDQS gateway
 * intermittently drops connections; a single immediate retry recovers most.
 */
function _lead_sources_wikidata_retry(string $sparql): ?array {
    $res = _lead_sources_wikidata_http($sparql);
    if ($res !== null) return $res;
    usleep(1_500_000);
    return _lead_sources_wikidata_http($sparql);
}

/**
 * Query B — enrich an explicit QID set with contact fields.  VALUES keeps
 * the join tiny, so this shape is deterministic and cheap.
 */
function _lead_sources_wikidata_query_details(array $qids, int $limit): string {
    $vals = implode(' ', array_map(fn($q) => 'wd:' . preg_replace('/[^0-9]/', '', $q), $qids));

    return <<<SPARQL
SELECT ?item ?website ?phone ?email ?street ?hours WHERE {
  VALUES ?item { {$vals} }
  OPTIONAL { ?item wdt:P856 ?website . }
  OPTIONAL { ?item wdt:P1329 ?phone . }
  OPTIONAL { ?item wdt:P968 ?email . }
  OPTIONAL { ?item wdt:P6375 ?street . }
  OPTIONAL { ?item wdt:P8686 ?hours . }
}
LIMIT {$limit}
SPARQL;
}

/**
 * Merge a details-binding map into candidate rows.
 */
function _lead_sources_wikidata_rows(array $candidates, array $details, string $city, string $industry): array {
    $rows = [];
    foreach ($candidates as $c) {
        $qid = preg_replace('/^.*?([0-9]+)$/', '$1', (string)($c['item']['value'] ?? ''));
        if ($qid === '') continue;
        $name = trim((string)($c['itemLabel']['value'] ?? ''));
        if ($name === '') continue;

        $d = $details[$qid] ?? [];
        $website = trim((string)($d['website']['value'] ?? ($c['website']['value'] ?? '')));
        $phone   = trim((string)($d['phone']['value'] ?? ''));
        $lat = isset($c['lat']['value']) ? (float)$c['lat']['value'] : null;
        $lng = isset($c['lon']['value']) ? (float)$c['lon']['value'] : null;

        $rows[] = [
            'place_id'           => 'wikidata_' . $qid,
            'business_name'      => $name,
            'business_address'   => trim((string)($d['street']['value'] ?? '')),
            'business_phone'     => $phone,
            'business_email'     => trim((string)($d['email']['value'] ?? '')),
            'business_category'  => $industry,
            'business_city'      => $city,
            'country'            => '',
            'rating'             => null,
            'total_ratings'      => 0,
            'maps_url'           => 'https://www.wikidata.org/wiki/Q' . $qid,
            'website'            => $website,
            'source'             => 'wikidata',
            'lat'                => $lat,
            'lng'                => $lng,
            'business_hours'     => trim((string)($d['hours']['value'] ?? '')) ?: null,
            'price_level'        => null,
            'international_phone'=> $phone,
            'raw_payload'        => ['qid' => $qid],
            // The core signal: businesses Wikidata lists with no website on
            // record.  Surfaces the "No Website" badge in the UI.
            'no_website'         => ($website === ''),
            'opportunity_score'  => 0,
        ];
    }
    return $rows;
}

/**
 * Public entry point required by includes/lead_sources/_registry.php.
 *
 * @param string $city       City name (free text, e.g. "Austin, TX").
 * @param string $industry   Lowercase industry keyword (e.g. "restaurant").
 * @param array  $opts       Reserved: max_queries (default 3).
 * @return array             Normalized lead rows, deduped by Wikidata QID.
 */
function lead_source_wikidata_find(string $city, string $industry, array $opts = []): array {
    $max_queries = max(1, min(3, (int)($opts['max_queries'] ?? 2)));
    $bbox = _lead_sources_osm_bbox($city);
    if (!$bbox) return [];

    // Loosen a too-small city bbox so surrounding areas still surface.
    $bbox = [$bbox[0] - 0.02, $bbox[1] + 0.02, $bbox[2] - 0.02, $bbox[3] + 0.02];

    $keywords = lead_sources_expand_keywords($industry, $max_queries);
    $candidates = [];      // qid -> [item, itemLabel, lat, lon, website?]
    foreach ($keywords as $kw) {
        $res = _lead_sources_wikidata_retry(_lead_sources_wikidata_query_candidates($bbox, $kw, 30));
        if (!$res) continue;
        foreach (($res['results']['bindings'] ?? []) as $b) {
            $qid = preg_replace('/^.*?([0-9]+)$/', '$1', (string)($b['item']['value'] ?? ''));
            if ($qid === '' || isset($candidates[$qid])) continue;
            $lat = isset($b['lat']['value']) ? (float)$b['lat']['value'] : null;
            $lng = isset($b['lon']['value']) ? (float)$b['lon']['value'] : null;
            $candidates[$qid] = [
                'item'      => ['value' => $b['item']['value']],
                'itemLabel' => ['value' => $b['itemLabel']['value'] ?? ''],
                'website'   => $b['website'] ?? ['value' => ''],
                'lat'       => ['value' => $lat],
                'lon'       => ['value' => $lng],
            ];
        }
        if (count($candidates) >= 12) break;
    }
    if (!$candidates) return [];

    // One detail pass for every QID found.
    $details = [];
    $qids = array_keys($candidates);
    $res = _lead_sources_wikidata_http(_lead_sources_wikidata_query_details($qids, 120));
    if ($res) {
        foreach (($res['results']['bindings'] ?? []) as $b) {
            $qid = preg_replace('/^.*?([0-9]+)$/', '$1', (string)($b['item']['value'] ?? ''));
            if ($qid !== '') $details[$qid] = $b;
        }
    }

    return _lead_sources_wikidata_rows(array_values($candidates), $details, $city, $industry);
}