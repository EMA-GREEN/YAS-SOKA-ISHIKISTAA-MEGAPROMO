<?php
setlocale(LC_ALL, 'fr_FR');
error_reporting(E_ALL);
set_time_limit(60 * 60);
date_default_timezone_set('Africa/Dar_Es_Salaam');

file_put_contents("incoming_mos.txt", json_encode($_GET), FILE_APPEND);

$msisdn = $_GET['sender'];
$msisdn = str_replace("+", "", $msisdn);
$message = $_GET['message'];
$keyword = $_GET['keyword'];
$transactionID = $_GET['id'];

$rowtext = $keyword . " " . $message;
$text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', utf8_encode($rowtext));
$shortCode = $_GET['shortcode'];

if (empty($msisdn)) {
    exit;
}

if ($shortCode === "15305") {
    $conn = connect_db("192.168.168.4", "tigo_megapromo");
    if (!$conn) {
        file_put_contents("Failed.txt", json_encode($_GET), FILE_APPEND);
        exit;
    }
    $sql = "SELECT * FROM tbl_tigo_promo_mo_logs WHERE TRANSACTION_UUID = '{$transactionID}'";
    $result = $conn->query($sql);
    $resultArray = $result->fetch_array();

    if (empty($resultArray)) {
        $sql = "INSERT INTO tbl_tigo_promo_mo_logs (`MSISDN`, `MESSAGE`, `TRANSACTION_UUID`) VALUES ('{$msisdn}', '{$text}', '{$transactionID}')";
        $conn->query($sql);
    }
    $conn->close();
    exit;
} else if ($shortCode === "15590") {
    $conn = connect_tbl_db();
    
    if (!$conn) {
        file_put_contents("Failed.txt", json_encode($_GET), FILE_APPEND);
        exit;
    }

    $operator = "TIGO";
    $sql = "SELECT * FROM tbl_mo_logs 
            WHERE TRANSACTION_UUID = '{$transactionID}' 
            AND OPERATOR = '{$operator}'";

    $result = $conn->query($sql);
    $resultArray = $result->fetch_array();
    
    if (empty($resultArray)) {
        $sql = "INSERT INTO tbl_mo_logs (`MSISDN`, `OPERATOR`, `MO_TEXT`, `KEYWORD`, `MESSAGE`, `SHORTCODE`, `TRANSACTION_UUID`) 
                VALUES ('{$msisdn}', '{$operator}', '{$text}', '{$keyword}', '{$message}', '{$shortCode}', '{$transactionID}')";
        $conn->query($sql);
    }
    $conn->close();
    exit;
} else {
    exit;
}

function connect_db($host, $db) {
    $conn = new Mysqli($host, "tigo_message", "7Hrmz85NuW6x8Jj5@", $db);
    return $conn ? $conn : FALSE;
}

function connect_tbl_db() {
    $conn = new Mysqli("102.218.75.4", "tbltigomoreceiver", "tB1m0r3ce@iv3r6YkrSdB", "tblmoreceiver");
    return $conn ? $conn : FALSE;
}
