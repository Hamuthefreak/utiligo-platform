<?php
/**
 * includes/lead_enrichment.php
 *
 * Lead enrichment dispatcher.  Each provider is a function in this file
 * named enrich_provider_<name>($lead, $context) that returns null on miss
 * or an array [['field'=>..., 'value'=>..., 'confidence'=>high|medium|low], ...]
 * of fields it filled in.  enrich_lead() runs every provider the user's plan
 * allows against one lead and writes the resulting rows into the
 * `lead_enrichments` table (one row per field per provider).
 *
 * enrichment ALSO writes back into utiligo_leads's denormalized convenience
 * columns (business_email, website, ...) so the running-lead-list query
 * and exports don't have to JOIN lead_enrichments to surface the most
 * commonly wanted values.
 *
 * This file is pure dispatcher + the first two providers (website_finder,
 * email_pattern) which are FREE (no API key).  email_verifier and
 * social_profiles will be added here too.  Each provider honors the global
 * "1 outbound HTTP req per second" contract by sharing the osm.php wait
 * clock via $GLOBALS['__osm_last_call_at'] — that variable is mis-named
 * for enrichment but is intentionally shared so we never exceed 1/s total
 * to ANY non-QPS-billed upstream in this request, regardless of provider
 * mix.
 */

/**
 * Public dispatcher.
 *
 * @param array  $lead     Full utiligo_leads row (associative).
 * @param array  $providers List from plan_enrich_providers($plan).
 * @param PDO    $pdb      Platform DB handle (utiligo_leads lives here).
 * @return int   Count of enrichments written.
 */
function enrich_lead(array $lead, array $providers, \PDO $pdb): int {
    $lead_id = (int) ($lead['id'] ?? 0);
    if ($lead_id <= 0 || !$providers) return 0;

    $written = 0;
    foreach ($providers as $name) {
        $fn = "enrich_provider_{$name}";
        if (!function_exists($fn)) continue;
        try {
            $results = $fn($lead);
        } catch (\Throwable $e) {
            // One provider failing must not stop the others.
            if (function_exists('log_error')) {
                log_error('enrich_' . $name, $e, ['lead_id' => $lead_id]);
            }
            continue;
        }
        if (!$results) continue;

        $stmt = $pdb->prepare(
            'INSERT INTO lead_enrichments (lead_id, provider, field, value, confidence)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), confidence = VALUES(confidence), found_at = NOW()'
        );
        foreach ($results as $r) {
            $field = (string) ($r['field'] ?? '');
            $value = (string) ($r['value'] ?? '');
            $conf  = in_array($r['confidence'] ?? '', ['high','medium','low'], true)
                   ? $r['confidence'] : 'medium';
            if ($field === '' || $value === '') continue;
            try {
                $stmt->execute([$lead_id, $name, $field, $value, $conf]);
                $written++;
            } catch (\Throwable $e) {
                // Schema miss / dup race: log + keep going.
                if (function_exists('log_error')) {
                    log_error('enrich_insert', $e, ['lead_id'=>$lead_id,'provider'=>$name,'field'=>$field]);
                }
            }

            // denormalize the most-common fields into utiligo_leads
            _enrich_backfill_lead_column($pdb, $lead_id, $lead, $field, $value);
        }
    }

    if ($written > 0) {
        try {
            $pdb->prepare('UPDATE utiligo_leads SET enriched_at = NOW() WHERE id = ? AND enriched_at IS NULL')
                ->execute([$lead_id]);
        } catch (\Throwable $e) {/* non-fatal */}
    }
    return $written;
}

/**
 * Backfill a denormalized field on utiligo_leads if it's empty / worse-than-
 * current.  Only writes if the new value looks better (non-empty when
 * empty, or higher-confidence — for now "any value beats empty").
 */
function _enrich_backfill_lead_column(\PDO $pdb, int $lead_id, array $lead, string $field, string $value): void {
    $map = [
        'website'         => 'website',
        'business_email'  => 'business_email',
        'business_phone'  => 'business_phone',
        'social_facebook' => 'social_facebook',
        'social_instagram'=> 'social_instagram',
        'social_linkedin' => 'social_linkedin',
        'social_yelp'     => 'social_yelp',
    ];
    // Only the two most-common columns are denormalized today; the others
    // live in lead_enrichments only.  If social_* is added to the table
    // later, add the column to this list.
    static $backfill_in_table = ['website', 'business_email'];
    $target = $map[$field] ?? null;
    if (!$target || !in_array($target, $backfill_in_table, true)) return;
    $existing = trim((string)($lead[$target] ?? ''));
    if ($existing !== '') return;   // never overwrite an already-known value
    try {
        $pdb->prepare("UPDATE utiligo_leads SET `$target` = ? WHERE id = ? AND ($target = '' OR $target IS NULL)")
            ->execute([$value, $lead_id]);
    } catch (\Throwable $e) {/* non-fatal */}
}

