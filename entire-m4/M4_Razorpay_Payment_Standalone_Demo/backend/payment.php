<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payments_common.php';

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
    $s=$conn->prepare('SELECT id, full_name, phone, user_id FROM candidates WHERE id=? LIMIT 1');
    $s->bind_param('i',$id); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); return $r ?: null;
}
function candidate_email(int $id): string {
    global $conn;
    $s=$conn->prepare('SELECT u.email FROM candidates c JOIN users u ON u.id=c.user_id WHERE c.id=? LIMIT 1');
    $s->bind_param('i',$id); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    return (string)($r['email'] ?? 'candidate@example.com');
}
function assessment(int $id): ?array {
    global $conn;
    $s=$conn->prepare('SELECT id,title,description,duration_minutes,total_questions,status FROM assessments WHERE id=? LIMIT 1');
    $s->bind_param('i',$id); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); return $r ?: null;
}
function fee(): float {
    global $conn;
    $s=$conn->prepare("SELECT setting_value FROM settings WHERE setting_key='exam_fee' LIMIT 1");
    $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); return $r ? (float)$r['setting_value'] : 2999.00;
}
function public_base_url(): string {
    $configured = rtrim(envv('APP_BASE_URL'), '/');
    if ($configured !== '') return $configured;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $folder = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . ($folder === '/' ? '' : $folder);
}

