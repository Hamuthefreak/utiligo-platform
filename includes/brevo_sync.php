<?php
/**
 * includes/brevo_sync.php
 * Syncs a user to the correct Brevo contact lists based on their plan.
 *
 * Usage:
 *   require_once __DIR__ . '/brevo_sync.php';
 *   brevo_sync_user($userId, 'pro');        // after upgrade
 *   brevo_sync_user($userId, 'free');       // after downgrade / cancellation
 *   brevo_sync_user($userId, 'entrepreneur');
 *
 * This function:
 *   1. Adds the contact to BREVO_LIST_ALL_USERS (always)
 *   2. Adds the contact to the plan-specific list
 *   3. Removes the contact from all OTHER plan-specific lists
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';

function brevo_sync_user(int $userId, string $plan): void
{
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === 'YOUR_BREVO_API_KEY') return;

    $userdb = get_user_db();
    $stmt   = $userdb->prepare('SELECT email, full_name FROM utiligo_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user   = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return;

    $email    = $user['email'];
    $fullName = $user['full_name'] ?? '';
    $firstName = explode(' ', trim($fullName))[0] ?? '';
    $lastName  = trim(substr($fullName, strlen($firstName))) ?: '';

    // Map plan -> list ID
    $planListMap = [
        'free'         => BREVO_LIST_FREE_USERS,
        'pro'          => BREVO_LIST_PRO_USERS,
        'entrepreneur' => defined('BREVO_LIST_ENT_USERS') ? BREVO_LIST_ENT_USERS : BREVO_LIST_PRO_USERS,
    ];
    $targetList = $planListMap[$plan] ?? BREVO_LIST_FREE_USERS;

    // Lists to remove from (all plan-specific lists except the new one)
    $allPlanLists = array_unique(array_values($planListMap));
    $removeLists  = array_filter($allPlanLists, fn($l) => $l !== $targetList);

    $payload = [
        'email'      => $email,
        'attributes' => ['FIRSTNAME' => $firstName, 'LASTNAME' => $lastName, 'PLAN' => $plan],
        'listIds'    => array_unique([BREVO_LIST_ALL_USERS, $targetList]),
        'updateEnabled' => true,
    ];
    if (!empty($removeLists)) {
        $payload['unlinkListIds'] = array_values($removeLists);
    }

    _brevo_api_request('POST', 'https://api.brevo.com/v3/contacts', $payload);
}

function _brevo_api_request(string $method, string $url, array $payload = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}
