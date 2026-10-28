<?php
require_once 'IshikistaaServices.php';
$tokenUrl = 'http://10.222.10.232:8086/api/auth/login';
$tokenRequest = '{
"username": "GreenTelecom",
"password": "Tigo@1234"
}';

    $headers = array(
            "Method:POST",
            "Content-Type: application/json",
            "Content-length: " . strlen($tokenRequest),
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "X-Requested-With: XMLHttpRequest"
        );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenRequest);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $tokenResponse = curl_exec($ch);


$tokenResponseArray = json_decode($tokenResponse,True);
$userAccessToken = $tokenResponseArray['token'];
$refreshToken    = $tokenResponseArray['refreshToken'];
$ishikistaaServicesObj = new IshikistaaServices("consumer");
$token = [];
$token['ACCESS_TOKEN'] = $userAccessToken;
$token['EXPIRES_AT'] = date("Y-m-d H:i:s",strtotime("+90 minutes"));
$token['REFERESH_TOKEN'] = $refreshToken;
$token['CREATED_AT'] = date("Y-m-d H:i:s");
$ishikistaaServicesObj->recordData("user_access_token",$token);