$action=$_GET['action'] ?? 'details';
try {
    if ($action==='details') {
        $candidateId=(isset($_GET['candidate_id']) && ctype_digit((string)$_GET['candidate_id'])) ? (int)$_GET['candidate_id'] : 1;
        $assessmentId=(isset($_GET['assessment_id']) && ctype_digit((string)$_GET['assessment_id'])) ? (int)$_GET['assessment_id'] : 0;
        $c=candidate($candidateId); if(!$c) out(['success'=>false,'message'=>'Candidate not found.'],404);
        if($assessmentId>0) $a=assessment($assessmentId); else {
            global $conn;
            $s=$conn->prepare("SELECT id,title,description,duration_minutes,total_questions,status FROM assessments WHERE status='active' ORDER BY id DESC LIMIT 1"); $s->execute(); $a=$s->get_result()->fetch_assoc(); $s->close();
            if(!$a){$s=$conn->prepare('SELECT id,title,description,duration_minutes,total_questions,status FROM assessments ORDER BY id DESC LIMIT 1');$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();}
        }
        if(!$a) out(['success'=>false,'message'=>'No assessment found in the database.'],404);
        $aid=(int)$a['id'];
        $s=$conn->prepare('SELECT id,amount,status,reference_number,payment_date,created_at FROM payments WHERE candidate_id=? AND assessment_id=? ORDER BY id DESC LIMIT 1');
        $s->bind_param('ii',$candidateId,$aid);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        $s=$conn->prepare('SELECT id,eligibility_status FROM enrollments WHERE candidate_id=? AND assessment_id=? LIMIT 1');$s->bind_param('ii',$candidateId,$aid);$s->execute();$e=$s->get_result()->fetch_assoc();$s->close();
        out(['success'=>true,'data'=>['candidate'=>['id'=>(int)$c['id'],'name'=>$c['full_name'],'phone'=>$c['phone'],'email'=>candidate_email($candidateId)],'assessment'=>['id'=>$aid,'title'=>$a['title'],'description'=>$a['description'],'duration'=>(int)$a['duration_minutes'],'questions'=>(int)$a['total_questions']],'fee'=>2999.00,'payment'=>$p,'enrollment'=>$e]]);
    }

    if ($action==='create') {
        $data=body(); $candidateId=(int)($data['candidate_id']??0); $assessmentId=(int)($data['assessment_id']??0);
        if($candidateId<1||$assessmentId<1) out(['success'=>false,'message'=>'Candidate and assessment are required.'],400);
        $c=candidate($candidateId); $a=assessment($assessmentId); if(!$c||!$a) out(['success'=>false,'message'=>'Candidate or assessment not found.'],404);
        if(payu_key()===''||payu_salt()==='') out(['success'=>false,'message'=>'PayU Test Key/Salt are not configured in .env.'],500);
        $s=$conn->prepare("SELECT id,amount,status,reference_number,payment_date FROM payments WHERE candidate_id=? AND assessment_id=? AND status='success' ORDER BY id DESC LIMIT 1");$s->bind_param('ii',$candidateId,$assessmentId);$s->execute();$success=$s->get_result()->fetch_assoc();$s->close();
        if($success) out(['success'=>true,'already_paid'=>true,'payment'=>$success]);
        $amount=2999.00; $txnid=make_txnid(); $ref=$txnid;
        $s=$conn->prepare("INSERT INTO payments (candidate_id,assessment_id,amount,status,reference_number) VALUES (?,?,?,'pending',?)");
        $s->bind_param('iids',$candidateId,$assessmentId,$amount,$ref); $s->execute(); $paymentId=$s->insert_id; $s->close();
        $first=trim(explode(' ',trim($c['full_name']),2)[0]); $email=candidate_email($candidateId); $product='InternBoot Assessment Registration';
        $params=['key'=>payu_key(),'txnid'=>$txnid,'amount'=>number_format($amount,2,'.',''),'productinfo'=>$product,'firstname'=>$first,'email'=>$email,'phone'=>(string)$c['phone'],'surl'=>public_base_url().'/backend/payu-return.php?type=success','furl'=>public_base_url().'/backend/payu-return.php?type=failure','udf1'=>(string)$paymentId,'udf2'=>(string)$candidateId,'udf3'=>(string)$assessmentId,'udf4'=>$ref,'udf5'=>''];
        $params['hash']=request_hash($params);
        $_SESSION['m4_payu_payment']=['payment_id'=>$paymentId,'candidate_id'=>$candidateId,'assessment_id'=>$assessmentId,'txnid'=>$txnid,'expires'=>time()+1800];
        out(['success'=>true,'payment_id'=>$paymentId,'reference_number'=>$ref,'amount'=>$amount,'payu_url'=>payu_test_url(),'form'=>$params,'mode'=>'payu_test']);
    }

    if ($action==='verify') {
        $data=body(); $paymentId=(int)($data['payment_id']??0); if($paymentId<1) out(['success'=>false,'message'=>'Payment ID is required.'],400);
        $s=$conn->prepare('SELECT id,candidate_id,assessment_id,amount,status,reference_number FROM payments WHERE id=? LIMIT 1');$s->bind_param('i',$paymentId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p) out(['success'=>false,'message'=>'Payment record not found.'],404);
        // Do not require the browser session here.
        // PayU posts the callback from a different site, so a normal PHP
        // session cookie may not be available on the callback/return flow.
        // The DB payment record + PayU server-side verification are the source of truth.
        $verification=verify_payment_with_payu((string)$p['reference_number']);
        $details=$verification['transaction_details']??[];
        $tx=$details[$p['reference_number']]??(is_array($details)?reset($details):[]);
        $status=strtolower((string)($tx['status']??''));
        if($status==='') $status=strtolower((string)($verification['status']??''));
        $unmapped=strtolower((string)($tx['unmappedstatus']??''));
        $verifiedSuccess = ($status==='success' || $status==='captured' || $unmapped==='captured');
        if($verifiedSuccess) {
            $conn->begin_transaction();
            try { $s=$conn->prepare("UPDATE payments SET status='success',payment_date=NOW() WHERE id=?");$s->bind_param('i',$paymentId);$s->execute();$s->close();$cid=(int)$p['candidate_id'];$aid=(int)$p['assessment_id'];$s=$conn->prepare("INSERT INTO enrollments (candidate_id,assessment_id,payment_id,eligibility_status) VALUES (?,?,?,'eligible') ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id),eligibility_status='eligible'");$s->bind_param('iii',$cid,$aid,$paymentId);$s->execute();$s->close();$conn->commit(); } catch(Throwable $e){$conn->rollback();throw $e;}
            unset($_SESSION['m4_payu_payment']); out(['success'=>true,'status'=>'success','message'=>'Payment verified by PayU. Enrollment is now eligible.','payment'=>['id'=>$paymentId,'reference_number'=>$p['reference_number'],'amount'=>(float)$p['amount'],'status'=>'success']]);
        }
        out(['success'=>true,'status'=>$status?:'pending','message'=>'PayU verification returned a non-success status. Enrollment was not created.','payment'=>['id'=>$paymentId,'reference_number'=>$p['reference_number'],'amount'=>(float)$p['amount'],'status'=>$status?:'pending']]);
    }
    out(['success'=>false,'message'=>'Unknown action.'],400);
} catch(Throwable $e) { out(['success'=>false,'message'=>'Server error: '.$e->getMessage()],500); }
