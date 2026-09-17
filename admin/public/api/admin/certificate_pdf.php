<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/queries.php';
require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/pdf.php';

try {
    require_admin_access($conn);
    $resultId=require_positive_int($_GET['result_id']??null,'result_id');
    $data=generate_certificate($conn,$resultId);
    output_certificate_pdf($data);
} catch (Throwable $e) {
    error_log('InternBoot M7 certificate PDF error: ' . $e->getMessage());
    $dev = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') === 'development';
    send_json_response('error',$dev?$e->getMessage():'Unable to generate certificate.',null,422);
}
