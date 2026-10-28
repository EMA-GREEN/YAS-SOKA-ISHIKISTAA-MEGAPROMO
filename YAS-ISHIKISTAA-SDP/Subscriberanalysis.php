<?php


$sql = "SELECT MSISDN,SUB_DATE,LAST_CHARGING from  ishikistaa_check_20221003 where  LAST_CHARGING <> '0000-00-00 00:00:00'";


$conn = connect_db();

if($conn){

    $result = $conn->query($sql);
    $data = [];

    while($row = $result->fetch_assoc()){

        $data[] = $row;
    }

    foreach($data as $key=>$value){

        $startDate = $data[$key]['SUB_DATE'];
        $lastDate  = $data[$key]['LAST_CHARGING'];
        $msisdn = $data[$key]['MSISDN'];



    $dates = array();
    $current = strtotime("2022-07-01");
    $last = strtotime(date("Y-m-d"));
    $step = "+1 day";
    while ($current <= $last) {

        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }
    $sql = "";
    foreach($dates as $table){
        $txntable = "vas_transaction_trivia_".date("Ymd", strtotime($table));
        $sql .= "SELECT sum(amount)as 'AMOUNT' from $txntable where MSISDN = '{$msisdn}' UNION ALL ";
        
    }
    echo $sql;
    $sql .= "END";
    $sql = str_replace("UNION ALL END", "", $sql);
    echo $sql;
    $sumResult = $conn->query($sql);
    $sumData = [];
    $amount = 0;
    while($row = $sumResult->fetch_assoc()){

        $sumData[] = $row;
        
    }
    foreach($sumData as $key=>$value){
        
        $amount += $sumData[$key]['AMOUNT'];
    }

    $sql = "UPDATE ishikistaa_check_20221003 set INTERACTION_AMOUNT = $amount where MSISDN = '{$msisdn}'";
    echo $sql;
    $conn->query($sql);

    }
}


function connect_db(){

$conn = new mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","ishikistaa_tigo_vas");
 if($conn){
     return $conn;
 }
    else{
        return false;
    }
}


