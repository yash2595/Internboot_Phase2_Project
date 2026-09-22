<?php
// Path: scripts/apply-schema.php
//
// Applies schema.sql to the configured database.
//
// Usage (from repository root):
//   php scripts/apply-schema.php           -- safe, idempotent (CREATE TABLE IF NOT EXISTS)
//   php scripts/apply-schema.php --force   -- drops all tables first (DESTROYS ALL DATA)
//
// Trigger handling: triggers with BEGIN...END bodies contain internal semicolons
// and cannot be sent via naive ";" splitting. This script sends them directly as
// hardcoded PHP strings via individual mysqli::query() calls, bypassing the SQL
// file parser for those specific statements. The trigger definitions in schema.sql
// are kept as documentation reference; the authoritative source for this script
// is the $triggers array below.
//
// Triggers are verified at the end — the script exits non-zero and prints a
// clear error if any expected trigger is missing after the run.

require_once __DIR__ . '/../src/core/bootstrap.php';

$force = in_array('--force', $argv, true);

// ── Trigger definitions (source of truth for PHP-based installation) ──────────
// Kept in sync with schema.sql's trigger block. Each entry:
//   'name'  => trigger name (used for DROP IF EXISTS + verification)
//   'sql'   => full CREATE TRIGGER body (no trailing delimiter needed)
$triggers = [
    [
        'name' => 'trg_prevent_negative_seats_update',
        'sql'  => "CREATE TRIGGER `trg_prevent_negative_seats_update`
BEFORE UPDATE ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Concurrency Error: seats_remaining cannot be negative';
    END IF;
END",
    ],
    [
        'name' => 'trg_prevent_negative_seats_insert',
        'sql'  => "CREATE TRIGGER `trg_prevent_negative_seats_insert`
BEFORE INSERT ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Validation Error: Initial seats_remaining cannot be negative';
    END IF;
END",
    ],
];

// ── Load and parse schema file (tables + settings, NOT triggers) ──────────────

echo "Reading schema.sql...\n";
$schemaFile = __DIR__ . '/../schema.sql';
if (!file_exists($schemaFile)) {
    die("Error: schema.sql not found at {$schemaFile}\n");
}

$sqlContent = file_get_contents($schemaFile);
if ($sqlContent === false) {
    die("Error: Could not read schema.sql\n");
}

// Normalise line endings
$sqlContent = str_replace("\r\n", "\n", $sqlContent);

// ── Strip out the trigger block entirely from the parsed content ──────────────
// Triggers are handled separately below. We remove everything from
// "DROP TRIGGER IF EXISTS" lines and CREATE TRIGGER blocks so the naive
// ";" splitter never sees the multi-statement trigger bodies.
$sqlContent = preg_replace(
    '/^\s*(DROP TRIGGER IF EXISTS|CREATE TRIGGER)\b.*?(?:END\s*;?\s*$)/ms',
    '',
    $sqlContent
);

// ── Naive ";" splitter for tables + settings (no multi-statement bodies) ─────
$statements = array_map('trim', explode(';', $sqlContent));

echo "Parsed " . count($statements) . " statements from schema.sql.\n";

if ($force) {
    echo "[--force] Destructive mode: DROP TABLE statements will be executed.\n";
} else {
    echo "Safe mode: DROP TABLE statements will be skipped (use --force to execute them).\n";
}

echo "=================================================\n";

$totalStatements   = 0;
$executedStatements = 0;
$skippedStatements  = 0;
$warnStatements     = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;

    $stmtNoComments = trim(preg_replace('/^\s*--[^\n]*\n?/m', '', $stmt));
    if ($stmtNoComments === '') continue;

    $totalStatements++;

    if (stripos($stmt, 'DROP TABLE') !== false && !$force) {
        echo "[SKIP] DROP TABLE (use --force): "
            . substr(str_replace(["\n", "\r"], ' ', $stmtNoComments), 0, 70) . "\n";
        $skippedStatements++;
        continue;
    }

    try {
        if (!$conn->query($stmt)) {
            echo "[WARN] Statement failed: " . $conn->error . "\n";
            echo "       " . substr(str_replace(["\n", "\r"], ' ', $stmtNoComments), 0, 70) . "\n";
            $warnStatements++;
        } else {
            $executedStatements++;
        }
    } catch (Exception $e) {
        // Suppress benign "already exists" / duplicate key warnings on re-runs
        $msg = $e->getMessage();
        $benign = stripos($msg, 'Duplicate entry') !== false
               || stripos($msg, 'already exists') !== false;
        if (!$benign) {
            echo "[WARN] Exception: {$msg}\n";
            echo "       " . substr(str_replace(["\n", "\r"], ' ', $stmtNoComments), 0, 70) . "\n";
            $warnStatements++;
        }
    }
}

echo "=================================================\n";
echo "Tables/settings complete.\n";
echo "Total:    {$totalStatements}\n";
echo "Executed: {$executedStatements}\n";
echo "Skipped:  {$skippedStatements}\n";
echo "Warnings: {$warnStatements}\n";
echo "=================================================\n";

if ($skippedStatements > 0) {
    echo "Note: Use --force to execute skipped DROP TABLE statements (DANGEROUS).\n";
}

// ── Install triggers explicitly ───────────────────────────────────────────────
echo "\nInstalling triggers...\n";

$triggerWarnings = 0;
foreach ($triggers as $t) {
    $name = $t['name'];

    // DROP IF EXISTS first (always safe)
    try {
        $conn->query("DROP TRIGGER IF EXISTS `{$name}`");
    } catch (Exception $e) {
        echo "[WARN] Could not drop trigger '{$name}': " . $e->getMessage() . "\n";
        $triggerWarnings++;
    }

    // CREATE
    try {
        if ($conn->query($t['sql'])) {
            echo "[OK]   Trigger '{$name}' created.\n";
        } else {
            echo "[WARN] Trigger '{$name}' creation returned false: " . $conn->error . "\n";
            $triggerWarnings++;
        }
    } catch (Exception $e) {
        echo "[ERROR] Trigger '{$name}' creation failed: " . $e->getMessage() . "\n";
        $triggerWarnings++;
    }
}

// ── Trigger verification ──────────────────────────────────────────────────────
echo "\nVerifying triggers in information_schema...\n";

$result = $conn->query(
    "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
);
$foundTriggers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $foundTriggers[] = $row['TRIGGER_NAME'];
    }
}

$allOk = true;
foreach ($triggers as $t) {
    $name = $t['name'];
    if (in_array($name, $foundTriggers, true)) {
        echo "[OK]   Trigger '{$name}' verified in database.\n";
    } else {
        echo "[ERROR] Trigger '{$name}' is MISSING after installation!\n";
        $allOk = false;
    }
}

echo "\n=================================================\n";
if (!$allOk || $triggerWarnings > 0) {
    echo "Schema installation finished WITH ERRORS. Review warnings above.\n";
    exit(1);
}

echo "Schema installation complete. All " . count($triggers) . " trigger(s) verified.\n";
echo "=================================================\n";
