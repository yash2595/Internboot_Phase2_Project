# 📦 InternBoot — Module 5: Batch & Slot Management (M5)
**Author:** Mohd Suhail (M5 Developer)  
**Target Audience:** Yash Mishra (Tech Lead / M1), Development Team, Downstream AI Agents  
**Git Branch:** `feature/m5-batch-slots`  
**PR Target:** `dev`  
**Status:** Complete, Tested, & Ready for Review  

---

## 1. Executive Summary & Module Scope

Module 5 (**Batch & Slot Management**) is responsible for automated grouping of eligible candidates into assessment batches, generating weekend exam dates with multiple time slots, and managing exam slot bookings with database-level concurrency protection.

### Core Mandates Handled:
1. **Dynamic / Configurable Threshold Batching**: Group candidates into batches once eligible count reaches the threshold (defaults to `100`, read dynamically from `settings.batch_threshold`, overridable for testing/demos).
2. **Weekend Exam Scheduling**: Automatically calculate and assign exam schedules for the next upcoming **Saturday** and **Sunday**, provisioning morning and afternoon slots under each schedule.
3. **Database-Query-Level Concurrency Guard**: Guarantee 0 overselling/race conditions via atomic query checks (`UPDATE exam_slots SET seats_remaining = seats_remaining - 1 WHERE id = ? AND seats_remaining > 0`) inside SQL transactions.
4. **Single-Slot Holding Rule**: Prevent candidates from holding or booking more than one slot for an assessment.
5. **Strict API Contract Adherence**: Comply exactly with the input/output schemas defined in `SCHEMA_HANDOVER.md`.

---

## 2. File & Directory Structure

All implementation code strictly adheres to the 3-layer architecture patterned after `m3_auth`:

```
Internboot_Phase2_Project/
├── public/api/slots/
│   ├── book.php                   # POST: Reserve exam slot for eligible candidate
│   ├── available.php              # GET: List active slots with remaining seats
│   └── auto_batch.php             # POST: Trigger batch threshold check & formation
│
└── src/modules/m5_batch_slots/
    ├── controller.php             # Request validation, response delivery (send_json_response)
    ├── service.php                # Business logic: thresholding, weekend date math, atomic booking
    ├── queries.php                # 100% Prepared statements (MySQLi)
    └── README.md                  # This complete documentation & context file
```

---

## 3. Database Schema Mapping & Table Relations

Module 5 interacts with 6 tables in the `railway` MySQL schema:

| Table Name | Interaction | Purpose in M5 |
| :--- | :--- | :--- |
| `settings` | Read | Fetch `batch_threshold` (default `100`) dynamically. |
| `enrollments` | Read & Update | Filter candidates with `eligibility_status = 'eligible' AND batch_id IS NULL`, then assign `batch_id` upon batch creation. Verify eligibility and batch match on booking. |
| `batches` | Create & Read | Store auto-generated batch records (`batch_number`, `assessment_id`, `creation_date`). |
| `exam_schedules` | Create & Read | Store weekend exam dates (`batch_id`, `exam_date`, `status = 'scheduled'`) restricted to Saturday/Sunday. |
| `exam_slots` | Create, Read, Update | Provision slots (`start_time`, `end_time`, `capacity`, `seats_remaining`). Atomically decrement `seats_remaining`. |
| `attempts` | Create & Read | Prevent duplicate bookings; record confirmed booking as an attempt (`candidate_id`, `assessment_id`, `exam_slot_id`, `status = 'in_progress'`). |

---

## 4. Key Business Logic & Algorithms

### 4.1. Batch Threshold Resolution (`get_batch_threshold`)
```php
get_batch_threshold(mysqli $conn, ?int $customThreshold = null): int
```
- Priority 1: `$customThreshold` argument if passed and $> 0$ (ideal for sandbox/demo testing with small batches).
- Priority 2: Value of `batch_threshold` from `settings` table.
- Priority 3: Hardcoded fallback default `100`.

