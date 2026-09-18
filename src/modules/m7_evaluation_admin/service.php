<?php
require_once __DIR__ . '/queries.php';

function m7_dashboard(mysqli $conn): array { return get_dashboard_stats($conn); }
function m7_candidates(mysqli $conn): array { return get_candidates($conn); }
function m7_results(mysqli $conn): array { return get_results($conn); }
function m7_pending_attempts(mysqli $conn): array { return get_pending_attempts($conn); }
function m7_certificates(mysqli $conn): array { return get_certificates($conn); }
function m7_placements(mysqli $conn): array { sync_placement_records($conn); return get_placement_records($conn); }
function m7_questions(mysqli $conn): array { return get_questions($conn); }
function m7_batches(mysqli $conn): array { return get_batches($conn); }
function m7_settings(mysqli $conn): array { return get_settings($conn); }

function evaluate_attempt(mysqli $conn, int $attemptId, bool $generateCertificate=false): array
{
    $conn->begin_transaction();
    try {
        $attempt=get_attempt_for_update($conn,$attemptId);
        if(!$attempt) throw new InvalidArgumentException('Attempt not found.');

        if($attempt['status']==='submitted'){
            $existing=q_one($conn,'SELECT r.*, c.full_name FROM results r JOIN attempts a ON a.id=r.attempt_id JOIN candidates c ON c.id=a.candidate_id WHERE r.attempt_id=?','i',[$attemptId]);
            $conn->commit();
            return ['result'=>$existing,'already_evaluated'=>true];
        }

        // Guard: only 'expired' (and 'in_progress' whose timer has silently run out)
        // may be evaluated. A genuinely live in_progress attempt must not be graded.
        if($attempt['status']==='in_progress'){
            // Check whether the wall-clock end_time has already passed inside this
            // transaction (same logic M6 uses for lazy expiry on candidate requests).
            $timerRow=q_one($conn,'SELECT end_time <= NOW() AS timer_expired FROM attempts WHERE id=? FOR UPDATE','i',[$attemptId]);
            if($timerRow && (int)$timerRow['timer_expired']===1){
                // Timer has run out but M6 hasn't lazy-expired it yet.
                // Auto-expire here so grading is fair and consistent with M6 behaviour.
                $conn->query("UPDATE attempts SET status='expired', submitted_at=NOW() WHERE id={$attemptId} AND status='in_progress'");
                // Re-read the attempt so $attempt['status'] reflects the new state downstream.
                $attempt=get_attempt_for_update($conn,$attemptId);
            } else {
                // Candidate is actively mid-exam — reject without touching anything.
                throw new InvalidArgumentException(
                    'Cannot evaluate an attempt that is still in progress. '.
                    'The candidate must submit or the attempt must expire first.'
                );
            }
        }

        // Defensive catch-all: reject any status that is neither 'submitted' nor 'expired'.
        // With the current ENUM('in_progress','submitted','expired') this branch is
        // unreachable, but guards against future schema changes.
        if($attempt['status']!=='expired'){
            throw new InvalidArgumentException(
                "Attempt has unexpected status '{$attempt['status']}' and cannot be evaluated."
            );
        }

        $answers=get_answer_rows($conn,$attemptId);
        $correct=0;
        foreach($answers as $answer){
            $isCorrect=(int)$answer['is_correct'];
            $correct += $isCorrect;
            save_answer_correctness($conn,(int)$answer['answer_id'],$isCorrect);
        }

        $total=max(1,(int)$attempt['total_questions']);
        $percentage=round(($correct/$total)*100,2);
        $level=get_level_for_percentage($conn,$percentage);
        if(!$level) throw new RuntimeException('No level mapping exists for this percentage.');

        $resultId=upsert_result($conn,$attemptId,(float)$correct,$percentage,(int)$level['level_number']);
        update_attempt_submitted($conn,$attemptId);
        ensure_placement_record($conn,(int)$attempt['candidate_id'],$resultId);

        $certificate=null;
        if($generateCertificate){
            $certificate=upsert_certificate($conn,(int)$attempt['candidate_id'],$resultId,(int)$level['level_number']);
        }

        create_admin_log($conn,$_SESSION['user_id']??null,'evaluate_attempt',json_encode([
            'attempt_id'=>$attemptId,'result_id'=>$resultId,'percentage'=>$percentage,'level'=>$level['level_number']
        ]));

        $conn->commit();

        return [
            'result_id'=>$resultId,
            'attempt_id'=>$attemptId,
            'candidate_id'=>(int)$attempt['candidate_id'],
            'score'=>$correct,
            'total_questions'=>$total,
            'percentage'=>$percentage,
            'level'=>(int)$level['level_number'],
            'level_name'=>$level['level_name'],
            'certificate'=>$certificate
        ];
    }catch(Throwable $e){
        $conn->rollback();
        throw $e;
    }
}

function generate_certificate(mysqli $conn,int $resultId): array
{
    $row=q_one($conn,"SELECT r.id result_id,r.level_assigned,r.percentage,c.id candidate_id,c.full_name,u.email,a.title assessment_title
      FROM results r
      JOIN attempts at ON at.id=r.attempt_id
      JOIN candidates c ON c.id=at.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN assessments a ON a.id=at.assessment_id
      WHERE r.id=?",'i',[$resultId]);

    if(!$row) throw new InvalidArgumentException('Result not found.');

    return upsert_certificate(
        $conn,
        (int)$row['candidate_id'],
        $resultId,
        (int)$row['level_assigned']
    ) + [
        'candidate'=>$row['full_name'],
        'email'=>$row['email'],
        'assessment'=>$row['assessment_title'],
        'percentage'=>$row['percentage'],
        'level'=>$row['level_assigned']
    ];
}

function generate_next_certificate(mysqli $conn): array
{
    $row=q_one($conn,"SELECT r.id
        FROM results r
        LEFT JOIN certificates c ON c.result_id=r.id
        WHERE c.id IS NULL
        ORDER BY r.created_at ASC
        LIMIT 1");

    if(!$row) throw new InvalidArgumentException('There are no results waiting for a certificate.');
    return generate_certificate($conn,(int)$row['id']);
}
