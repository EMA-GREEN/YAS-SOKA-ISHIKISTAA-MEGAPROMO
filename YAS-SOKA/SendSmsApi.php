<?php

header('Content-Type: application/json');

require_once "SokaLib.php";
require_once "SokaServices.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

// Read raw input (for JSON payloads) and log incoming request payload
$rawInput = file_get_contents('php://input');
$jsonBody = null;
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/sms_requests_' . date('Ymd') . '.txt';
$logData = [
    'time'      => date('Y-m-d H:i:s'),
    'ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    'post'      => $_POST,
    'raw_input' => $rawInput,
    'json'      => $jsonBody,
];
// @file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);

// Support both application/json body and form-encoded POST
if ($jsonBody !== null) {
    $msisdn = isset($jsonBody['msisdn']) ? trim($jsonBody['msisdn']) : null;
    $sms    = isset($jsonBody['text']) ? trim($jsonBody['text']) : null;
} else {
    $msisdn = isset($_POST['msisdn']) ? trim($_POST['msisdn']) : null;
    $sms    = isset($_POST['text']) ? trim($_POST['text']) : null;
}

if (empty($msisdn) || empty($sms)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing required parameter(s): msisdn and text',
    ]);
    exit;
}

try {
    $response = sendSMSApi($msisdn, $sms);

    echo json_encode([
        'status'  => 'success',
        'msisdn'  => $msisdn,
        'sms'     => $sms,
        'message' => '0: Accepted for delivery',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Failed to send SMS',
        'error'   => $e->getMessage(),
    ]);
}

function sendSMSApi($msisdn, $smsText) {
    $requestID = "E" . date("Ymdhis") . $msisdn;
    $sokaServicesObj = new SokaServices("consumer");
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, $requestID, 0);
    $response = $sokaLib->sendSMSApi($smsText);
    $sokaServicesObj->closeDB();

    return $response;
}
