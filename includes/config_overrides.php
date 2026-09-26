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
