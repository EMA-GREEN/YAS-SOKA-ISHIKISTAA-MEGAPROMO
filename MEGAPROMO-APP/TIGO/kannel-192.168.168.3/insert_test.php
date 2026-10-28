<?php

$conn = connect_mystrybx_db();
if ($conn) {
    $msisdn = "255656692469";
    $text = "Open box1";
    $transactionID = date("YmdHis");
    $sql = "INSERT INTO sms_notifications (`msisdn`, `message`, `msg_id`) VALUES ('{$msisdn}', '{$text}', '{$transactionID}')";
    $conn->query($sql);
    $conn->close();
}

function connect_mystrybx_db() {
    $conn = new Mysqli("192.168.168.10", "yasmysteryboxuser", "mYst3Ryb0xusry@stz25", "mysterybox");
    return $conn ? $conn : FALSE;
}