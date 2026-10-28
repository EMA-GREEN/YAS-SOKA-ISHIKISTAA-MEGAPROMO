<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$url = 'http://10.222.10.232:8086/api/public/ACT/Activate';
$userAccessToken = 'eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJHcmVlblRlbGVjb20iLCJhdWQiOiJBQ0NFU1MiLCJzY29wZXMiOiJBRE1JTiIsImlzcyI6Imh0dHA6Ly9zaXhkZWUuY29tIiwiaWF0IjoxNTkzNjMzNjIyLCJleHAiOjE1OTM2NDI2MjJ9.ysXUrkSSwqF7s-hX1sVJdvHdf2ICdcvsefFjoHu-AlfXDFrrmpP88A7_aAB2-ChAmq5mv-kBCps3pqrUmnuKIw';
$request = [];
$cpID = 86;
$password = "Tigo@1234";
$date = date("YmdHis");
$encrypted = md5($cpID.$password.$date);

$activeRequest = '{
"requestId":"W17",
"requestTimeStamp":"'.$date.'",
"channel":"8",
"sourceAddress":"188.64.188.49",
"operation":"ACTIVATE",
"username":"GreenTelecom",
"password":"'.$encrypted.'",
"requestParam":
{
"data":[{
"name":"OfferCode",
"value":"017008600009"
},
{
"name":"Msisdn",
"value":"255659256870"
},
{
"name":"ShortCode",
"value":"15633"
},
{
"name":"CpId",
"value":"86"
}
]}}
';


echo $activeRequest;


     $headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($activeRequest),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$userAccessToken);


         $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $activeRequest);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            echo  "Response : ". $response;
            
            
            
            /*http://10.222.10.232:8086/api/public/INT/InteractiveRequest
http://10.222.10.232:8086/api/public/SMS/SendSMS
http://10.222.10.232:8086/api/public/PAY/Payment
http://10.222.10.232:8086/api/public/DCT/DeActivate
http://10.222.10.232:8086/api/public/ACT/Activate
http://10.222.10.232:8086/api/public/Activation/Web
*/
