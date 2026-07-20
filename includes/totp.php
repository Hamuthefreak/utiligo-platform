<?php
/**
 * includes/totp.php
 * Zero-dependency RFC 6238 TOTP implementation.
 * Compatible with Google Authenticator, Authy, 1Password, etc.
 */

function totp_generate_secret(int $bytes = 20): string {
    $raw    = random_bytes($bytes);
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $result = '';
    $buffer = 0;
    $bitsLeft = 0;
    foreach (str_split($raw) as $ch) {
        $buffer    = ($buffer << 8) | ord($ch);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $result   .= $base32[($buffer >> $bitsLeft) & 0x1F];
        }
    }
    if ($bitsLeft > 0) $result .= $base32[($buffer << (5 - $bitsLeft)) & 0x1F];
    return $result;
}

function _totp_base32_decode(string $secret): string {
    $base32   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $result   = '';
    $buffer   = 0;
    $bitsLeft = 0;
    foreach (str_split($secret) as $ch) {
        $val = strpos($base32, $ch);
        if ($val === false) continue;
        $buffer    = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result   .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $result;
}

function _totp_code(string $secret, int $counter): string {
    $key    = _totp_base32_decode($secret);
    $msg    = pack('J', $counter);
    $hash   = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code   = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code.
 * Window of ±2 steps (60 seconds either side) to absorb clock drift and slow typing.
 */
function totp_verify(string $secret, string $code, int $window = 2): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^[0-9]{6}$/', $code)) return false;
    $counter = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(_totp_code($secret, $counter + $i), $code)) return true;
    }
    return false;
}

function totp_uri(string $secret, string $email, string $issuer = 'Utiligo'): string {
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($email),
        rawurlencode($secret),
        rawurlencode($issuer)
    );
}