// API key + 1-equivalent-HTTP / cost constants for providers that want them.
function _enrich_http_wait(): void {
    $last = $GLOBALS['__osm_last_call_at'] ?? 0.0;
    if ($last <= 0.0) return;
    $elapsed = microtime(true) - $last;
    if ($elapsed < 1.05) usleep((int)((1.1 - $elapsed) * 1_000_000));
}
function _enrich_http_mark(): void {
    $GLOBALS['__osm_last_call_at'] = microtime(true);
}
function _enrich_http_get(string $url, int $timeout = 8): string {
    _enrich_http_wait();
    _enrich_http_mark();
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "User-Agent: Utiligo-Enrichment/1.0\r\n",
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    return is_string($r) ? $r : '';
}

// ── Provider: website_finder ───────────────────────────────────────────────
// Tries common domain patterns derived from the business name + city and
// does a HEAD/GET to confirm a 200/301/302. First hit wins.
function enrich_provider_website_finder(array $lead): array {
    $name = trim($lead['business_name'] ?? '');
    $city = trim($lead['business_city'] ?? '');
    if ($name === '') return [];
    if (!empty($lead['website'])) return [];   // already known from upstream
    $slug = _enrich_slugify($name);
    $candidates = [
        "https://$slug.com",
        "https://www.$slug.com",
        "https://$slug.net",
        "https://$slug.io",
        "https://$slug.co",
        // .com.au, .co.uk if city looks Australian / UK-ish — selected by country
    ];
    // Country-specific TLDs from raw_payload if present.
    $raw = $lead['raw_payload'] ?? null;
    if (is_array($raw) && !empty($raw['country'])) {
        $c = strtolower($raw['country']);
        if (str_contains($c, 'australia')) $candidates[] = "https://$slug.com.au";
        if (str_contains($c, 'united kingdom') || str_contains($c, ' uk')) $candidates[] = "https://$slug.co.uk";
        if (str_contains($c, 'canada')) $candidates[] = "https://$slug.ca";
    }
    foreach ($candidates as $url) {
        if (_enrich_url_responds($url)) {
            return [['field' => 'website', 'value' => $url, 'confidence' => 'medium']];
        }
    }
    return [];
}
function _enrich_slugify(string $name): string {
    $slug = preg_replace('/[^a-z0-9]/', '', strtolower($name));
    $slug = trim($slug, '-');
    return $slug !== '' && strlen($slug) <= 40
        ? $slug
        : substr($slug, 0, 40);
}
function _enrich_url_responds(string $url): bool {
    _enrich_http_wait();
    _enrich_http_mark();
    $ctx = stream_context_create(['http' => [
        'method'        => 'HEAD',
        'header'        => "User-Agent: Utiligo-Enrichment/1.0\r\n",
        'timeout'       => 5,
        'ignore_errors' => true,
        'follow_location' => 1,
        'max_redirects' => 3,
    ]]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) return false;
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    foreach ($meta['wrapper_data'] ?? [] as $h) {
        if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $h, $m)) {
            $code = (int)$m[1];
            // 200, 301, 302, 303, 307, 308 all mean "there is a website here".
            if ($code === 200 || ($code >= 301 && $code <= 308)) return true;
        }
    }
    return false;
}

// ── Provider: email_pattern ────────────────────────────────────────────────
// Given a website, fetch the homepage and regex out mailto: links + any
// visible email address.  Returns the first non-spammy-ish match as
// business_email, plus any extras as separate rows (so the user can browse
// them all in the slide-over panel).
function enrich_provider_email_pattern(array $lead): array {
    $website = trim($lead['website'] ?? '');
    if ($website === '') return [];
    if (!empty($lead['business_email'])) return [];  // upstream already has one

    $html = _enrich_http_get($website, 8);
    if ($html === '') return [];
    // Strip script/style first so we don't harvest JS-string emails.
    $stripped = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $html);
    $mails = [];
    // 1) mailto: links (highest signal).
    if (preg_match_all('/mailto:([^"?\'\s>]+)/i', $stripped, $m)) {
        foreach ($m[1] as $e) {
            $e = urldecode($e);
            if (_enrich_is_plausible_email($e)) $mails[$e] = true;
        }
    }
    // 2) Plain email regex on visible text.
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $stripped, $m2)) {
        foreach ($m2[0] as $e) {
            if (_enrich_is_plausible_email($e)) $mails[$e] = true;
        }
    }
    // Filter out common junk.
    static $blocklist = ['example.com','sentry.io','wixpress.com','godaddy.com','squarespace.com','example.org'];
    $mails = array_filter(array_keys($mails), function ($e) use ($blocklist) {
        $dom = strtolower(substr(strrchr($e, '@'), 1));
        foreach ($blocklist as $b) if (str_ends_with($dom, $b)) return false;
        // filter images/pdf extensions disguised as emails.
        if (preg_match('/\.(png|jpg|jpeg|gif|webp|svg|pdf|css|js)$/i', $e)) return false;
        return true;
    });
    if (!$mails) return [];
    $mails = array_values($mails);
    $primary = $mails[0];
    $out = [['field' => 'business_email', 'value' => $primary, 'confidence' => 'medium']];
    foreach (array_slice($mails, 1, 5) as $extra) {
        $out[] = ['field' => 'business_email_extra', 'value' => $extra, 'confidence' => 'low'];
    }
    return $out;
}
function _enrich_is_plausible_email(string $e): bool {
    if (strlen($e) < 6 || strlen($e) > 254) return false;
    if (substr_count($e, '@') !== 1) return false;
    [$user, $dom] = explode('@', $e, 2);
    if ($user === '' || $dom === '' || !str_contains($dom, '.')) return false;
    return true;
}

