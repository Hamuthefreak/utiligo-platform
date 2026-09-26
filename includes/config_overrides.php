<?php
/**
 * includes/config_overrides.php — is the file the settings page writes actually
 * being loaded?
 *
 * THE FAILURE THIS EXISTS FOR
 * ──────────────────────────
 * storage/config_overrides.php is the one file the admin form can write, and it is
 * deliberately not in the repository. config.php is excluded from the FTP deploy,
 * so the copy on the live server is edited by hand and can be older than the one
 * here. If that copy has no `require_once` of the overrides file — or defines these
 * constants *above* it — then every save writes a file that nothing reads. The
 * operator gets "Settings saved", the Payments page keeps saying "not set", and
 * nothing anywhere explains it, because the two halves of that sentence live in
 * different files on different machines.
 *
 * So the app asks itself the one question that separates those two cases: does what
 * the FILE says match what THIS process is running with? The file is written in one
 * shape — a single-line define() per key — and config.php loads it before every
 * other definition, and every later define() in config.php is `!defined()`-guarded,
 * so anything in the file wins. A key whose file value is not the running value can
 * therefore only mean the file is not being loaded.
 *
 * It reports KEY NAMES ONLY. Not a value, not a prefix, not a length. The file holds
 * the database password and the payment keys, and the rule everywhere this state is
 * displayed (see admin/payments.php) is that a secret is reported as set or missing
 * and never printed.
 *
 * @see admin/settings.php   the writer, and the page that shows this check
 * @see admin/payments.php   where an operator lands when payments are "not set"
 */

/**
 * Where the overrides live: one definition of the path, in one file.
 *
 * admin/settings.php used to spell the path out itself and includes/plan_limits.php
 * still does; this is the copy new callers should use, so the check and the writer
 * cannot end up looking at two different files.
 */
function config_overrides_file(): string
{
    return __DIR__ . '/../storage/config_overrides.php';
}

/**
 * One literal out of that file, read the way PHP would read it.
 *
 * The writer emits single-quoted strings escaped with addcslashes() and bare
 * true/false/numbers, so those are the four shapes this has to understand. The
 * unescaping is a single pass — a value ending in an escaped backslash must not
 * have its neighbours re-interpreted.
 */
function config_overrides_literal(string $raw)
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if ($raw[0] === "'") {
        return (string)preg_replace_callback(
            '/\\\\(.)/',
            static fn(array $m): string => $m[1],
            substr($raw, 1, -1)
        );
    }

    $lower = strtolower($raw);
    if ($lower === 'true')  { return true; }
    if ($lower === 'false') { return false; }
    if (is_numeric($raw))   { return str_contains($raw, '.') ? (float)$raw : (int)$raw; }

    return $raw;
}

/** bool, int, float and string in one spelling, so the two sides can be compared. */
function config_overrides_canonical($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return (string)$value;
}

/**
 * The define()s the file on disk states, as key => value.
 *
 * Line-based and greedy to the last `);` on the line, so a value that contains a
 * semicolon — a database password, typically — still parses.
 *
 * @return array<string, mixed>
 */
function config_overrides_defines(?string $path = null): array
{
    $path = $path ?? config_overrides_file();
    if (!is_file($path)) {
        return [];
    }
    $src = (string)@file_get_contents($path);
    if ($src === '') {
        return [];
    }

    $out = [];
    if (preg_match_all("/^\\s*define\\s*\\(\\s*'([A-Za-z0-9_]+)'\\s*,\\s*(.*)\\s*\\)\\s*;\\s*$/m", $src, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $out[$match[1]] = config_overrides_literal($match[2]);
        }
    }

    return $out;
}

/**
 * The keys the file states that the running process disagrees with.
 *
 * `mismatched` non-empty means the file is there and is not being loaded. An empty
 * `mismatched` with `read` true means the two agree, which is the state every save
 * has to end in for the values to mean anything.
 *
 * @return array{file: string, read: bool, stated: int, mismatched: list<string>}
 */
function config_overrides_mismatch(?string $path = null): array
{
    $path   = $path ?? config_overrides_file();
    $stated = config_overrides_defines($path);
    $names  = [];

    foreach ($stated as $key => $value) {
        // Not defined at all is the loudest form of the same thing: the file says
        // this key is set and the running process has never heard of it.
        if (!defined($key)) {
            $names[] = $key;
            continue;
        }
        if (config_overrides_canonical(constant($key)) !== config_overrides_canonical($value)) {
            $names[] = $key;
        }
    }

    return [
        'file'       => $path,
        'read'       => is_file($path),
        'stated'     => count($stated),
        'mismatched' => $names,
    ];
}

