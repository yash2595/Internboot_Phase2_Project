<?php
// Path: src/modules/m1_ai_qbank/controller.php

require_once __DIR__ . '/service.php';

/**
 * Controller handler for adding a manual question.
 */
function handle_add_question_request(array $input, mysqli $conn): void {
    $qbankId = (int)($input['question_bank_id'] ?? 0);
    $questionText = trim($input['question_text'] ?? '');
    $difficulty = strtolower(trim($input['difficulty'] ?? 'medium'));
    $options = $input['options'] ?? [];

    // Validation checks
    if ($qbankId <= 0) {
        send_json_response('error', 'Valid question_bank_id is required', null, 400);
    }

    if (empty($questionText)) {
        send_json_response('error', 'Question text cannot be empty', null, 400);
    }

    $validDifficulties = ['easy', 'medium', 'hard'];
    if (!in_array($difficulty, $validDifficulties, true)) {
        send_json_response('error', 'Difficulty must be easy, medium, or hard', null, 400);
    }

    if (!is_array($options) || count($options) !== 4) {
        send_json_response('error', 'Exactly 4 options must be provided in an array', null, 400);
    }

    try {
        $result = add_manual_question($qbankId, $questionText, $difficulty, $options, $conn);
        send_json_response('success', 'Question added successfully', $result, 201);
    } catch (Exception $e) {
        send_json_response('error', $e->getMessage(), null, 400);
    }
}

/**
 * Controller handler for listing approved questions of a qbank.
 */
function handle_list_questions_request(array $input, mysqli $conn): void {
    $qbankId = (int)($input['question_bank_id'] ?? ($_GET['question_bank_id'] ?? 0));

    if ($qbankId <= 0) {
        send_json_response('error', 'Valid question_bank_id parameter is required', null, 400);
    }

    // Determine if requester is Admin
    $isAdmin = (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin');

    try {
        $data = fetch_approved_qbank_questions($qbankId, $conn, $isAdmin);
        send_json_response('success', 'Approved questions retrieved successfully', $data, 200);
    } catch (Exception $e) {
        send_json_response('error', $e->getMessage(), null, 500);
    }
}
?>
