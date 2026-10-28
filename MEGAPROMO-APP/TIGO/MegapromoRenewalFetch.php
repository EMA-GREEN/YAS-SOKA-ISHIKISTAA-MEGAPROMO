<?php


$conn = connect_db();

if($conn){

    
    $data = [];

 
        $dates = array();
        $current = strtotime("2022-09-01");
        $last = strtotime("2022-09-30");
        $step = "+1 day";
    while ($current <= $last) {
        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }
    $sql = "";
    foreach($dates as $table){
        $txntable = "tbl_payment_renewal_notifications_".date("Ymd", strtotime($table));
        $sql .= "SELECT sum(BILLED_AMOUNT) as 'AMOUNT',date(CREATED_AT) as 'TXN_DATE' from $txntable where PAYMENT_STATUS = 'SUCCESS' UNION ALL ";

    }

    $sql .= "END";
    $sql = str_replace("UNION ALL END", "", $sql);
    $sumResult = $conn->query($sql);
    $sumData = [];
    $amount = 0;
    $dailyAmount = 0;
    while($row = $sumResult->fetch_assoc()){
        $sumData[] = $row;

    }
    foreach($sumData as $key=>$value){
        $amount += $sumData[$key]['AMOUNT'];
        $dailyAmount = $sumData[$key]['AMOUNT'];
        $date = $sumData[$key]['TXN_DATE'];
        echo "Revenue ".$dailyAmount."|".$date."\n";
    }
    
        
    echo "Total Amount "."|".$amount;

    
}


function connect_db(){

$conn = new mysqli("localhost","trivia_publisher","bJ++r5tL>xQdG:7H","tigo_megapromo");
 if($conn){
     return $conn;
 }
    else{
        return false;
    }
}



