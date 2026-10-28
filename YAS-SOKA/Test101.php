<?php

$url = "https://beep.oya.co.tz/green-1.0/SMSService/gr33nte1c0m/sms/m2m/ap1";

$headers = array("Method:POST","Content-Type: application/json", "Accept: application/json","Authorization: Basic b3lhOjB5QDIwMjA=");  

$smspayload = '{
  "messages": [
    {
      "from": "OYA",
      "to": "255656692469",
      "text": "SMS Test - 001"
    }
  ]
}';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,$url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $smspayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);

curl_close($ch);

echo $response."\n";