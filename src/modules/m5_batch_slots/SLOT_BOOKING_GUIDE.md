# 📘 InternBoot — Slot Booking & Batch API Integration Guide
**Module:** M5 (Batch & Slot Management)  
**Author:** Mohd Suhail (M5 Developer)  
**Target Audience:** Frontend Team, M4 (Dashboard/Payment), M6 (Exam Engine)  
**Branch:** `feature/m5-batch-slots`  
**Base Path:** `/public/api/slots/` (or `/api/slots/` depending on your web server rewrite rules)

---

## 🧭 1. Overview: The Candidate Slot Booking Journey

To book a slot, a candidate progresses through these stages:

```
[1. Register & Login (M3)]
          ↓
[2. Complete Payment & Marked 'eligible' (M4)]
          ↓
[3. Threshold Met (default 100) -> Auto-Assigned to Batch with Weekend Schedules (M5)]
          ↓
[4. Candidate Dashboard: View Available Slots (GET /api/slots/available.php) (M5)]
          ↓
[5. Candidate Selects & Confirms Slot (POST /api/slots/book.php) (M5)]
          ↓
[6. Attempt Created with Locked Server End-Time (M6 Exam Engine)]
```

---

## 💻 2. Frontend Integration Guide (JavaScript / Fetch)

### Step A: Fetch Available Exam Slots for the Candidate
Fetch active slots where `seats_remaining > 0` for the candidate's assigned batch.

```javascript
/**
 * Fetch available slots for an assessment
 * @param {number} assessmentId 
 */
async function loadAvailableSlots(assessmentId) {
  try {
    const response = await fetch(`/api/slots/available.php?assessment_id=${assessmentId}`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json'
      }
    });

    const result = await response.json();

    if (result.status === 'success') {
      console.log('Available slots:', result.data);
      // Example data item:
      // {
      //   exam_slot_id: 12,
      //   batch_id: 5,
      //   exam_date: "2026-09-20",
      //   start_time: "10:00:00",
      //   end_time: "11:00:00",
      //   capacity: 50,
      //   seats_remaining: 34
      // }
      renderSlotOptions(result.data);
    } else {
      alert('Error fetching slots: ' + result.message);
    }
  } catch (error) {
    console.error('Network error while fetching slots:', error);
  }
}
```

---

### Step B: Book a Selected Exam Slot
When the candidate clicks **"Confirm Slot"**:

```javascript
/**
 * Book an exam slot
 * @param {number} assessmentId 
 * @param {number} examSlotId 
 */
async function bookExamSlot(assessmentId, examSlotId) {
  try {
    const response = await fetch('/api/slots/book.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        assessment_id: assessmentId,
        exam_slot_id: examSlotId
      })
    });

    const result = await response.json();

    if (response.ok && result.status === 'success') {
      // Slot successfully booked!
      console.log('Booking Confirmed:', result.data);
      alert(`Booking Confirmed!\nDate: ${result.data.exam_date}\nTime: ${result.data.start_time} - ${result.data.end_time}`);
      
      // Redirect candidate to their Exam Lobby or updated Dashboard
      window.location.href = `/dashboard.php?booked=true&attempt_id=${result.data.attempt_id}`;
    } else {
      // Handle known business errors (fully booked, duplicate booking, ineligible, etc.)
      alert(`Booking Failed: ${result.message}`);
    }
  } catch (error) {
    console.error('Network error during booking:', error);
    alert('An unexpected error occurred. Please check your connection and try again.');
  }
}
```

---

## ⚙️ 3. Backend PHP Integration (For M4 & M6)

### 🔹 For M4 (Payment Verification & Enrollment):
Whenever a candidate payment is verified and their enrollment is updated to `eligible`, trigger the batch creation check:

```php
require_once __DIR__ . '/../m5_batch_slots/service.php';

// Inside M4 payment verification logic after setting eligibility_status = 'eligible':
$newBatch = check_and_create_batch($assessmentId, $conn);

if ($newBatch !== null) {
    // A new batch (e.g. 100 candidates) was formed and scheduled!
    // $newBatch['batch_id'], $newBatch['batch_number'], $newBatch['schedules'] are available.
}
```

