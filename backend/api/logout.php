<?php
// backend/api/logout.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

cors_headers();
logout();
json_ok('Logged out');
