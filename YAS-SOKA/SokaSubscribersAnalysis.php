<?php


$conn = connect_db();
$sql = "SELECT MSISDN from tbl_subs_analysis where SUCCESS_BILLINGS >= 1 order by LAST_CHARGING desc";
$result = $conn->query($sql);
$analysis = [];
while($row = $result->fetch_assoc()){

    $analysis[] = $row;

}

foreach($analysis as $index=>$value){
$msisdn = $analysis[$index]['MSISDN'];
$dates = array();
    $current = strtotime(date("2022-11-01"));
    $last = strtotime(date("2022-11-30"));
    $step = "+1 day";
    
    while ($current <= $last) {
        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }
    $sql = "";
    foreach($dates as $table){
        $txntable = "vas_transaction_trivia_".date("Ymd", strtotime($table));
        $sql .= "SELECT sum(amount)as 'AMOUNT',count(*) as  'TOTAL_BILLING' from $txntable where MSISDN = '{$msisdn}' UNION ALL ";

    }

    $sql .= "END";
    $sql = str_replace("UNION ALL END", "", $sql);


    $sumResult = $conn->query($sql);
    $sumData = [];
    $amount = 0;
    $total =0;
    while($row = $sumResult->fetch_assoc()){

        $sumData[] = $row;

    }
    foreach($sumData as $key=>$value){

        $amount += $sumData[$key]['AMOUNT'];
        $total += $sumData[$key]['TOTAL_BILLING'];
    }

$sql = "update tbl_subs_analysis set TOTAL_AMOUNT = {$amount},SUCCESS_BILLINGS = {$total} where MSISDN = {$msisdn}";
$conn->query($sql);
}

function connect_db(){

$conn = new mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
 if($conn){
     return $conn;
 }
    else{
        return false;
    }
}

