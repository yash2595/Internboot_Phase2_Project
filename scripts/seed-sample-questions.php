<?php
// Path: scripts/seed-sample-questions.php
// Seeds sample approved questions and options for manual testing

require_once __DIR__ . '/../src/core/bootstrap.php';

echo "Seeding sample approved questions...\n";

// Get assessment 1 and question bank 1
$qbank = $conn->query("SELECT id, assessment_id FROM question_banks WHERE id = 1 LIMIT 1")->fetch_assoc();
if (!$qbank) {
    die("Question bank 1 not found. Run seed.php first.\n");
}
$qbankId = (int)$qbank['id'];
$assessmentId = (int)$qbank['assessment_id'];

$sampleQuestions = [
    [
        'text' => 'Which of the following is NOT a superglobal variable in PHP?',
        'difficulty' => 'easy',
        'options' => [
            ['text' => '$_SERVER', 'correct' => 0],
            ['text' => '$_SESSION', 'correct' => 0],
            ['text' => '$_GLOBAL', 'correct' => 1],
            ['text' => '$_POST', 'correct' => 0]
        ]
    ],
    [
        'text' => 'Which function is used in PHP to open a file for reading?',
        'difficulty' => 'easy',
        'options' => [
            ['text' => 'fopen()', 'correct' => 1],
            ['text' => 'open_file()', 'correct' => 0],
            ['text' => 'readfile()', 'correct' => 0],
            ['text' => 'file_read()', 'correct' => 0]
        ]
    ],
    [
        'text' => 'What is the default port for MySQL server connection?',
        'difficulty' => 'easy',
        'options' => [
            ['text' => '8080', 'correct' => 0],
            ['text' => '3306', 'correct' => 1],
            ['text' => '5432', 'correct' => 0],
            ['text' => '27017', 'correct' => 0]
        ]
    ],
    [
        'text' => 'In MySQL, which storage engine supports ACID transactions and row-level locking?',
        'difficulty' => 'medium',
        'options' => [
            ['text' => 'MyISAM', 'correct' => 0],
            ['text' => 'MEMORY', 'correct' => 0],
            ['text' => 'InnoDB', 'correct' => 1],
            ['text' => 'CSV', 'correct' => 0]
        ]
    ],
    [
        'text' => 'Which HTTP status code signifies that the requested resource was created successfully?',
        'difficulty' => 'easy',
        'options' => [
            ['text' => '200 OK', 'correct' => 0],
            ['text' => '201 Created', 'correct' => 1],
            ['text' => '204 No Content', 'correct' => 0],
            ['text' => '202 Accepted', 'correct' => 0]
        ]
    ],
    [
        'text' => 'What does ACID stand for in database management systems?',
        'difficulty' => 'medium',
        'options' => [
            ['text' => 'Atomicity, Consistency, Isolation, Durability', 'correct' => 1],
            ['text' => 'Accuracy, Consistency, Integrity, Durability', 'correct' => 0],
            ['text' => 'Automatic, Concurrency, Isolation, Distribution', 'correct' => 0],
            ['text' => 'Atomicity, Compatibility, Isolation, Distribution', 'correct' => 0]
        ]
    ],
    [
        'text' => 'In modern PHP, what does the spaceship operator (<=>) return when the left operand is equal to the right operand?',
        'difficulty' => 'medium',
        'options' => [
            ['text' => '-1', 'correct' => 0],
            ['text' => '0', 'correct' => 1],
            ['text' => '1', 'correct' => 0],
            ['text' => 'null', 'correct' => 0]
        ]
    ],
    [
        'text' => 'Which MySQL clause is used to prevent concurrent reads from modifying rows inside an active transaction?',
        'difficulty' => 'hard',
        'options' => [
            ['text' => 'LOCK IN EXCLUSIVE', 'correct' => 0],
            ['text' => 'FOR UPDATE', 'correct' => 1],
            ['text' => 'WITH ROWLOCK', 'correct' => 0],
            ['text' => 'HOLDLOCK', 'correct' => 0]
        ]
    ],
    [
        'text' => 'Which PHP function securely hashes a password using modern cryptographic algorithms like Bcrypt?',
        'difficulty' => 'easy',
        'options' => [
            ['text' => 'md5()', 'correct' => 0],
            ['text' => 'sha1()', 'correct' => 0],
            ['text' => 'password_hash()', 'correct' => 1],
            ['text' => 'crypt_hash()', 'correct' => 0]
        ]
    ],
    [
        'text' => 'Which HTTP status code should be returned when a request conflicts with the current state of the server (e.g. slot full)?',
        'difficulty' => 'medium',
        'options' => [
            ['text' => '400 Bad Request', 'correct' => 0],
            ['text' => '403 Forbidden', 'correct' => 0],
            ['text' => '409 Conflict', 'correct' => 1],
            ['text' => '422 Unprocessable Entity', 'correct' => 0]
        ]
    ]
];

$stmtQ = $conn->prepare("INSERT INTO questions (question_bank_id, question_text, type, difficulty, approval_status) VALUES (?, ?, 'MCQ', ?, 'approved')");
$stmtO = $conn->prepare("INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

$insertedCount = 0;
foreach ($sampleQuestions as $q) {
    // Check if question already exists
    $chk = $conn->prepare("SELECT id FROM questions WHERE question_bank_id = ? AND question_text = ?");
    $chk->bind_param("is", $qbankId, $q['text']);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        continue;
    }

    $stmtQ->bind_param("iss", $qbankId, $q['text'], $q['difficulty']);
    $stmtQ->execute();
    $qid = $stmtQ->insert_id;

    foreach ($q['options'] as $opt) {
        $stmtO->bind_param("isi", $qid, $opt['text'], $opt['correct']);
        $stmtO->execute();
    }
    $insertedCount++;
}

$stmtQ->close();
$stmtO->close();

// Update assessment total questions to match count of available questions
$totalCount = (int)$conn->query("SELECT count(*) as c FROM questions WHERE question_bank_id = {$qbankId} AND approval_status = 'approved'")->fetch_assoc()['c'];
$conn->query("UPDATE assessments SET total_questions = {$totalCount} WHERE id = {$assessmentId}");

echo "[OK] Seeded {$insertedCount} questions. Total approved questions in assessment {$assessmentId}: {$totalCount}\n";