// ── Provider: email_verifier (Ent only) ────────────────────────────────────
function enrich_provider_email_verifier(array $lead): array {
    $email = trim($lead['business_email'] ?? '');
    if ($email === '') return [];
    [$user, $dom] = explode('@', $email, 2);
    if (!$user || !$dom) return [];

    // DNS check needs getmxrr — not on all platforms.  If missing, we degrade.
    if (!function_exists('getmxrr')) return [];
    $mx = [];
    if (!@getmxrr($dom, $mx) || !$mx) {
        return [['field'=>'email_dns_status','value'=>'no_mx','confidence'=>'high']];
    }

    // SMTP RCPT probe with a fake MAIL FROM.  InfinityFree blocks outbound
    // 25, so this will fail with ECONNREFUSED there — but the caller
    // (cron/enrich_leads.php) wraps it in try/catch and we mark low trust.
    $errno = 0; $errstr = '';
    $fp = @fsockopen($mx[0], 25, $errno, $errstr, 4);
    if (!$fp) return [['field'=>'email_dns_status','value'=>'mx_unreachable','confidence'=>'low']];
    stream_set_timeout($fp, 4);
    $read = function() use ($fp) { return fgets($fp, 512) ?: ''; };

    $banner = $read();                                          // 220
    fputs($fp, "EHLO mail.utiligo.example\r\n");
    $ehlo = $read();
    fputs($fp, "MAIL FROM:<probe-bounces@utiligo.example>\r\n");
    $mail = $read();
    fputs($fp, "RCPT TO:<$email>\r\n");
    $rcpt = trim($read());
    fputs($fp, "QUIT\r\n");
    fclose($fp);

    // 250/251 = yes.  550+mailbox/disabled = no.  everything else = unsure.
    $code = (int) substr($rcpt, 0, 3);
    if ($code === 250 || $code === 251) { $value = 'verified';  $conf = 'high'; }
    elseif ($code >= 550)               { $value = 'invalid';   $conf = 'medium'; }
    else                                { $value = 'unknown';   $conf = 'low'; }
    return [['field'=>'email_dns_status','value'=>"$value ($rcpt)",'confidence'=>$conf]];
}

// ── Provider: social_profiles (Ent only) ──────────────────────────────────
// Scrape the lead's website for links to FB / IG / LinkedIn / Yelp.
function enrich_provider_social_profiles(array $lead): array {
    $website = trim($lead['website'] ?? '');
    if ($website === '') return [];
    $html = _enrich_http_get($website, 8);
    if ($html === '') return [];

    $patterns = [
        'social_facebook'  => '/https?:\/\/(?:www\.)?facebook\.com\/[A-Za-z0-9._\-\/?=&]+/i',
        'social_instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/[A-Za-z0-9._\-\/?=&]+/i',
        'social_linkedin'  => '/https?:\/\/(?:[a-z]{2,3}\.)?linkedin\.com\/(?:company|in)\/[A-Za-z0-9._\-\/?=&]+/i',
        'social_yelp'      => '/https?:\/\/(?:www\.)?yelp\.com\/biz\/[A-Za-z0-9._\-\/?=&]+/i',
    ];
    $out = [];
    foreach ($patterns as $field => $re) {
        $hits = [];
        if (preg_match_all($re, $html, $m)) $hits = array_unique($m[0]);
        if ($hits) {
            $v = $hits[0];
            // strip query string/dupes from the URL
            $v = preg_replace('/[?#].*$/', '', $v);
            $out[] = ['field' => $field, 'value' => $v, 'confidence' => 'medium'];
        }
    }
    return $out;
}
