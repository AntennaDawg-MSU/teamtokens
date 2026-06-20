<?php
// backend/includes/helpers.php

// ── JSON response helpers ─────────────────────────────────────────────────────
function json_ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

// ── CORS headers (adjust origin in production) ────────────────────────────────
function cors_headers(): void {
    $allowed = getenv('FRONTEND_ORIGIN') ?: 'https://your-org.github.io';
    header("Access-Control-Allow-Origin: $allowed");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Read JSON body ────────────────────────────────────────────────────────────
function json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid JSON body');
    }
    return $data;
}

// ── Input sanitisation ────────────────────────────────────────────────────────
function sanitise_string(mixed $v, int $max = 255): string {
    return mb_substr(trim((string)$v), 0, $max);
}

function sanitise_int(mixed $v): int {
    return (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
}

function sanitise_netid(string $netid): string {
    // NetIDs: alphanumeric + underscore, max 50 chars
    return preg_replace('/[^a-zA-Z0-9_]/', '', mb_substr($netid, 0, 50));
}

function sanitise_grade(string $g): string {
    $g = strtoupper(trim($g));
    return in_array($g, ['A','B','C','D','F'], true) ? $g : '';
}

// ── Validate required fields ──────────────────────────────────────────────────
function require_fields(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') {
            json_error("Missing required field: $f");
        }
    }
}

// ── CSV utilities ─────────────────────────────────────────────────────────────
function parse_csv_upload(string $key): array {
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        json_error('CSV upload failed or missing');
    }
    $file = $_FILES[$key]['tmp_name'];
    $rows = [];
    if (($fh = fopen($file, 'r')) !== false) {
        $headers = fgetcsv($fh);
        if (!$headers) { json_error('CSV is empty'); }
        $headers = array_map('trim', $headers);
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = array_combine($headers, array_map('trim', $row));
        }
        fclose($fh);
    }
    return $rows;
}

// ── Excel / CSV export helpers ────────────────────────────────────────────────
function send_csv(string $filename, array $rows): never {
    if (empty($rows)) { json_error('No data to export'); }
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $fh = fopen('php://output', 'w');
    fputcsv($fh, array_keys($rows[0]));
    foreach ($rows as $row) { fputcsv($fh, $row); }
    fclose($fh);
    exit;
}
