<?php
date_default_timezone_set("Africa/Dar_Es_Salaam");
require_once 'SokaServices.php';


$sokaServicesObj = new SokaServices("consumer");
$tokenDetails = $sokaServicesObj->getTokenDetails();
$tomorrow  = date("Ymd",strtotime("+2 Days"));
$tokenExpiry = date("Ymd",strtotime($tokenDetails['EXPIRES_AT']));
if(true){
// if(strtotime($tomorrow)>= strtotime($tokenExpiry)){
    

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
$token = [];
$expiry = date("Y-m-d H:i:s",strtotime("+10 days"));
$token['ACCESS_TOKEN'] = $userAccessToken;
$token['EXPIRES_AT'] = $expiry;
$token['REFERESH_TOKEN'] = $refreshToken;
$token['CREATED_AT'] = date("Y-m-d H:i:s");
$sokaServicesObj->recordData("user_access_token",$token);

$renewalServicesObj = new SokaServices("renewal");

$table  = "tbl_renewal_access_token";
$renewalToken = [];
$renewalToken['TOKEN'] = $userAccessToken;
$renewalToken['TOKEN_EXPIRE_TIME'] = $expiry;
$renewalToken['RECORD_DATE'] = date("Y-m-d H:i:s");
$where  = "TOKEN_ID";
$value = '1';
$renewalServicesObj->_update($table, $where, $value, $renewalToken);
$conn = api_connect_db("localhost");
$mpromo  =  promo_connect("localhost");
$boli    =  boli_connect("localhost");
$supa    =  supa_connect("localhost");
$date    = date("Y-m-d H:i:s");
$sql     = "INSERT INTO user_access_token(`ACCESS_TOKEN`,`EXPIRES_AT`,`REFERESH_TOKEN`,`CREATED_AT`) VALUES('".$userAccessToken."','".$expiry."','".$refreshToken."','".$date."')";
$conn->query($sql);
$mpromo->query($sql);

$boli->query($sql);
$soma = soma_connect("localhost");
$soma->query($sql);
$supa->query($sql);
}
else{
   
    echo "Didnt Expire";
}




function api_connect_db($ip){

    $conn  = new mysqli($ip,"api_user",'bJ++r5tL>xQdG:7H','ishikistaa_tigo_vas');
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}

function soma_connect($ip){

    $conn  = new mysqli($ip,"api_user",'bJ++r5tL>xQdG:7H','soma_tigo_vas');
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}



function promo_connect($ip){

    $conn  = new mysqli($ip,"api_user",'bJ++r5tL>xQdG:7H','tigo_megapromo');
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}


function boli_connect($ip){

    $conn  = new mysqli($ip,"api_user",'bJ++r5tL>xQdG:7H','boli_tigo_vas');
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}

function supa_connect($ip){

    $conn  = new mysqli($ip,"api_user",'bJ++r5tL>xQdG:7H','supa_tigo_vas');
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}

