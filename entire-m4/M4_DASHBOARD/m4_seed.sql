-- M4 development seed data.
-- Run schema.sql first. This creates one demo candidate, assessment,
-- batch, exam schedule/slot, successful payment and enrollment.
-- If M1/M3 data already exists, do NOT run this blindly; use their IDs.

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO users (id, email, password, role, is_active)
VALUES (1001, 'john.doe@example.com', '$2y$10$abcdefghijklmnopqrstuuV4l4zQ6fXn6uQmQv4l0x4bVYkJ0m', 'candidate', 1)
ON DUPLICATE KEY UPDATE email=VALUES(email);

INSERT INTO candidates (id, user_id, full_name, phone, profile_details)
VALUES (
  1001, 1001, 'John Doe', '+91 98765 43210',
  '{"dateOfBirth":"15 May 2003","gender":"Male","address":"New Delhi, India"}'
)
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), phone=VALUES(phone), profile_details=VALUES(profile_details);

INSERT INTO assessments (id, title, description, duration_minutes, total_questions, status)
VALUES (1001, 'Level Test', 'InternBoot Level Assessment', 60, 50, 'active')
ON DUPLICATE KEY UPDATE title=VALUES(title), duration_minutes=VALUES(duration_minutes),
total_questions=VALUES(total_questions), status=VALUES(status);

INSERT INTO batches (id, batch_number, assessment_id)
VALUES (1001, 'WB-2026-01', 1001)
ON DUPLICATE KEY UPDATE batch_number=VALUES(batch_number), assessment_id=VALUES(assessment_id);

INSERT INTO payments (id, candidate_id, assessment_id, amount, status, reference_number, payment_date)
VALUES (1001, 1001, 1001, 2999.00, 'success', 'M4-DEMO-1001', NOW())
ON DUPLICATE KEY UPDATE status='success', amount=2999.00, payment_date=NOW();

INSERT INTO enrollments (id, candidate_id, assessment_id, payment_id, batch_id, eligibility_status)
VALUES (1001, 1001, 1001, 1001, 1001, 'eligible')
ON DUPLICATE KEY UPDATE payment_id=1001, batch_id=1001, eligibility_status='eligible';

INSERT INTO exam_schedules (id, batch_id, exam_date, status)
VALUES (1001, 1001, '2026-08-22', 'scheduled')
ON DUPLICATE KEY UPDATE exam_date=VALUES(exam_date), status=VALUES(status);

INSERT INTO exam_slots (id, exam_schedule_id, start_time, end_time, capacity, seats_remaining)
VALUES (1001, 1001, '10:00:00', '11:00:00', 50, 49)
ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time);

SET FOREIGN_KEY_CHECKS = 1;
