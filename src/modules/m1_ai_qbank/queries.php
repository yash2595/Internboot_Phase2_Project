<?php
// Path: src/modules/m1_ai_qbank/queries.php

/**
 * Inserts a manually created approved question into the questions table.
 */
function insert_question(int $qbankId, string $questionText, string $difficulty, mysqli $conn): int {
    $sql = "INSERT INTO questions (question_bank_id, question_text, type, difficulty, approval_status, created_at) 
            VALUES (?, ?, 'MCQ', ?, 'approved', NOW())";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare question insert query: " . $conn->error);
    }

    $stmt->bind_param("iss", $qbankId, $questionText, $difficulty);
    $stmt->execute();
    $questionId = (int)$stmt->insert_id;
    $stmt->close();

    return $questionId;
}

/**
 * Inserts a single multiple-choice option for a question.
 */
function insert_question_option(int $questionId, string $optionText, int $isCorrect, mysqli $conn): bool {
    $sql = "INSERT INTO options (question_id, option_text, is_correct, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare option insert query: " . $conn->error);
    }

    $stmt->bind_param("isi", $questionId, $optionText, $isCorrect);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Fetches all approved questions for a given question bank ID.
 */
function get_approved_questions_by_qbank(int $qbankId, mysqli $conn): array {
    $sql = "SELECT id, question_bank_id, question_text, type, difficulty, approval_status 
            FROM questions 
            WHERE question_bank_id = ? AND approval_status = 'approved'
            ORDER BY id ASC";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Failed to prepare questions select query: " . $conn->error);
    }

    $stmt->bind_param("i", $qbankId);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $questions;
}

/**
 * Fetches options for a specific question ID.
 */
function get_options_by_question_id(int $questionId, mysqli $conn): array {
    $sql = "SELECT id, question_id, option_text, is_correct FROM options WHERE question_id = ? ORDER BY id ASC";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Failed to prepare options select query: " . $conn->error);
    }

    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $options = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $options;
}
?>
