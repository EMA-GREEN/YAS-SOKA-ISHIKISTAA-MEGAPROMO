<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
$renewal = '{
  "password" : "c4f60a4a142940f3c038bc06968ed087",
  "sourceAddress": "188.64.188.49",
  "requestId": "R20200626071828255659256870",
  "requestTimeStamp": "20200626191828",
  "channel": "8",
  "requestParam": {
    "data": [
      {
        "name": "OfferCode",
        "value": "017008600007"
      },
      {
        "name": "Msisdn",
        "value": "255659256870"
      },
      {
        "name": "Language",
        "value": "1"
      },
      {
        "name": "ChargeAmount",
        "value": "10"
      },
      {
        "name": "CpId",
        "value": "86"
      }
    ]
  },
  "operation": "Payment",
  "username": "GreenTelecom"
}';

echo $renewal;

$headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($renewal),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJHcmVlblRlbGVjb20iLCJhdWQiOiJBQ0NFU1MiLCJzY29wZXMiOiJBRE1JTiIsImlzcyI6Imh0dHA6Ly9zaXhkZWUuY29tIiwiaWF0IjoxNTkzMTk4MDM0LCJleHAiOjE1OTMyMDcwMzR9.GUyPMAHEPMJbxvk7xOyp7OOtzG0mDagNXGDsYJCUXcOt_orqp6mqfSydfri3ciYH1cSvhxiRZi_qgwfw9P9FiQ");

$url = "http://10.222.10.232:8086/api/public/PAY/Payment";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $renewal);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
           echo $response;
