<?php
/**
 * includes/lead_sources/google_places.php
 *
 * Stub.  The Google Places Text Search + Place Details path is owned
 * end-to-end by api/find-leads.php — it was built before the source
 * registry in includes/lead_sources/_registry.php existed and has its own
 * quota / cache / quota-table logic that we don't want to rip out.
 *
 * This file is here so that `require_once lead_source_registry()['google_places']['file']`
 * never crashes when the registry is iterated generically, and so that a
 * future refactor can move the Google call into lead_source_google_places_find()
 * without touching any caller.
 *
 * When that happens, copy the body of api/find-leads.php's Google section
 * into lead_source_google_places_find() here and have api/find-leads.php
 * delegate to it through the source registry.
 */

/**
 * No-op fallback. Falls back to api/find-leads.php's existing Google code,
 * so callers of the dispatcher should treat 'google_places' specially: if
 * present in the user's source list, they should call api/find-leads.php's
 * existing logic directly (which already deducts quota etc.).
 *
 * @return array Always empty.
 */
function lead_source_google_places_find(string $city, string $industry, array $opts = []): array {
    return [];
}
