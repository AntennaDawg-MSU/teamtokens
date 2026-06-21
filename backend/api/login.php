<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body       = json_body();
$netid      = sanitise_netid($body['netid']      ?? '');
$shibboleth = sanitise_string($body['shibboleth'] ?? '', 255);

if ($netid === '' || $shibboleth === '') {
    json_error('NetID and Shibboleth code are required');
}

$db   = get_db();
$stmt = $db->prepare('SELECT * FROM users WHERE netid = ?');
$stmt->execute([$netid]);
$user = $stmt->fetch();

if (!$user) {
    json_error('User not found', 401);
}

$verify = password_verify($shibboleth, $user['shibboleth_hash']);

json_ok([
    'role'          => $user['role'],
    'verify'        => $verify,
    'shib_received' => $shibboleth,
    'shib_length'   => strlen($shibboleth),
    'hash_stored'   => substr($user['shibboleth_hash'], 0, 10) . '...',
]);