### 4.2. Auto-Batch Formation (`check_and_create_batch`)
```php
check_and_create_batch(int $assessmentId, mysqli $conn, ?int $customThreshold = null): ?array
```
1. Queries count of unbatched eligible enrollments (`eligibility_status = 'eligible' AND batch_id IS NULL`).
2. If count $<$ threshold: returns `null` (no action taken).
3. If count $\ge$ threshold:
   - Starts SQL transaction (`$conn->begin_transaction()`).
   - Fetches candidate IDs with row-level locking (`FOR UPDATE`).
   - Generates unique batch code: `BATCH-A{assessmentId}-YYYYMMDD-{randomHash}`.
   - Inserts row into `batches`.
   - Executes batch update on `enrollments` setting `batch_id = $batchId` for the selected candidates.
   - Computes upcoming Saturday and Sunday dates.
   - Creates 2 schedules (`Saturday`, `Sunday`) in `exam_schedules`.
   - Provisions 2 standard slots per day in `exam_slots`:
     - Slot 1: `10:00:00` - `11:00:00` (Capacity: 50, Seats Remaining: 50)
     - Slot 2: `14:00:00` - `15:00:00` (Capacity: 50, Seats Remaining: 50)
   - Commits transaction (`$conn->commit()`).

### 4.3. Weekend Date Math (`calculate_next_weekend_dates`)
- Evaluates current server day of week:
  - If Monday–Friday: returns the immediately upcoming Saturday and Sunday.
  - If Saturday: returns next week's Saturday (+7 days) and Sunday (+8 days) to give candidates prep notice.
  - If Sunday: returns next week's Saturday (+6 days) and Sunday (+7 days).

### 4.4. Anti-Race-Condition Slot Booking (`book_exam_slot`)
```php
book_exam_slot(int $candidateId, int $assessmentId, int $examSlotId, mysqli $conn): array
```
To eliminate concurrency race conditions (e.g. 2 candidates clicking "Book" at the same millisecond on the last seat):
1. **Pre-flight Checks**:
   - Enrollment check: candidate must be enrolled with `eligibility_status = 'eligible'`.
   - Batch check: candidate must have an allocated `batch_id`.
   - Duplicate check: candidate must NOT already have an attempt record for this `assessment_id`.
   - Slot validity check: slot must belong to candidate's batch, assessment, and have `schedule_status = 'scheduled'`.
2. **Atomic Query Decrement inside Transaction**:
   ```sql
   UPDATE exam_slots 
   SET seats_remaining = seats_remaining - 1, updated_at = NOW() 
   WHERE id = ? AND seats_remaining > 0
   ```
   - Checks `$stmt->affected_rows`.
   - If `0`: Immediately rolls back and throws Exception (`Selected exam slot is fully booked. No seats remaining.`).
   - If `1`: Seat guaranteed. Inserts new record into `attempts` table (`status = 'in_progress'`), commits transaction, and returns fresh slot details.

---

## 5. API Endpoint Specifications

### 5.1. Slot Booking Endpoint
- **URL:** `POST /api/slots/book.php` (or `/public/api/slots/book.php`)
- **Headers:** `Content-Type: application/json`

**Request Payload:**
```json
{
  "candidate_id": 15,
  "assessment_id": 2,
  "exam_slot_id": 12
}
```

**Success Response (`200 OK`):**
```json
{
  "status": "success",
  "message": "Exam slot booked successfully",
  "data": {
    "attempt_id": 204,
    "candidate_id": 15,
    "exam_slot_id": 12,
    "exam_date": "2026-09-20",
    "start_time": "10:00:00",
    "end_time": "11:00:00",
    "seats_remaining": 34
  }
}
```

**Error Responses (`400 Bad Request`):**
- Candidate not eligible: `{"status": "error", "message": "Candidate is not eligible to book an exam slot (Status: pending)"}`
- Duplicate booking: `{"status": "error", "message": "Candidate already has a booked slot for this assessment (Attempt ID: 204)"}`
- Fully booked: `{"status": "error", "message": "Selected exam slot is fully booked. No seats remaining."}`
- Batch mismatch: `{"status": "error", "message": "Exam slot belongs to Batch #5, but candidate is assigned to Batch #3"}`

---

### 5.2. Available Slots Listing Endpoint
- **URL:** `GET /api/slots/available.php?assessment_id=2&candidate_id=15`
- **Method:** `GET`

**Success Response (`200 OK`):**
```json
{
  "status": "success",
  "message": "Available exam slots retrieved successfully",
  "data": [
    {
      "exam_slot_id": 12,
      "exam_schedule_id": 4,
      "batch_id": 5,
      "exam_date": "2026-09-20",
      "start_time": "10:00:00",
      "end_time": "11:00:00",
      "capacity": 50,
      "seats_remaining": 34
    },
    {
      "exam_slot_id": 13,
      "exam_schedule_id": 4,
      "batch_id": 5,
      "exam_date": "2026-09-20",
      "start_time": "14:00:00",
      "end_time": "15:00:00",
      "capacity": 50,
      "seats_remaining": 50
    }
  ]
}
```

