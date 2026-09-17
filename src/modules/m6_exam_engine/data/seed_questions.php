<?php

if (file_exists(__DIR__ . '/../../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../src/core/bootstrap.php';
} else {
    require_once __DIR__ . '/../db.php';
}

$questionBankId = 1;

// Prevent accidental duplicate seeding
$check = $conn->prepare(
    "SELECT COUNT(*) AS total FROM questions WHERE question_bank_id = ?"
);
$check->bind_param("i", $questionBankId);
$check->execute();

$result = $check->get_result()->fetch_assoc();

if ((int)$result['total'] > 0) {
    die("Questions already exist for this question bank. No new questions inserted.");
}

$questions = [];

/*
|--------------------------------------------------------------------------
| MEDIUM QUESTIONS
|--------------------------------------------------------------------------
*/

$mediumTopics = [
    "Programming & Problem Solving",
    "OOP",
    "Data Structures & Algorithms",
    "DBMS & SQL",
    "Operating Systems",
    "Computer Networks",
    "Software Engineering",
    "Web Technologies",
    "Computer Fundamentals"
];

for ($i = 1; $i <= 60; $i++) {

    $topic = $mediumTopics[($i - 1) % count($mediumTopics)];

    $questions[] = [
        "text" => "Medium-level development question #$i: Which approach is most appropriate when solving a {$topic} problem where correctness and predictable execution are required?",
        "difficulty" => "medium",
        "options" => [
            "Apply a structured algorithm and validate its assumptions",
            "Choose an arbitrary implementation without analysing constraints",
            "Ignore the input constraints and optimize only the interface",
            "Remove validation because it can reduce execution speed"
        ],
        "correct" => 0
    ];
}

/*
|--------------------------------------------------------------------------
| HARD QUESTIONS
|--------------------------------------------------------------------------
*/

$hardTopics = [
    "Programming & Problem Solving",
    "OOP",
    "Data Structures & Algorithms",
    "DBMS & SQL",
    "Operating Systems",
    "Computer Networks",
    "Software Engineering",
    "Web Technologies",
    "Computer Fundamentals"
];

for ($i = 1; $i <= 60; $i++) {

    $topic = $hardTopics[($i - 1) % count($hardTopics)];

    $questions[] = [
        "text" => "Hard-level development question #$i: In a {$topic} system, which design decision is most important when the solution must remain correct under larger inputs and concurrent or changing conditions?",
        "difficulty" => "hard",
        "options" => [
            "Analyse constraints, correctness conditions, complexity and failure cases",
            "Optimize only the visual presentation of the system",
            "Assume all inputs are valid and eliminate defensive checks",
            "Use the most complex available implementation regardless of requirements"
        ],
        "correct" => 0
    ];
}

/*
|--------------------------------------------------------------------------
| INSERT QUESTIONS + OPTIONS
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    $questionStmt = $conn->prepare(
        "INSERT INTO questions
        (question_bank_id, question_text, type, difficulty, approval_status)
        VALUES (?, ?, 'MCQ', ?, 'approved')"
    );

    $optionStmt = $conn->prepare(
        "INSERT INTO options
        (question_id, option_text, is_correct)
        VALUES (?, ?, ?)"
    );

    foreach ($questions as $question) {

        $questionStmt->bind_param(
            "iss",
            $questionBankId,
            $question["text"],
            $question["difficulty"]
        );

        $questionStmt->execute();

        $questionId = $conn->insert_id;

        foreach ($question["options"] as $index => $optionText) {

            $isCorrect = ($index === $question["correct"]) ? 1 : 0;

            $optionStmt->bind_param(
                "isi",
                $questionId,
                $optionText,
                $isCorrect
            );

            $optionStmt->execute();
        }
    }

    $conn->commit();

    echo "<h2>Success</h2>";
    echo "<p>120 questions inserted successfully.</p>";
    echo "<p>60 Medium + 60 Hard</p>";
    echo "<p>480 options inserted.</p>";

} catch (Throwable $e) {

    $conn->rollback();

    echo "<h2>Insert failed</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}