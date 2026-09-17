# InternBoot M7 Admin API

Public entry point:

```text
/public/api/admin/evaluate.php
```

## GET

- `?action=csrf` – returns the session CSRF token
- `?action=health` – checks the database and required M7 tables
- `?action=dashboard`
- `?action=candidates`
- `?action=candidate&id=1`
- `?action=results`
- `?action=pending-attempts`
- `?action=attempt&id=1`
- `?action=certificates`
- `?action=certificate-verify&certificate_number=IB-2026-123456`
- `?action=placements`
- `?action=questions`
- `?action=batches`
- `?action=settings`

## POST JSON

All POST requests require the `X-CSRF-Token` header.

- `?action=evaluate` with `{ "attempt_id": 1, "generate_certificate": true }`
- `?action=certificate` with `{ "result_id": 1 }`
- `?action=certificate-next` with `{}`
- `?action=placement` with `{ "id": 1, "status": "placed", "company_name": "ABC", "notes": "..." }`
- `?action=batch` with `{ "batch_number": "BATCH-01", "exam_date": "2026-09-20", "capacity": 100 }`
- `?action=slot` with `{ "batch_id": 1, "start_time": "10:00", "end_time": "11:00", "capacity": 50 }`
- `?action=allocate` with `{ "enrollment_id": 1, "batch_id": 1, "slot_id": 1 }`
- `?action=question-status` with `{ "question_id": 1, "status": "approved" }`
- `?action=setting` with `{ "key": "batch_notifications", "value": "1" }`
- `?action=profile` with `{ "full_name": "Admin", "email": "admin@internboot.com", "phone": "..." }`
- `?action=logout` with `{}`

Certificate PDF:

```text
/public/api/admin/certificate_pdf.php?result_id=1
```
