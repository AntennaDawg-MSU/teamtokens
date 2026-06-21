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

session_start_secure();
$result = login($netid, $shibboleth);

if ($result === false) {
    json_error('Invalid NetID or Shibboleth code, or no assignment is currently open', 401);
}

if (isset($result['locked']) && $result['locked']) {
    json_error('You have already submitted this assignment. You will be able to log in again when the next assignment opens.', 403);
}

json_ok([
    'name' => $result['name'],
    'netid' => $result['netid'],
    'role'  => $result['role'],
]);
