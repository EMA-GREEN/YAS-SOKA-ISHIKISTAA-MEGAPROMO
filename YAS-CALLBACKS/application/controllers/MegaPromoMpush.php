<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');
error_reporting(0);

class MegaPromoMpush extends CI_Controller {

    private $allowedChannels = array('SMS', 'USSD', 'APP');

    public function __construct() {
        parent::__construct();
    }

    private function megaPromoGateway()
    {
        if (!isset($this->TigoMegaPromoServices)) {
            $this->load->model('TigoMegaPromoServices', 'TigoMegaPromoServices');
        }
        return $this->TigoMegaPromoServices;
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

        $requestFile = "logs/MegaPromoMpush-" . date("YmdH") . ".txt";
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
        $tbl = "tbl_mpush_subs_init_notifications_" . date("Ymd");
        $id = $this->megaPromoGateway()->_recordData($recordData, "tigo_megapromo", $tbl);

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