/**
 * The one line a live config.php is missing, in one place.
 *
 * Printed by the settings page and inserted by the repair below, so the sentence the
 * operator reads and the line that gets written cannot drift apart.
 */
function config_overrides_loader_line(): string
{
    return "require_once __DIR__ . '/storage/config_overrides.php';";
}

/**
 * What this installation's config.php says about the overrides file.
 *
 * config_overrides_mismatch() answers "is what the file STATES in effect" — but it
 * needs something saved to compare against, so on the day the file is still empty it
 * says nothing at all. This one reads the other half of the arrangement directly, and
 * it can answer before a single value has been saved: does the config.php in this
 * installation mention the file, is that copy writable, and does the mention come
 * BEFORE the first define()? (A loader after the definitions is the same as no loader,
 * because the first define() wins.)
 *
 * The name of a file is not a secret, so unlike the values this may be shown.
 *
 * @return array{file: string, exists: bool, writable: bool, mentions: ?bool, above_defines: ?bool, first_define_line: ?int, mention_line: ?int}
 */
function config_overrides_reader_status(?string $configPath = null): array
{
    $configPath = $configPath ?? dirname(__DIR__) . '/config.php';

    $status = [
        'file'              => $configPath,
        'exists'            => is_file($configPath),
        'writable'          => false,
        'mentions'          => null,
        'above_defines'     => null,
        'first_define_line' => null,
        'mention_line'      => null,
    ];
    if (!$status['exists']) {
        return $status;
    }

    $status['writable'] = is_writable($configPath);
    $src = (string)@file_get_contents($configPath);
    $status['mentions'] = str_contains($src, 'config_overrides');

    $lineOf = static function (int $offset) use ($src): int {
        return substr_count(substr($src, 0, $offset), "\n") + 1;
    };

    // Horizontal whitespace only: `\s` also matches the newline before the line, which
    // reports a definition one line later than it is — the number an operator is asked
    // to go and look at, so it has to be the right one.
    if (preg_match('/^[ \t]*define\s*\(/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        $status['first_define_line'] = $lineOf($m[0][1]);
    }
    if ($status['mentions'] && preg_match('/^.*config_overrides.*$/m', $src, $mm, PREG_OFFSET_CAPTURE)) {
        $status['mention_line'] = $lineOf($mm[0][1]);
    }

    $status['above_defines'] = $status['mentions']
        && ($status['first_define_line'] === null || $status['mention_line'] < $status['first_define_line']);

    return $status;
}

/**
 * Write a canary next to the overrides file, and report the bytes and the path.
 *
 * The complaint this answers is "I saved it and the file is still empty" — which has
 * two completely different causes that look identical from a file listing: the write
 * failing, or the operator's FTP client being open on a second copy of the site (an
 * addon domain's own htdocs/ next to the primary one, historically). A probe file in
 * the same directory settles it without either side having to guess: if the bytes were
 * written and the file is not in the listing, the listing is the wrong folder.
 *
 * It is inert — it defines nothing, prints nothing, and storage/ is refused to HTTP by
 * the root .htaccess — and safe to delete.
 *
 * @return array{file: string, bytes: int|false, mtime: int, error: string}
 */
function config_overrides_write_probe(?string $dir = null): array
{
    $dir  = $dir ?? dirname(config_overrides_file());
    $file = $dir . '/_utiligo_write_test.php';
    $body = "<?php\n"
        . "// Written by the write test on admin/settings.php at " . gmdate('Y-m-d H:i:s') . " UTC.\n"
        . "// Inert on purpose: it defines nothing and prints nothing. Safe to delete.\n"
        . 'return ' . var_export(time(), true) . ";\n";

    $written = @file_put_contents($file, $body);
    $last    = $written === false ? error_get_last() : null;

    return [
        'file'  => $file,
        'bytes' => $written === false ? false : (int)$written,
        'mtime' => $written === false ? 0 : (int)@filemtime($file),
        'error' => $written === false ? (string)($last['message'] ?? 'file_put_contents() returned false') : '',
    ];
}

/**
 * Put the missing require_once into this server's config.php.
 *
 * WHY THIS EXISTS AT ALL
 * ──────────────────────
 * config.php is excluded from the FTP deploy, because the live copy holds the database
 * credentials and must not be overwritten by a push. That exclusion is correct and is
 * also the whole bug: a file that only a human may edit is a file that silently falls
 * behind, and every setting on the settings page is written into a file this one line
 * decides whether anything reads. Telling an operator to hand-edit it over FTP is a
 * step that can be skipped, forgotten or done to the wrong copy of the site — and then
 * the page reports "saved" while the Payments page reports "not set", with nothing
 * connecting the two.
 *
 * So the app that writes the file can also repair the reader, once, on an explicit
 * click of an admin-only button.
 *
 * WHAT KEEPS IT SAFE
 * ───────────────────
 *   • it refuses a config.php that already mentions the file — the require is then not
 *     what is wrong, and a second one would be no help;
 *   • the edit is a pure insertion after `<?php`: the original bytes are still there
 *     after it, verified by asserting both sides match the source exactly;
 *   • the result has to parse — token_get_all(TOKEN_PARSE) throws on a syntax error;
 *   • a dated backup is copied into storage/ BEFORE anything replaces the original;
 *   • the new bytes go to a temp file and are renamed over the original, so a half
 *     written config.php (which would take the whole site down) cannot exist;
 *   • any step that fails leaves config.php untouched and says which step it was.
 *
 * @return array{ok: bool, message: string, backup: ?string}
 */
function config_overrides_repair_reader(?string $configPath = null): array
{
    $configPath = $configPath ?? dirname(__DIR__) . '/config.php';
    $fail = static fn(string $why): array => ['ok' => false, 'message' => $why, 'backup' => null];

    if (!is_file($configPath)) {
        return $fail('config.php was not found at ' . $configPath . ', so there was nothing to repair.');
    }
    $src = @file_get_contents($configPath);
    if (!is_string($src) || $src === '') {
        return $fail('config.php could not be read, so nothing was changed.');
    }
    if (strlen($src) > 512 * 1024) {
        return $fail('config.php is unexpectedly large — this page refuses to edit it.');
    }
    if (str_contains($src, 'config_overrides')) {
        return $fail('This config.php already mentions storage/config_overrides.php, so a missing require is not what is wrong with it. Nothing was changed.');
    }
    if (!preg_match('/<\?php\s/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $fail('config.php does not open with <?php — refusing to edit a file this page does not recognise.');
    }

    $snippet = "\n"
        . "// ── Load admin-managed config overrides FIRST ─────────────────────────\n"
        . "// Written by admin/settings.php. It has to be loaded before every define()\n"
        . "// below, or the values saved there are written and then ignored.\n"
        . "if (is_file(__DIR__ . '/storage/config_overrides.php')) {\n"
        . "    require_once __DIR__ . '/storage/config_overrides.php';\n"
        . "}\n";

    $at  = $m[0][1] + strlen($m[0][0]);
    $new = substr($src, 0, $at) . $snippet . substr($src, $at);

    try {
        token_get_all($new, TOKEN_PARSE);
    } catch (\Throwable $e) {
        return $fail('The edited file would not parse (' . $e->getMessage() . ') — nothing was written.');
    }
    if (strlen($new) !== strlen($src) + strlen($snippet)
        || substr($new, 0, $at) !== substr($src, 0, $at)
        || substr($new, $at + strlen($snippet)) !== substr($src, $at)) {
        return $fail('The edit did not come out as a pure insertion — nothing was written.');
    }

    $backupDir = dirname($configPath) . '/storage';
    if (!is_dir($backupDir)) {
        return $fail('There is no storage/ directory next to config.php to keep a backup in — nothing was written.');
    }
    $backup = $backupDir . '/config.php.backup-' . date('Ymd-His') . '.php';

    $tmp = $configPath . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $new) === false) {
        return $fail('The directory holding config.php refused the write, so config.php is unchanged. Check its permissions.');
    }
    if (!@copy($configPath, $backup)) {
        @unlink($tmp);
        return $fail('Could not keep a backup of config.php, so it was left alone.');
    }
    if (!@rename($tmp, $configPath)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'A backup was written (' . $backup . ') but config.php could not be replaced. The original is unchanged.', 'backup' => $backup];
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }

    return [
        'ok'      => true,
        'message' => 'config.php now loads storage/config_overrides.php before every define(). Reload this page — the values saved here take effect from that request on.',
        'backup'  => $backup,
    ];
}
