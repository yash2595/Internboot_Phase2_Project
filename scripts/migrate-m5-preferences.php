<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

echo "Checking enrollments table for preferred_date and preferred_time_slot...\n";

// Check if preferred_date column exists
$res = $conn->query("SHOW COLUMNS FROM enrollments LIKE 'preferred_date'");
if ($res && $res->num_rows === 0) {
    $conn->query("ALTER TABLE enrollments ADD COLUMN preferred_date DATE NULL AFTER batch_id");
    echo "[OK] Added preferred_date to enrollments table.\n";
} else {
    echo "[INFO] preferred_date already exists.\n";
}

// Check if preferred_time_slot column exists
$res = $conn->query("SHOW COLUMNS FROM enrollments LIKE 'preferred_time_slot'");
if ($res && $res->num_rows === 0) {
    $conn->query("ALTER TABLE enrollments ADD COLUMN preferred_time_slot VARCHAR(50) NULL AFTER preferred_date");
    echo "[OK] Added preferred_time_slot to enrollments table.\n";
} else {
    echo "[INFO] preferred_time_slot already exists.\n";
}

echo "Schema migration complete!\n";
