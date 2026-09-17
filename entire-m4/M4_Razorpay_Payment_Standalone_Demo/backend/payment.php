<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function candidate(int $id): ?array {
    global $conn;
    $s = $conn->prepare('SELECT id, full_name, phone, user_id FROM candidates WHERE id=? LIMIT 1');
    $s->bind_param('i', $id); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
}

function assessment(int $id): ?array {
    global $conn;
    $s = $conn->prepare('SELECT id, title, description, duration_minutes, total_questions, status FROM assessments WHERE id=? LIMIT 1');
    $s->bind_param('i', $id); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
}

function fee(): float {
    global $conn;
    $s = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='exam_fee' LIMIT 1");
    $s->execute(); $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ? (float)$r['setting_value'] : 2999.00;
}

$action = $_GET['action'] ?? 'details';

try {
    if ($action === 'details') {
        $candidateId = isset($_GET['candidate_id']) && ctype_digit((string)$_GET['candidate_id']) ? (int)$_GET['candidate_id'] : 1;
        $assessmentId = isset($_GET['assessment_id']) && ctype_digit((string)$_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;

        $c = candidate($candidateId);
        if (!$c) out(['success'=>false,'message'=>'Candidate not found.'],404);

        if ($assessmentId > 0) {
            $a = assessment($assessmentId);
        } else {
            $s = $conn->prepare("SELECT id,title,description,duration_minutes,total_questions,status FROM assessments WHERE status='active' ORDER BY id DESC LIMIT 1");
            $s->execute(); $a = $s->get_result()->fetch_assoc(); $s->close();
            if (!$a) {
                $s = $conn->prepare('SELECT id,title,description,duration_minutes,total_questions,status FROM assessments ORDER BY id DESC LIMIT 1');
                $s->execute(); $a = $s->get_result()->fetch_assoc(); $s->close();
            }
        }
        if (!$a) out(['success'=>false,'message'=>'No assessment found in the database.'],404);

        $aid=(int)$a['id'];
        $s=$conn->prepare('SELECT id,amount,status,reference_number,payment_date,created_at FROM payments WHERE candidate_id=? AND assessment_id=? ORDER BY id DESC LIMIT 1');
        $s->bind_param('ii',$candidateId,$aid); $s->execute(); $p=$s->get_result()->fetch_assoc(); $s->close();
        $s=$conn->prepare('SELECT id,eligibility_status FROM enrollments WHERE candidate_id=? AND assessment_id=? LIMIT 1');
        $s->bind_param('ii',$candidateId,$aid); $s->execute(); $e=$s->get_result()->fetch_assoc(); $s->close();

        out(['success'=>true,'data'=>[
            'candidate'=>['id'=>(int)$c['id'],'name'=>$c['full_name'],'phone'=>$c['phone']],
            'assessment'=>['id'=>$aid,'title'=>$a['title'],'description'=>$a['description'],'duration'=>(int)$a['duration_minutes'],'questions'=>(int)$a['total_questions']],
            'fee'=>$p ? (float)$p['amount'] : fee(),
            'payment'=>$p,
            'enrollment'=>$e
        ]]);
    }

    if ($action === 'create') {
        $data=body();
        $candidateId=(int)($data['candidate_id'] ?? 0);
        $assessmentId=(int)($data['assessment_id'] ?? 0);
        if ($candidateId < 1 || $assessmentId < 1) out(['success'=>false,'message'=>'Candidate and assessment are required.'],400);
        if (!candidate($candidateId) || !assessment($assessmentId)) out(['success'=>false,'message'=>'Candidate or assessment not found.'],404);

        $s=$conn->prepare("SELECT id,amount,status,reference_number,payment_date FROM payments WHERE candidate_id=? AND assessment_id=? AND status='success' ORDER BY id DESC LIMIT 1");
        $s->bind_param('ii',$candidateId,$assessmentId); $s->execute(); $success=$s->get_result()->fetch_assoc(); $s->close();
        if ($success) out(['success'=>true,'already_paid'=>true,'payment'=>$success]);

        $amount=fee();
        $ref='IB-DEMO-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
        $s=$conn->prepare("INSERT INTO payments (candidate_id,assessment_id,amount,status,reference_number) VALUES (?,?,?,'pending',?)");
        $s->bind_param('iids',$candidateId,$assessmentId,$amount,$ref); $s->execute(); $paymentId=$s->insert_id; $s->close();

        $token=bin2hex(random_bytes(32));
        $_SESSION['m4_demo_payment']=['payment_id'=>$paymentId,'candidate_id'=>$candidateId,'assessment_id'=>$assessmentId,'token_hash'=>hash('sha256',$token),'expires'=>time()+900];
        out(['success'=>true,'payment_id'=>$paymentId,'reference_number'=>$ref,'amount'=>$amount,'token'=>$token,'mode'=>'demo']);
    }

    if ($action === 'verify') {
        $data=body();
        $paymentId=(int)($data['payment_id'] ?? 0); $token=(string)($data['token'] ?? '');
        $sesh=$_SESSION['m4_demo_payment'] ?? null;
        if (!$sesh || $paymentId < 1 || !hash_equals((string)$sesh['token_hash'],hash('sha256',$token)) || (int)$sesh['payment_id']!==$paymentId || time()>(int)$sesh['expires']) {
            out(['success'=>false,'message'=>'Demo payment verification failed. Please start the payment again.'],403);
        }

        $conn->begin_transaction();
        try {
            $s=$conn->prepare("SELECT id,candidate_id,assessment_id,amount,status,reference_number FROM payments WHERE id=? FOR UPDATE");
            $s->bind_param('i',$paymentId); $s->execute(); $p=$s->get_result()->fetch_assoc(); $s->close();
            if (!$p) throw new RuntimeException('Payment record not found.');
            if ($p['status'] !== 'success') {
                $s=$conn->prepare("UPDATE payments SET status='success', payment_date=NOW() WHERE id=? AND status='pending'");
                $s->bind_param('i',$paymentId); $s->execute(); $s->close();
            }
            $candidateId=(int)$p['candidate_id']; $assessmentId=(int)$p['assessment_id'];
            $s=$conn->prepare("INSERT INTO enrollments (candidate_id,assessment_id,payment_id,eligibility_status) VALUES (?,?,?,'eligible') ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id), eligibility_status='eligible'");
            $s->bind_param('iii',$candidateId,$assessmentId,$paymentId); $s->execute(); $s->close();
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }

        unset($_SESSION['m4_demo_payment']);
        out(['success'=>true,'message'=>'Payment verified on server. Enrollment is now eligible.','payment'=>['id'=>$paymentId,'reference_number'=>$p['reference_number'],'amount'=>(float)$p['amount'],'status'=>'success','payment_date'=>date('Y-m-d H:i:s')],'enrollment_status'=>'eligible']);
    }

    out(['success'=>false,'message'=>'Unknown action.'],400);
} catch (Throwable $e) {
    out(['success'=>false,'message'=>'Server error: '.$e->getMessage()],500);
}
