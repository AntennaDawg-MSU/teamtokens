<?php
// backend/api/submit.php
// POST  – save draft or final submission
// Validates all token & grade rules before persisting.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

cors_headers();
$session = require_auth();
$db      = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body         = json_body();
$student_id   = (int) $session['user_id'];
$assignment_id = sanitise_int($body['assignment_id'] ?? 0);
$is_final     = !empty($body['is_final']);

// ── Verify assignment exists & is open ───────────────────────────────────────
$stmt = $db->prepare(
    'SELECT * FROM assignments WHERE id = ? AND is_active = TRUE AND open_date <= NOW() AND due_date >= NOW()'
);
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_error('Assignment not found or not currently open', 404);
}

// ── Verify student not already finally submitted ──────────────────────────────
$stmt = $db->prepare('SELECT is_final FROM submissions WHERE student_id = ? AND assignment_id = ?');
$stmt->execute([$student_id, $assignment_id]);
$existing = $stmt->fetch();
if ($existing && $existing['is_final']) {
    json_error('Submission is already final. Contact an administrator to reopen.', 403);
}

// ── Collect & validate inputs ─────────────────────────────────────────────────
$allocations = $body['token_allocations'] ?? [];  // [{recipient_id, tokens}, …]
$grades      = $body['advisor_grades']    ?? [];  // [{advisor_id, grade}, …]
$meeting_ans = sanitise_string($body['advisor_meeting_ans'] ?? '', 2000);
$comments    = sanitise_string($body['comments'] ?? '', 5000);

$errors   = [];
$warnings = [];

// Token total validation
$required_total = (int) $assignment['token_value'];
$actual_total   = array_sum(array_column($allocations, 'tokens'));
if ($actual_total !== $required_total) {
    $errors[] = "Total tokens must equal $required_total (currently $actual_total).";
}

// Each token value must be non-negative
foreach ($allocations as $alloc) {
    if ((int)$alloc['tokens'] < 0) {
        $errors[] = 'Token values cannot be negative.';
        break;
    }
}

// Advisor grades required
if (empty($grades)) {
    $errors[] = 'At least one advisor grade is required.';
}
foreach ($grades as $g) {
    if (sanitise_grade($g['grade']) === '') {
        $errors[] = 'All advisor grades must be A, B, C, D, or F.';
        break;
    }
}

// Advisor meeting answer required
if (trim($meeting_ans) === '') {
    $errors[] = 'Advisor meeting answer is required.';
}

// ── Warning rules ─────────────────────────────────────────────────────────────
$avg_tokens = count($allocations) > 0 ? $required_total / count($allocations) : 0;
foreach ($allocations as $alloc) {
    // Token cut: giving a teammate significantly fewer than average
    if ($avg_tokens > 0 && (int)$alloc['tokens'] < ($avg_tokens * 0.5)) {
        $warnings[] = 'One or more teammates received a significantly low token allocation (token cut).';
        break;
    }
}
foreach ($grades as $g) {
    if (in_array($g['grade'], ['D','F'], true)) {
        $warnings[] = 'One or more advisors received a low grade.';
        break;
    }
}
$has_warnings = !empty($warnings);

// Warnings require comments on final submit
if ($is_final && $has_warnings && trim($comments) === '') {
    $errors[] = 'Comments are required when warnings are present.';
}

if (!empty($errors)) {
    json_error('Validation failed', 422, ['errors' => $errors, 'warnings' => $warnings]);
}

// ── Persist ───────────────────────────────────────────────────────────────────
try {
    $db->beginTransaction();

    if ($existing) {
        // Update existing draft
        $stmt = $db->prepare(
            'UPDATE submissions
             SET advisor_meeting_ans = ?, comments = ?, has_warnings = ?, is_final = ?, submitted_at = NOW()
             WHERE student_id = ? AND assignment_id = ?
             RETURNING id'
        );
        $stmt->execute([$meeting_ans, $comments, $has_warnings, $is_final, $student_id, $assignment_id]);
        $sub_id = $stmt->fetchColumn();

        // Clear old allocations & grades so we can re-insert
        $db->prepare('DELETE FROM token_allocations WHERE submission_id = ?')->execute([$sub_id]);
        $db->prepare('DELETE FROM advisor_grades    WHERE submission_id = ?')->execute([$sub_id]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO submissions (student_id, assignment_id, advisor_meeting_ans, comments, has_warnings, is_final)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$student_id, $assignment_id, $meeting_ans, $comments, $has_warnings, $is_final]);
        $sub_id = $stmt->fetchColumn();
    }

    // Token allocations
    $ta_stmt = $db->prepare(
        'INSERT INTO token_allocations (submission_id, recipient_id, tokens) VALUES (?, ?, ?)'
    );
    foreach ($allocations as $alloc) {
        $ta_stmt->execute([$sub_id, (int)$alloc['recipient_id'], (int)$alloc['tokens']]);
    }

    // Advisor grades
    $ag_stmt = $db->prepare(
        'INSERT INTO advisor_grades (submission_id, advisor_id, grade) VALUES (?, ?, ?)'
    );
    foreach ($grades as $g) {
        $ag_stmt->execute([$sub_id, (int)$g['advisor_id'], sanitise_grade($g['grade'])]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    json_error('Database error during submission', 500);
}

json_ok([
    'submission_id' => $sub_id,
    'is_final'      => $is_final,
    'warnings'      => $warnings,
]);
