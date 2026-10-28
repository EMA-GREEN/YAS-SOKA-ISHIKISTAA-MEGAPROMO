<?php


$conn = connect_db();
$sql = "SELECT ID,MSISDN from tbl_subs_analysis order by id asc";
$result = $conn->query($sql);
$analysis = [];
while($row = $result->fetch_assoc()){

    $analysis[] = $row;

}

foreach($analysis as $index=>$value){
 $sumData = [];
    $amount = 0;
    $total =0;
    $interactions = 0;
    $lastCharging = '';
    $lastinteraction = '';
$msisdn = $analysis[$index]['MSISDN'];
$id =     $analysis[$index]['ID'];
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
        $sql .= "SELECT sum(amount)as 'AMOUNT',count(*) as  'TOTAL_BILLING',max(txn_date) as 'LAST_CHARGING' from $txntable where MSISDN = '{$msisdn}' UNION ALL ";

    }

    $sql .= "END";
    $sql = str_replace("UNION ALL END", "", $sql);
    $smssql = "";
    foreach($dates as $table){
        $smstable = "trivia_mo_".date("Ymd", strtotime($table));
        $smssql .= "SELECT count(*) as 'TOTAL_INTERACTIONS',max(DATE)  as 'LAST_INTERACTION' from  $smstable where MSISDN = '{$msisdn}' UNION ALL ";
        
    }
       $smssql .= "END";
    $smssql = str_replace("UNION ALL END", "", $smssql);
    
    
    $sumResult = $conn->query($sql);
//    $sumData = [];
  //  $amount = 0;
 //   $total =0;

    while($row = $sumResult->fetch_assoc()){

        $sumData[] = $row;

    }
    foreach($sumData as $key=>$value){
        if(!empty($sumData[$key]['LAST_CHARGING'])){
            
            $lastCharging = $sumData[$key]['LAST_CHARGING'];
        }
        $amount += $sumData[$key]['AMOUNT'];
        $total += $sumData[$key]['TOTAL_BILLING'];
        
    }

    $smsResult  = $conn->query($smssql);
    $smsData = [];
    while($row = $smsResult->fetch_assoc()){

        $smsData[] = $row;
        
    }
        $interactions = 0;
       foreach($smsData as $key=>$value){
        if(!empty($smsData[$key]['LAST_INTERACTION'])){
            $lastinteraction = $smsData[$key]['LAST_INTERACTION'];
        }   
        
        $interactions += $smsData[$key]['TOTAL_INTERACTIONS'];
    }
    
    
$sql = "update tbl_subs_analysis set TOTAL_AMOUNT = {$amount},SUCCESS_BILLINGS = {$total},TOTAL_INTERACTIONS = {$interactions} ,LAST_INTERACTION = '{$lastinteraction}',LAST_CHARGING = '{$lastCharging}' where ID = {$id}";

echo $sql."\n";
$conn->query($sql);
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

