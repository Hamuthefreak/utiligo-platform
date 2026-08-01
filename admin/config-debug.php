<?php
/**
 * admin/config-debug.php
 * TEMPORARY DEBUG PAGE — delete after diagnosis.
 * Shows exactly what session + DB state require_admin() sees.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html><html><head><title>Admin Debug</title>
<style>body{background:#0f172a;color:#e2e8f0;font-family:monospace;padding:2rem;}table{border-collapse:collapse;width:100%}td,th{border:1px solid #334155;padding:8px 14px;text-align:left}th{background:#1e293b;color:#94a3b8}.ok{color:#34d399}.fail{color:#f87171}.warn{color:#fbbf24}</style>
</head><body>
<h2 style="color:#a78bfa">Admin Auth Debug</h2>

<?php
$uid    = $_SESSION['user_id'] ?? null;
$sessOk = !empty($uid);

$user      = null;
$isAdmin   = false;
$isBanned  = false;
$dbError   = null;

if ($sessOk) {
    try {
        $pdo   = get_user_db();
        $stmt  = $pdo->prepare('SELECT id, email, full_name, is_admin, subscription_status FROM utiligo_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$uid]);
        $user  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $isAdmin  = !empty($user['is_admin']);
            $isBanned = ($user['subscription_status'] ?? '') === 'banned';
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$sessIp      = $_SESSION['admin_session_ip']   ?? null;
$lastActive  = $_SESSION['admin_last_active']  ?? null;
$serverIp    = $_SERVER['REMOTE_ADDR']          ?? 'unknown';
$idleSeconds = $lastActive ? (time() - $lastActive) : null;
$idleLimit   = ADMIN_SESSION_IDLE_SECONDS;

$checks = [
    ['session user_id set',    $sessOk,                                          $sessOk ? $uid : 'MISSING — not logged in'],
    ['user found in DB',       (bool)$user,                                      $user ? $user['email'] : ($dbError ?? 'not found')],
    ['is_admin = 1',           $isAdmin,                                         $isAdmin ? 'YES' : ('NO — value is: ' . ($user['is_admin'] ?? 'NULL'))],
    ['not banned',             !$isBanned,                                       $isBanned ? 'BANNED' : 'ok'],
    ['session IP matches',     !$sessIp || $sessIp === $serverIp,                $sessIp ? "stored=$sessIp  current=$serverIp" : 'not set yet (first admin visit)'],
    ['idle timeout ok',        $idleSeconds === null || $idleSeconds < $idleLimit, $idleSeconds !== null ? "{$idleSeconds}s idle (limit {$idleLimit}s)" : 'no timer yet'],
];
?>

<table>
<tr><th>Check</th><th>Pass?</th><th>Detail</th></tr>
<?php foreach ($checks as [$label, $pass, $detail]): ?>
<tr>
  <td><?= htmlspecialchars($label) ?></td>
  <td class="<?= $pass ? 'ok' : 'fail' ?>"><?= $pass ? '✓ PASS' : '✗ FAIL' ?></td>
  <td><?= htmlspecialchars((string)$detail) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h3 style="color:#94a3b8;margin-top:2rem">Raw SESSION keys</h3>
<pre style="background:#1e293b;padding:1rem;border-radius:8px"><?php
$safe = $_SESSION;
unset($safe['admin_csrf']); // don't expose tokens
print_r($safe);
?></pre>

<p style="color:#475569;margin-top:2rem;font-size:.8rem">
  Delete <code>admin/config-debug.php</code> once you have diagnosed the issue.
</p>
</body></html>
