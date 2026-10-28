<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');
error_reporting(0);

class SokaMpush extends CI_Controller {

    private $allowedChannels = array('SMS', 'USSD', 'APP');

    public function __construct() {
        parent::__construct();
    }

    private function sokaGateway()
    {
        if (!isset($this->TigoSokaGatewayServices)) {
            $this->load->model('TigoSokaGatewayServices', 'TigoSokaGatewayServices');
        }
        return $this->TigoSokaGatewayServices;
    }

    function notification() {
        $requestData = file_get_contents("php://input");
        if (empty($requestData)) {
            $this->respond(array(
                'status' => '1',
                'statusCode' => '1001',
                'description' => 'Empty Request'
            ));
            return;
        }

        $requestFile = "logs/SokaMpush-" . date("YmdH") . ".txt";
        file_put_contents($requestFile, $requestData . " \n", FILE_APPEND);

        $requestArray = json_decode($requestData, TRUE);
        if (!is_array($requestArray)) {
            $this->respond(array(
                'status' => '1',
                'statusCode' => '1002',
                'description' => 'Invalid JSON'
            ));
            return;
        }

        $msisdn = isset($requestArray['msisdn']) ? $requestArray['msisdn'] : (isset($requestArray['MSISDN']) ? $requestArray['MSISDN'] : null);
        $channel = isset($requestArray['channel']) ? strtoupper(trim($requestArray['channel'])) : (isset($requestArray['CHANNEL']) ? strtoupper(trim($requestArray['CHANNEL'])) : null);
        $ussdData = isset($requestArray['ussd_data']) ? $requestArray['ussd_data'] : (isset($requestArray['USSD_DATA']) ? $requestArray['USSD_DATA'] : null);

        if (empty($msisdn) || empty($channel)) {
            $this->respond(array(
                'status' => '1',
                'statusCode' => '1003',
                'description' => 'msisdn and channel are required'
            ));
            return;
        }

        if (!in_array($channel, $this->allowedChannels, TRUE)) {
            $this->respond(array(
                'status' => '1',
                'statusCode' => '1004',
                'description' => 'channel must be one of SMS, USSD, APP'
            ));
            return;
        }

        $recordData = array(
            'MSISDN' => $msisdn,
            'CHANNEL' => $channel,
            'IS_OUTGOING' => 1
        );
        if ($ussdData !== null && $ussdData !== '') {
            $recordData['USSD_DATA'] = $ussdData;
        }
        $tbl = "tbl_mpush_subs_init_notifications_" . date("Ymd");
        $id = $this->sokaGateway()->_recordData($recordData, "trivia_tigotz_vas", $tbl);

        $responseData = array(
            'status' => '0',
            'statusCode' => '0000',
            'description' => 'Success'
        );
        file_put_contents($requestFile, json_encode($responseData) . " \n", FILE_APPEND);
        $this->respond($responseData);
    }

    private function respond($responseData)
    {
        header('Content-Type: application/json');
        echo json_encode($responseData);
        exit;
    }
}
