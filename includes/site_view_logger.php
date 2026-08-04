<?php
/**
 * includes/site_view_logger.php
 * Shared helper: log one detailed row per site view into utiligo_site_view_log.
 *
 * Usage:
 *   require_once __DIR__ . '/site_view_logger.php';
 *   log_site_view((int)$site_id, $pdo);
 *
 * Never throws — safe to call on the hot path of s.php / api/track_view.php.
 */

if (!function_exists('log_site_view')) {

    /**
     * Ensure the log table exists (idempotent, cheap).
     */
    function site_view_log_ensure_table(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS utiligo_site_view_log (
              id         BIGINT AUTO_INCREMENT PRIMARY KEY,
              site_id    INT NOT NULL,
              viewed_at  DATETIME NOT NULL DEFAULT NOW(),
              referrer   VARCHAR(300) DEFAULT NULL,
              country    VARCHAR(4)   DEFAULT NULL,
              device     ENUM('desktop','mobile','tablet') DEFAULT 'desktop',
              INDEX idx_site_date (site_id, viewed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }

    /**
     * Detect device class from User-Agent.
     */
    function site_view_detect_device(string $ua): string
    {
        $ua = strtolower($ua);
        if ($ua === '') return 'desktop';
        // Tablets first — many tablet UAs also contain "mobile"
        if (preg_match('/ipad|tablet|kindle|playbook|silk|sm-t|gt-p|nexus 7|nexus 9|nexus 10/', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobi|android(?!.*tablet)|iphone|ipod|blackberry|windows phone|opera mini|iemobile/', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Return true if the UA looks like a bot/crawler/health-check.
     */
    function site_view_is_bot(string $ua): bool
    {
        if ($ua === '') return true; // empty UA on a beacon call = bot / curl
        $ua = strtolower($ua);
        $needles = [
            'bot','crawl','spider','slurp','scrape','scraper','curl','wget','httpclient',
            'python-requests','python-urllib','go-http-client','java/','okhttp',
            'headless','phantom','puppeteer','playwright','lighthouse','pagespeed',
            'pingdom','uptimerobot','statuscake','datadog','newrelic',
            'facebookexternalhit','twitterbot','linkedinbot','slackbot','discordbot',
            'whatsapp','telegrambot','preview','embedly','quora link preview',
            'ahrefs','semrush','mj12bot','dotbot','petalbot','bytespider',
        ];
        foreach ($needles as $n) {
            if (strpos($ua, $n) !== false) return true;
        }
        return false;
    }

    /**
     * Reduce a full referrer URL to just its host (e.g. "google.com", "facebook.com").
     * Returns null for empty / same-site referrers (counts as Direct).
     */
    function site_view_trim_referrer(?string $ref): ?string
    {
        if (!$ref) return null;
        $host = parse_url($ref, PHP_URL_HOST);
        if (!$host) return null;
        $host = strtolower($host);
        // Strip leading www.
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        // Same-site = direct
        if ($host === 'utiligo.ca' || substr($host, -12) === '.utiligo.ca') return null;
        // Common search engines → collapse variants
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host)) return 'google.com';
        if (preg_match('/(^|\.)bing\.com$/', $host))       return 'bing.com';
        if (preg_match('/(^|\.)duckduckgo\.com$/', $host)) return 'duckduckgo.com';
        if (preg_match('/(^|\.)yahoo\.[a-z.]+$/', $host))   return 'yahoo.com';
        // Social shorteners
        if ($host === 't.co') return 'twitter.com';
        if ($host === 'l.facebook.com' || $host === 'lm.facebook.com') return 'facebook.com';
        if ($host === 'out.reddit.com') return 'reddit.com';
        return substr($host, 0, 200);
    }

    /**
     * Main entry point. Returns true if a row was written.
     */
    function log_site_view(int $site_id, PDO $pdo): bool
    {
        if ($site_id <= 0) return false;
        try {
            $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (site_view_is_bot($ua)) return false;

            site_view_log_ensure_table($pdo);

            $device   = site_view_detect_device($ua);
            $referrer = site_view_trim_referrer($_SERVER['HTTP_REFERER'] ?? null);
            $country  = null;
            // Optional: if you ever add Cloudflare / proxy geo headers, read them here
            if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
                $country = substr(strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']), 0, 4);
            }

            $stmt = $pdo->prepare("
                INSERT INTO utiligo_site_view_log (site_id, viewed_at, referrer, country, device)
                VALUES (?, NOW(), ?, ?, ?)
            ");
            $stmt->execute([$site_id, $referrer, $country, $device]);
            return true;
        } catch (\Throwable $e) {
            error_log('[site_view_logger] ' . $e->getMessage());
            return false;
        }
    }
}
