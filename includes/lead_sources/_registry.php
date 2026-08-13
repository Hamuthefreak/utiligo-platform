<?php
/**
 * includes/lead_sources/_registry.php
 *
 * Central source registry for the lead-generation workspace.  Each concrete
 * source is its own file in this directory (e.g. google_places.php, osm.php)
 * and is looked up here so:
 *   - portal/leads.php can render the "Sources" multi-select chip list
 *     without knowing about each source's internals;
 *   - api/find-leads.php can fan a search out to every source the user's
 *     plan allows without if/else spray.
 *
 * Each entry has:
 *   'key'         — the short id matched against plan_lead_sources($plan)
 *                   and stored in utiligo_leads.source.
 *   'label'       — human label for the UI chip.
 *   'icon'        — Font Awesome class for the chip.
 *   'color'       — Tailwind background tint hex for the chip.
 *   'file'        — file in this dir implementing `find($city,$industry,$opts)`.
 *
 * Sources are sorted in the order shown below (display priority).
 *
 * Adding a new source:
 *   1. Drop a new file in this directory (e.g. `your_source.php`) that
 *      declares `function find(string $city, string $industry, array $opts): array`.
 *   2. Add it to the array below.
 *   3. (admin-only step) add its key to PRO_LEAD_SOURCES / ENT_LEAD_SOURCES
 *      in includes/plan_limits.php if it should be gated.
 * No other file needs to change — portal/leads.php and api/find-leads.php
 * discover it from here automatically.
 */

function lead_source_registry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    $reg = [
        'google_places' => [
            'key'   => 'google_places',
            'label' => 'Google Places',
            'icon'  => 'fa-google',
            'color' => '#4285F4',
            'file'  => __DIR__ . '/google_places.php',
            // Google Places is special — its `find()` is a no-op wrapper
            // because api/find-leads.php's existing code path owns the
            // Google call (it predates the registry).  Other sources used
            // by this registry go through lead_sources_dispatch().
        ],
        'osm' => [
            'key'   => 'osm',
            'label' => 'OpenStreetMap',
            'icon'  => 'fa-map-location-dot',
            'color' => '#7CBA34',
            'file'  => __DIR__ . '/osm.php',
        ],
        'yelp' => [
            'key'   => 'yelp',
            'label' => 'Yelp',
            'icon'  => 'fa-yelp',
            'color' => '#D32323',
            'file'  => __DIR__ . '/yelp.php',
        ],
        'tomtom' => [
            'key'   => 'tomtom',
            'label' => 'TomTom',
            'icon'  => 'fa-location-crosshairs',
            'color' => '#E63312',
            'file'  => __DIR__ . '/tomtom.php',
        ],
        'wikidata' => [
            'key'   => 'wikidata',
            'label' => 'Wikidata',
            'icon'  => 'fa-wikipedia-w',
            'color' => '#FFFFFF',
            'file'  => __DIR__ . '/wikidata.php',
        ],
    ];
    return $reg;
}

/**
 * @return array keyed by source key — only sources the user's PLAN permits.
 *               Each value is the registry entry with a 'file' path the
 *               caller can require_once on first use.
 */
function lead_sources_for_plan(string $plan): array {
    $allowed = array_flip(plan_lead_sources($plan));   // fast membership test
    $out = [];
    foreach (lead_source_registry() as $key => $entry) {
        if (isset($allowed[$key])) $out[$key] = $entry;
    }
    return $out;
}
