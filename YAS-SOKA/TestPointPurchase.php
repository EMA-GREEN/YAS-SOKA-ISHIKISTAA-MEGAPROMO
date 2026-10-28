<?php

require_once "config.php";

$sms = "soka";

if(similiarPointPurchaseKeyword($sms)){
	echo "Okay";
}

function isPointPurchaseKeyword($input, $point_purchase_map) {
    $input = strtoupper(trim($input));
    return array_key_exists($input, $point_purchase_map);
}

function getPointPurchaseAmount($input, $point_purchase_map) {
    $input = strtoupper(trim($input));
    if (array_key_exists($input, $point_purchase_map)) {
        return $point_purchase_map[$input];
    } else {
        return 0;
    }
}

function getPointPurchaseKeyword($amount, $point_purchase_map) {
    $key = array_search($amount, $point_purchase_map);
    return ($key !== false) ? $key : "";
}

function similiarPointPurchaseKeyword($input) {
    $stringValue = strval($input);
    return (bool) preg_match('/^[sS]\d+/', $stringValue);
}