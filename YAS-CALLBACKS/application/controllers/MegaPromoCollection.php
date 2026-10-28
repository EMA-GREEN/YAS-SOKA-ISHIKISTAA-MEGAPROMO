<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');
error_reporting(0);

// require dirname (  __DIR__, 2).'/analog/lib/Analog.php';

class MegaPromoCollection extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('TigoMegaPromoServices', 'TigoMegaPromoServices');
    }

    public function tigopesa()
    { // this will be used for all type of notification
        // receive sms
        $requestData = file_get_contents("php://input");
        if (empty($requestData)) {
            echo "Empty Request";
            exit;
        }
        $requestFile = "TigoPesaMegapromo-" . date("YmdH") . ".txt";
        file_put_contents($requestFile, $requestData . " \n", FILE_APPEND);

        $xml = simplexml_load_string($requestData, "SimpleXMLElement", LIBXML_NOCDATA);

        $json = json_encode($xml);
        $xmlArray = json_decode($json, true);

        $msisdn = $xmlArray['MSISDN'];
        $txnID = $xmlArray['TXNID'];
        $amount = $xmlArray['AMOUNT'];
        $customerRefID = $xmlArray['CUSTOMERREFERENCEID'];

        if ($amount !== "300" && $amount !== "1800" && $amount !== "6000") {
            $credit = 0;
            $errorCode = "error012";
            $content = "INAVLID AMOUNT";
        } elseif ($amount > "1800") {
            $errorCode = "error014";
            $content = "Amount too high";
            $credit = 0;
        } elseif ($amount < "300") {
            $credit = 0;
            $errorCode = "error015";
            $content = "Amount Too low";
        } else {
            $errorCode = "error000";
            $content = "SUCCESS";

            if ($amount === "300") {
                $credit = 1;
            }
            if ($amount === "1800") {
                $credit = 7;
            }
            if ($amount === "6000") {
                $credit = 30;
            }
        }

        $mobile = $this->formatmobile($msisdn);
        $isExist = $this->TigoMegaPromoServices->checkSubscriber($moble);
        if (empty($isExist)) { // add to subscriberbase
            $subscriberInfo = [];
            $subscriberInfo['MSISDN'] = $mobile;
            $subscriberInfo['BUNDLE_COUNT'] = $credit;
            $subscriberInfo['_STATUS'] = "SUBSCRIBED";
            $subscriberInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");
            $subscriberInfo['SUBSCRIPTION_CHANNEL'] = "TIGOPESA";
            $subscriberInfo['BUNDLE_EXPIRY'] = date("Y-m-d H:i:s", strtotime("+12 months"));
            if ($credit >= 1) {
                $subscriberInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
            }
            $this->TigoMegaPromoServices->_recordData($subscriberInfo, "tigo_megapromo_test", "tbl_promo_subscribers");
        } else {
            if ($credit > 0) {
                $subscriberInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
                $bundleCount = $isExist['BUNDLE_COUNT'] + $credit;
                $subscriberInfo['BUNDLE_COUNT'] = $bundleCount;
                $subscriberInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                if ($isExist['ACTIVITY_STATUS'] === "INACTIVE") {
                    $subscriberInfo['ACTIVITY_STATUS'] = "ACTIVE";
                }
                $this->TigoMegaPromoServices->_update("tbl_promo_subscribers", "MSISDN", $mobile, $subscriberInfo);
            }
        }

        $response = '<?xml
version="1.0"?>
<COMMAND>
<TYPE>SYNC_BILLPAY_RESPONSE</TYPE>
<TXNID>"' . $txnID . '"</TXNID>
<REFID>"' . $customerRefID . '"</REFID>
<RESULT>TS</RESULT>
<ERRORCODE>".."</ERRORCODE>
<ERRORDESC/>
<MSISDN>"' . $xmlArray['MSISDN'] . '"</MSISDN>
<FLAG>Y</FLAG>
<CONTENT></CONTENT>
</COMMAND>';
        echo $response;
        if ($credit > 0) {
            $mobileMoney = [];
            $mobileMoney['MSISDN'] = $msisdn;
            $mobileMoney['TXN_ID'] = $txnID;
            $mobileMoney['CUSTOMER_REF_ID'] = $customerRefID;
            $mobileMoney['AMOUNT'] = $amount;
            $mobileMoney['OPERATOR'] = "TIGOPESA";
            $mobileMoney['RESPONSE'] = $response;
            $this->TigoMegaPromoServices->_recordData($mobileMoney, "tigo_megapromo_test", "tbl_promo_mobilemoney_transaction_logs");
        }
        return true;
    }

    public function tigopesapush()
    {
        $request = file_get_contents("php://input");
        if (empty($request)) {
            echo "Empty Request";
            return;
        }

        $requestFile = "/var/www/html/TIGO/logs/tigopesa/Callback-" . date("YmdH") . ".txt";
        $requestData = PHP_EOL . "[Callback Time : " . date('Y-m-d H:i:s') . ",RequestType : Callback, Callback Response : " . $request . "]" . PHP_EOL;
        file_put_contents($requestFile, $requestData, FILE_APPEND);

        $requestArray = json_decode($request, true);
        $refID = $requestArray['ReferenceID'];

        echo    '{
        "ResponseCode": "BILLER-30-0000-S",
        "ResponseStatus": true,
        "ResponseDescription": "Callback successful",
        "ReferenceID": "' . $refID . '"
        }';

        $pushUpdate = [];

        $isExist = $this->TigoMegaPromoServices->checkNotification($refID);

        if (!empty($isExist)) {
            return true;
        }

        if (!$requestArray['Status']) {
            $status = "FAILED";
            $pushUpdate['STATUS'] = 'FAILED';
            $pushUpdate['CALLBACK_RESPONSE'] = $requestArray['Description'];
        } else {
            $pushUpdate['STATUS'] = 'SUCCESS';
            $status = 'SUCCESS';
            $details = $this->TigoMegaPromoServices->gettigopesaPushDetails($refID);
            $subDetails = $this->TigoMegaPromoServices->checkSubscriber($details['MSISDN']);
            $subInfoResult = $this->TigoMegaPromoServices->subscriberInfo($details['MSISDN']);
            if (empty($subDetails)) {
                $subInfo = [];
                $subInfo['BUNDLE_COUNT'] = $details['TICKET_COUNT'];
                $subInfo['MSISDN'] = $details['MSISDN'];
                $subInfo['SUBSCRIPTION_CHANNEL'] = "TIGOPESAPUSH";
                $subInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
                $subInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $subInfo['BUNDLE_EXPIRY'] = date("Y-m-d H:i:s", strtotime("+12 months"));
                $subInfo['_STATUS'] = "BILLED";
                $subDetailsArray = [];
                $subDetailsArray['MSISDN'] = $details['MSISDN'];
                $subDetailsArray['TOTAL_PURCHASED'] = $details['TICKET_COUNT'];
                $this->TigoMegaPromoServices->_recordData($subInfo, "tigo_megapromo", "tbl_promo_subscribers");
                $this->TigoMegaPromoServices->_recordData($subDetails, "tigo_megapromo", "subscriber_details");
            } else {
                $subInfo = [];
                $subInfoUpdate = [];
                if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
                    $subInfo['ACTIVITY_STATUS'] = 'ACTIVE';
                    $subInfoUpdate['REACT_DATE'] = date("Y-m-d H:i:s");
                }

                $subInfo['BUNDLE_COUNT'] = $subDetails['BUNDLE_COUNT'] + $details['TICKET_COUNT'];
                $subInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
                $subInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $subInfo['BUNDLE_EXPIRY'] = date("Y-m-d H:i:s", strtotime("+12 months"));
                $subInfo['_STATUS'] = "BILLED";
                $this->TigoMegaPromoServices->_update("tbl_promo_subscribers", "MSISDN", $subDetails['MSISDN'], $subInfo);

                $subInfoUpdate['TOTAL_PURCHASED'] = $subInfoResult['TOTAL_PURCHASED'] + $details['TICKET_COUNT'];
                $this->TigoMegaPromoServices->_update("subscriber_details", "MSISDN", $subDetails['MSISDN'], $subInfoUpdate);

                $purchasesms = "Hongera! Umefanikiwa kununua " . $details['TICKET_COUNT'] . " ambazo unaweza kutumia kucheza. Kutumia KETE tuma T kwenda 15305. TESTI ZALI lako kwa kucheza uongeze nafasi ya kushinda.";
                $sms = urlencode($purchasesms);
                $msisdn = $subDetails['MSISDN'];

                $response = file_get_contents("http://192.168.168.3:19213/cgi-bin/sendsms?username=Green_mega&password=123456&to=$msisdn&text=$sms&from=15305&dlr-mask=0&priority=3");

                $smsArray = [];
                $smsArray['TEMPLATE_ID'] = 19;
                $smsArray['MSISDN'] = $msisdn;
                $smsArray['MO_ID'] = 0;
                $smsArray['SMS_TYPE'] = "TIGOPESA_SUCCESS_PURCHASE";
                $smsArray['RESPONSE_RECEIVED'] = $response;
                if ($response === "0: Accepted for delivery") {
                    $smsArray['STATUS_CODE'] = "SUCCESS";
                } else {
                    $smsArray['STATUS'] = "FAILED";
                }
                $smsArray['TXN_ID'] = str_replace("GTL", "", $refID);
                $this->TigoMegaPromoServices->_recordData($smsArray, "tigo_megapromo", "tbl_promo_mt_logs");
            }
        }

        $this->TigoMegaPromoServices->_update("tbl_promo_mobilemoney_push_transactions", "TXN_ID", $refID, $pushUpdate);

        $data = [];
        $data['response'] = $request;
        $data['REF_ID'] = $refID;
        $data['STATUS'] = $status;
        $this->TigoMegaPromoServices->_recordData($data, "tigo_megapromo", "tbl_promo_tigopesapush_callbacknotif");
        return true;
    }

    public function formatmobile($mobile)
    {
        if ($mobile[0] == "0") {
            $mobile = "255" . (int) $mobile;
        } else {
            $mobile = $mobile;
        }
        return $mobile;
    }
}
