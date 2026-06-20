<?php
// backend/api/login.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body   = json_body();
$netid  = sanitise_netid($body['netid'] ?? '');
$pass   = $body['password'] ?? '';

if ($netid === '' || $pass === '') {
    json_error('NetID and password are required');
}

$user = login($netid, $pass);
if (!$user) {
    json_error('Invalid NetID or password', 401);
}

json_ok([
    'name'       => $user['name'],
    'netid'      => $user['netid'],
    'role'       => $user['role'],
    'must_reset' => (bool) $user['must_reset'],
]);
