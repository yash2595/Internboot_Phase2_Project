<?php
// Path: src/core/response.php

/**
 * Sends a standardized JSON response and terminates script execution.
 *
 * @param string $status  'success' or 'error'
 * @param string $message Human-readable status message
 * @param mixed  $data    Optional payload data
 * @param int    $code    HTTP status code (default: 200 for success, 400 for error)
 */
function send_json_response(string $status, string $message, $data = null, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    $response = [
        'status'  => $status,
        'message' => $message,
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}
?>