---

### 5.3. Batch Auto-Creation Endpoint
- **URL:** `POST /api/slots/auto_batch.php`
- **Headers:** `Content-Type: application/json`

**Request Payload:**
```json
{
  "assessment_id": 2,
  "threshold": 100
}
```
*(Note: `"threshold"` is optional; defaults to database setting or 100).*

**Success Response (`201 Created` - when batch formed):**
```json
{
  "status": "success",
  "message": "Batch and exam schedules formed successfully",
  "data": {
    "batch_id": 5,
    "batch_number": "BATCH-A2-20260915-A1F92",
    "assessment_id": 2,
    "assigned_candidates_count": 100,
    "threshold": 100,
    "schedules": [
      {
        "schedule_id": 8,
        "exam_date": "2026-09-19",
        "day": "Saturday",
        "slots": [
          { "slot_id": 16, "start_time": "10:00:00", "end_time": "11:00:00", "capacity": 50, "seats_remaining": 50 },
          { "slot_id": 17, "start_time": "14:00:00", "end_time": "15:00:00", "capacity": 50, "seats_remaining": 50 }
        ]
      },
      {
        "schedule_id": 9,
        "exam_date": "2026-09-20",
        "day": "Sunday",
        "slots": [
          { "slot_id": 18, "start_time": "10:00:00", "end_time": "11:00:00", "capacity": 50, "seats_remaining": 50 },
          { "slot_id": 19, "start_time": "14:00:00", "end_time": "15:00:00", "capacity": 50, "seats_remaining": 50 }
        ]
      }
    ]
  }
}
```

**Informational Response (`200 OK` - when count < threshold):**
```json
{
  "status": "success",
  "message": "Candidate count below threshold. Batch not formed yet.",
  "data": {
    "assessment_id": 2,
    "eligible_count": 42,
    "threshold": 100,
    "batch_formed": false,
    "needed_to_form_batch": 58
  }
}
```

---

## 6. Cross-Module Integration Guide (For Team Members & AI Agents)

### 🔹 For M4 (Payment & Dashboard):
Whenever a candidate completes payment and is marked `eligible`:
```php
require_once __DIR__ . '/../m5_batch_slots/service.php';

// After updating enrollments.eligibility_status = 'eligible':
$newBatch = check_and_create_batch($assessmentId, $conn);
if ($newBatch !== null) {
    // A new batch was formed! You can log or notify candidates.
}
```

### 🔹 For M6 (Assessment & Exam Engine):
When a candidate starts the exam:
1. Validate `attempt_id` exists in `attempts`.
2. Ensure `attempts.exam_slot_id` matches the current active time window:
```php
require_once __DIR__ . '/../m5_batch_slots/queries.php';
$slot = get_slot_details($examSlotId, $conn);
// $slot['exam_date'], $slot['start_time'], $slot['end_time'] provide official server time boundaries.
```

---

## 7. Verification & Testing Evidence

All code was verified through two automated test suites:

1. **Unit & Date Math Test Suite (`test_m5_unit.php`)**:
   - 15/15 Passed.
   - Verified weekend date projections across Mondays, Wednesdays, Saturdays, and Sundays.
   - Verified setting fallback and dynamic overrides.
   - Verified controller input validation checks.

2. **Full End-to-End MySQL Integration Test Suite (`test_m5_integration.php`)**:
   - 26/26 Passed on live MySQL engine running `schema.sql`.
   - Verified batch formation with 105 seeded candidates (100 batched, 5 remaining).
   - Verified schedule and slot generation.
   - Verified single-slot rule (blocked re-booking and second slot attempts).
   - Verified atomic race-condition decrement (when 1 seat remained, 1 succeeded, 2nd rejected; seats never negative).
   - Clean teardown with zero residual data.

---

## 8. Summary of Git Commits

- **Feature Branch:** `feature/m5-batch-slots`
- **Initial Feature Commit:** `c738188` (`feat(m5): implement batch formation, weekend exam scheduling, and concurrency-safe slot booking`)
- **Documentation Commit:** Included in branch updates.
