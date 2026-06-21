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

// Temporary debug — look up user directly
$db   = get_db();
$stmt = $db->prepare('SELECT id, netid, role, shibboleth_hash FROM users WHERE netid = ?');
$stmt->execute([$netid]);
$user = $stmt->fetch();

json_ok([
    'user_found'       => $user ? true : false,
    'role'             => $user['role'] ?? null,
    'has_hash'         => !empty($user['shibboleth_hash']),
    'hash_prefix'      => $user ? substr($user['shibboleth_hash'], 0, 7) : null,
    'verify_result'    => $user ? password_verify($shibboleth, $user['shibboleth_hash']) : false,
    'shibboleth_length'=> strlen($shibboleth),
]);
