<?php
require_once __DIR__ . '/service.php';

function m7_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('Request body must contain valid JSON.');
    }
    return is_array($data) ? $data : [];
}

function m7_handle_request(mysqli $conn): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'dashboard';

    if ($method === 'GET' && $action === 'csrf') {
        send_json_response('success', 'Security token loaded.', ['token' => csrf_token()]);
    }

    require_admin_access($conn);

    if ($method === 'GET') {
        switch ($action) {
            case 'dashboard': send_json_response('success','Dashboard data loaded',m7_dashboard($conn));
            case 'candidates': send_json_response('success','Candidates loaded',['candidates'=>m7_candidates($conn)]);
            case 'candidate':
                $id=require_positive_int($_GET['id']??null,'id');
                $data=get_candidate($conn,$id);
                if(!$data) throw new InvalidArgumentException('Candidate not found.');
                send_json_response('success','Candidate loaded',$data);
            case 'results': send_json_response('success','Results loaded',['results'=>m7_results($conn)]);
            case 'pending-attempts': send_json_response('success','Pending attempts loaded',['attempts'=>m7_pending_attempts($conn)]);
            case 'attempt':
                require_admin_only($conn);
                $id=require_positive_int($_GET['id']??null,'id');
                $data=get_attempt_detail($conn,$id);
                if(!$data) throw new InvalidArgumentException('Attempt not found.');
                send_json_response('success','Attempt loaded',$data);
            case 'certificates': send_json_response('success','Certificates loaded',['certificates'=>m7_certificates($conn)]);
            case 'certificate-verify':
                $number=trim((string)($_GET['certificate_number']??''));
                if($number==='' || strlen($number)>100) throw new InvalidArgumentException('Enter a valid certificate number.');
                $certificate=verify_certificate($conn,$number);
                if(!$certificate) send_json_response('success','Certificate not found.',['verified'=>false]);
                send_json_response('success','Certificate verified.',['verified'=>true,'certificate'=>$certificate]);
            case 'placements': send_json_response('success','Placement records loaded',['placements'=>m7_placements($conn)]);
            case 'questions':
                $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
                send_json_response('success','Questions loaded',['questions'=>m7_questions($conn, $isAdmin)]);
            case 'batches': send_json_response('success','Batches loaded',m7_batches($conn));
            case 'question-banks': send_json_response('success','Question banks loaded',['question_banks'=>m7_question_banks($conn)]);
            case 'settings': send_json_response('success','Settings loaded',m7_settings($conn));
            case 'health':
                $required=['users','candidates','payments','assessments','batches','enrollments','exam_schedules','exam_slots','question_banks','questions','options','attempts','answers','results','levels','certificates','placement_records','admin_logs','settings'];
                $missing=[];
                $stmt=$conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
                foreach($required as $table){
                    $stmt->bind_param('s',$table); $stmt->execute();
                    if(!$stmt->get_result()->fetch_row()) $missing[]=$table;
                }
                $stmt->close();
                send_json_response('success','M7 health check completed',['database'=>'connected','missing_tables'=>$missing,'ready'=>count($missing)===0]);
            default: throw new InvalidArgumentException('Unknown admin action.');
        }
    }

    if($method!=='POST') send_json_response('error','Method not allowed.',null,405);
    require_csrf();

    if (in_array($action, [
        'setting', 'batch', 'slot', 'allocate',
        'evaluate', 'certificate', 'certificate-next', 'placement', 'question-status'
    ], true)) {
        require_admin_only($conn);
    }

    /* Avatar upload uses multipart/form-data; all other POST actions use JSON. */
    if ($action === 'avatar-upload') {
        if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            throw new InvalidArgumentException('Please choose an image.');
        }
        $file = $_FILES['avatar'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The image upload failed.');
        }
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Image must be 2 MB or smaller.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $info = @getimagesize($tmp);
        if (!$info || empty($info['mime'])) throw new InvalidArgumentException('Please upload a valid image.');
        $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        $mime = strtolower((string)$info['mime']);
        if (!isset($allowed[$mime])) throw new InvalidArgumentException('Only PNG, JPG or WEBP images are allowed.');

        $adminUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($adminUserId <= 0) {
            $admin = q_one($conn, 'SELECT id FROM users WHERE role IN ("admin","staff") AND is_active=1 ORDER BY id LIMIT 1');
            $adminUserId = $admin ? (int)$admin['id'] : 0;
        }
        if ($adminUserId <= 0) throw new InvalidArgumentException('Administrator account could not be resolved.');

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Upload directory could not be created.');
        }
        $filename = 'admin-avatar-' . $adminUserId . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Could not save the uploaded image.');

        $avatarUrl = '/uploads/' . $filename;
        update_setting($conn, 'admin_avatar_url', $avatarUrl);
        create_admin_log($conn, $adminUserId, 'avatar_update', json_encode(['avatar_url'=>$avatarUrl]));
        send_json_response('success','Profile photo updated successfully.', ['avatar_url'=>$avatarUrl]);
    }

    $body=m7_request_body();

    switch($action){
        case 'candidate-create':
            $name = trim((string)($body['full_name'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $phone = trim((string)($body['phone'] ?? ''));
            $data = create_admin_candidate($conn, $name, $email, $phone);
            create_admin_log($conn, $_SESSION['user_id'] ?? null, 'create_candidate', json_encode([
                'candidate_id' => $data['id'],
                'email' => $data['email']
            ]));
            send_json_response('success', 'Candidate created successfully.', $data, 201);

        case 'evaluate':
            $id=require_positive_int($body['attempt_id']??null,'attempt_id');
            $data=evaluate_attempt($conn,$id,(bool)($body['generate_certificate']??false));
            send_json_response('success','Attempt evaluated successfully.',$data);

        case 'certificate':
            $id=require_positive_int($body['result_id']??null,'result_id');
            $data=generate_certificate($conn,$id);
            create_admin_log($conn,$_SESSION['user_id']??null,'generate_certificate',json_encode(['result_id'=>$id,'certificate_id'=>$data['id']]));
            send_json_response('success','Certificate generated successfully.',$data);

        case 'certificate-next':
            $data=generate_next_certificate($conn);
            create_admin_log($conn,$_SESSION['user_id']??null,'generate_certificate',json_encode(['result_id'=>$data['result_id']??null,'certificate_id'=>$data['id']]));
            send_json_response('success','Certificate generated successfully.',$data);

        case 'placement':
            $id=require_positive_int($body['id']??null,'id');
            $status=trim((string)($body['status']??''));
            $allowed=['eligible','shortlisted','interviewing','placed','not_placed'];
            if(!in_array($status,$allowed,true)) throw new InvalidArgumentException('Invalid placement status.');
            update_placement($conn,$id,$status,isset($body['company_name'])?(string)$body['company_name']:null,isset($body['notes'])?(string)$body['notes']:null);
            create_admin_log($conn,$_SESSION['user_id']??null,'update_placement',json_encode(['placement_id'=>$id,'status'=>$status]));
            send_json_response('success','Placement record updated successfully.');

        case 'batch':
            $date=trim((string)($body['exam_date']??''));
            $capacity=require_positive_int($body['capacity']??null,'capacity');
            $name=trim((string)($body['batch_number']??('BATCH-'.date('Ymd-His'))));
            $assessmentId=!empty($body['assessment_id']) ? require_positive_int($body['assessment_id'],'assessment_id') : 0;
            $startTime=trim((string)($body['start_time']??'10:00:00'));
            $endTime=trim((string)($body['end_time']??'11:00:00'));
            $data=create_batch($conn,$name,$assessmentId,$date,$capacity,$startTime,$endTime);
            create_admin_log($conn,$_SESSION['user_id']??null,'create_batch',json_encode($data));
            send_json_response('success','Batch and unified exam slot created successfully.',$data,201);

        case 'reassign-batch':
            $enrollmentIds = $body['enrollment_ids'] ?? [];
            if (!is_array($enrollmentIds) && isset($body['enrollment_id'])) {
                $enrollmentIds = [(int)$body['enrollment_id']];
            }
            $targetBatchId = require_positive_int($body['target_batch_id'] ?? null, 'target_batch_id');
            $data = reassign_candidates_batch($conn, $enrollmentIds, $targetBatchId);
            create_admin_log($conn, $_SESSION['user_id'] ?? null, 'reassign_batch', json_encode($data));
            send_json_response('success', "Successfully reassigned {$data['moved_count']} candidate(s) to {$data['target_batch_number']}.", $data);

        case 'slot':
            $batchId=require_positive_int($body['batch_id']??null,'batch_id');
            $start=trim((string)($body['start_time']??''));
            $end=trim((string)($body['end_time']??''));
            $capacity=require_positive_int($body['capacity']??null,'capacity');
            $data=create_exam_slot($conn,$batchId,$start,$end,$capacity);
            create_admin_log($conn,$_SESSION['user_id']??null,'create_slot',json_encode($data));
            send_json_response('success','Exam slot created successfully.',$data,201);

        case 'allocate':
            $enrollmentId=require_positive_int($body['enrollment_id']??null,'enrollment_id');
            $batchId=require_positive_int($body['batch_id']??null,'batch_id');
            $slotId=require_positive_int($body['slot_id']??null,'slot_id');
            $data=allocate_candidate_to_batch($conn,$enrollmentId,$batchId,$slotId);
            create_admin_log($conn,$_SESSION['user_id']??null,'allocate_candidate',json_encode($data));
            send_json_response('success','Candidate allocated successfully.',$data);

        case 'question-status':
            $id=require_positive_int($body['question_id']??null,'question_id');
            $status=trim((string)($body['status']??''));
            update_question_status($conn,$id,$status);
            create_admin_log($conn,$_SESSION['user_id']??null,'question_status',json_encode(['question_id'=>$id,'status'=>$status]));
            send_json_response('success','Question status updated successfully.');

        case 'setting':
            $key=trim((string)($body['key']??''));
            $value=trim((string)($body['value']??''));
            if($key==='' || strlen($key)>100) throw new InvalidArgumentException('Invalid setting key.');
            if(strlen($value)>255) throw new InvalidArgumentException('Setting value is too long.');
            update_setting($conn,$key,$value);
            send_json_response('success','Setting saved successfully.');

        case 'profile':
            $name=trim((string)($body['full_name']??''));
            $email=trim((string)($body['email']??''));
            $phone=trim((string)($body['phone']??''));
            if($name==='') throw new InvalidArgumentException('Full name is required.');
            update_admin_profile($conn,$_SESSION['user_id']??null,$name,$email,$phone);
            send_json_response('success','Profile saved successfully.');

        case 'logout':
            require_once __DIR__ . '/../m3_auth/controller.php';
            handle_logout_request();


        default: throw new InvalidArgumentException('Unknown admin action.');
    }
}