---

### 🔹 For M6 (Assessment & Exam Engine):
When a candidate attempts to launch the exam:
1. Verify their `attempt_id` exists in `attempts`.
2. Retrieve the official slot timing boundaries from `exam_slots`:

```php
require_once __DIR__ . '/../m5_batch_slots/queries.php';

// Fetch slot schedule and time boundaries:
$slot = get_slot_details($examSlotId, $conn);

// $slot contains:
// $slot['exam_date']        -> e.g. "2026-09-20"
// $slot['start_time']       -> e.g. "10:00:00"
// $slot['end_time']         -> e.g. "11:00:00"
// $slot['schedule_status']   -> e.g. "scheduled"
```

---

## 📡 4. Complete API Specifications

### Endpoint 1: Slot Booking
* **Method & URL:** `POST /api/slots/book.php`
* **Content-Type:** `application/json`
* **Authentication:** Requires candidate session (`$_SESSION['candidate_id']`).

#### Request Payload:
```json
{
  "assessment_id": 2,
  "exam_slot_id": 12
}
```
*(Optional: `"candidate_id": 15` can be passed, but it must match `$_SESSION['candidate_id']`).*

#### Success Response (`HTTP 200 OK`):
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

---

### Endpoint 2: Available Slots
* **Method & URL:** `GET /api/slots/available.php?assessment_id=2`
* **Query Parameters:**
  * `assessment_id` (Required, integer): The assessment ID.
  * `candidate_id` (Optional, integer): Filter slots specifically for candidate's assigned batch.

#### Success Response (`HTTP 200 OK`):
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
    }
  ]
}
```

---

### Endpoint 3: Auto-Batch Creation (Admin Only)
* **Method & URL:** `POST /api/slots/auto_batch.php`
* **Authentication:** Requires Admin session (`$_SESSION['role'] === 'admin'`).

#### Request Payload:
```json
{
  "assessment_id": 2,
  "threshold": 100
}
```

#### Success Response (`HTTP 201 Created`):
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
    "schedules": [ ... ],
    "overflow_candidates_count": 5,
    "overflow_policy": "FIFO queue: remaining candidates wait for subsequent registrations to reach batch threshold."
  }
}
```

---

## 🔒 5. Security & Business Rules Handled Automatically

1. **Anti-Race-Condition Concurrency:** Seat decrement happens atomically at the SQL level (`UPDATE exam_slots SET seats_remaining = seats_remaining - 1 WHERE id = ? AND seats_remaining > 0`). Two candidates clicking simultaneously at the last seat cannot oversell.
2. **IDOR Protection:** The API verifies candidate identity against `$_SESSION['candidate_id']`. Candidates cannot book or tamper with slots belonging to other users.
3. **Single Slot Holding Rule:** Candidates are prevented from booking more than one slot for an assessment. Attempting to book again returns a clear error message.
4. **Batch Ownership:** Candidates can only book slots that belong to the specific batch they were assigned to.

---

## ⚠️ 6. Error Handling & Status Codes Reference

| HTTP Code | Error Reason | Response Message | Recommended Frontend Action |
| :---: | :--- | :--- | :--- |
| `401` | Not logged in | `"Unauthorized: candidate authentication session required"` | Redirect user to `/login.php`. |
| `403` | IDOR mismatch | `"Forbidden: candidate_id does not match authenticated session"` | Invalidate corrupted local state or logout. |
| `400` | Ineligible | `"Candidate is not eligible to book an exam slot (Status: pending)"` | Redirect to payment page (`/payment.php`). |
| `400` | No batch yet | `"Candidate has not yet been assigned to an exam batch. Awaiting batch formation."` | Show dashboard notice: *"Your batch is being formed. Check back soon!"* |
| `400` | Duplicate attempt | `"Candidate already has a booked slot for this assessment (Attempt ID: X)"` | Show existing booking details instead of booking button. |
| `400` | Full Slot | `"Selected exam slot is fully booked. No seats remaining."` | Refresh slot list and ask user to pick another slot. |
| `400` | Invalid Input | `"A valid exam_slot_id is required"` | Validate dropdown selection before submitting. |
