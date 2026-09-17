-- ============================================================================
-- InternBoot Platform - Migration Script 001: Schema Hardening & Architectural Fixes
-- Target DB: MySQL 8.0+ / MariaDB 10.3+ (Railway Cloud Instance)
-- File: migration_001_fixes.sql
-- ============================================================================

-- ============================================================================
-- PRE-FLIGHT CHECKS (Run these queries first & verify output before continuing)
-- ============================================================================

-- Pre-flight Check 1: Verify no NULL exam_slot_id records exist in attempts table.
-- EXPECTED RESULT: 0. If > 0, resolve or assign slots before running Section 2.
SELECT COUNT(*) AS null_slot_attempts_count 
FROM `attempts` 
WHERE `exam_slot_id` IS NULL;

-- Pre-flight Check 2: Check MySQL server version.
-- EXPECTED RESULT: 8.0.16 or higher for native CHECK enforcement.
-- If < 8.0.16, Section 3 triggers are MANDATORY.
SELECT VERSION() AS mysql_server_version;


-- ============================================================================
-- SECTION 1: enrollments Documentation & Policy Lock
-- Purpose: Clarify enrollment uniqueness policy vs retakes
-- ============================================================================

-- Note: uk_candidate_assessment (candidate_id, assessment_id) is kept AS-IS.
-- Retakes (when settings.retake_allowed = 1) create new rows in `attempts`,
-- NOT new rows in `enrollments`. This maintains single registration integrity.

ALTER TABLE `enrollments` 
  COMMENT = 'Candidate registrations. Retakes create new attempts, not new enrollments.';


-- ============================================================================
-- SECTION 2: attempts Slot Nullability & Foreign Key Hardening
-- WARNING: BACKUP RECOMMENDED BEFORE THIS STEP
-- Prerequisite: Pre-flight Check 1 MUST return 0
-- ============================================================================

-- Step 2.1: Modify exam_slot_id to NOT NULL
ALTER TABLE `attempts` 
  MODIFY COLUMN `exam_slot_id` BIGINT UNSIGNED NOT NULL;

-- Step 2.2: Drop existing FK constraint safely if exists and re-add with RESTRICT
ALTER TABLE `attempts` 
  DROP FOREIGN KEY `fk_attempts_slot`;

ALTER TABLE `attempts` 
  ADD CONSTRAINT `fk_attempts_slot` 
  FOREIGN KEY (`exam_slot_id`) REFERENCES `exam_slots` (`id`) 
  ON DELETE RESTRICT ON UPDATE CASCADE;


-- ============================================================================
-- SECTION 3: exam_slots Concurrency Safety Triggers (Fallback for MySQL < 8.0.16)
-- Note: Only required if Pre-flight Check 2 shows MySQL < 8.0.16.
-- ============================================================================

DELIMITER //

-- Trigger 3.1: Prevent negative seats on UPDATE
DROP TRIGGER IF EXISTS `trg_prevent_negative_seats_update`//
CREATE TRIGGER `trg_prevent_negative_seats_update`
BEFORE UPDATE ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Concurrency Error: seats_remaining cannot be negative';
    END IF;
END//

-- Trigger 3.2: Prevent negative seats on INSERT
DROP TRIGGER IF EXISTS `trg_prevent_negative_seats_insert`//
CREATE TRIGGER `trg_prevent_negative_seats_insert`
BEFORE INSERT ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Validation Error: Initial seats_remaining cannot be negative';
    END IF;
END//

DELIMITER ;


-- ============================================================================
-- SECTION 4: results & levels Range Check Constraints
-- Purpose: Enforce 0.00 to 100.00 percentage bounds and min <= max logic
-- ============================================================================

-- Step 4.1: Results percentage validation (0 - 100)
ALTER TABLE `results` 
  ADD CONSTRAINT `chk_results_percentage` 
  CHECK (`percentage` BETWEEN 0.00 AND 100.00);

-- Step 4.2: Levels min_percentage validation (0 - 100)
ALTER TABLE `levels` 
  ADD CONSTRAINT `chk_levels_min_percentage` 
  CHECK (`min_percentage` BETWEEN 0.00 AND 100.00);

-- Step 4.3: Levels max_percentage validation (0 - 100)
ALTER TABLE `levels` 
  ADD CONSTRAINT `chk_levels_max_percentage` 
  CHECK (`max_percentage` BETWEEN 0.00 AND 100.00);

-- Step 4.4: Levels range order validation (min <= max)
ALTER TABLE `levels` 
  ADD CONSTRAINT `chk_levels_range_valid` 
  CHECK (`min_percentage` <= `max_percentage`);


-- ============================================================================
-- SECTION 5: VERIFICATION QUERY (Post-Migration Verification)
-- Purpose: Verify levels score ranges do not overlap.
-- EXPECTED RESULT: 0 rows returned.
-- ============================================================================

SELECT 
    l1.level_number AS level_a, 
    l1.level_name AS name_a,
    l1.min_percentage AS min_a, 
    l1.max_percentage AS max_a,
    l2.level_number AS level_b, 
    l2.level_name AS name_b,
    l2.min_percentage AS min_b, 
    l2.max_percentage AS max_b
FROM levels l1
JOIN levels l2 ON l1.id <> l2.id
WHERE (l1.min_percentage <= l2.max_percentage AND l1.max_percentage >= l2.min_percentage);
