<?php

$sql = "SELECT MSISDN,SUB_DATE,LAST_CHARGING from  soka_check_20221003 where is_processed = '1' ";

$conn = connect_db();

if ($conn) {

    $result = $conn->query($sql);
    $data = [];

    while ($row = $result->fetch_assoc()) {

        $data[] = $row;
    }

    foreach ($data as $key => $value) {

        $startDate = $data[$key]['SUB_DATE'];
        $lastDate = $data[$key]['LAST_CHARGING'];
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
        $renewSql = "";
        foreach ($dates as $table) {
            $txntable = "vas_transaction_trivia_" . date("Ymd", strtotime($table));
            $renewaltable = "tbl_payment_renewal_notifications_" . date("Ymd", strtotime($table));
            $sql .= "SELECT sum(amount)as 'AMOUNT' from $txntable where MSISDN = '{$msisdn}' UNION ALL ";
            $renewSql .= "SELECT count(*)*99 AS 'AMOUNT' from $renewaltable WHERE MSISDN = '{$msisdn}' and PAYMENT_STATUS = 'SUCCESS' UNION ALL ";
        }

        $sql .= "END";
        $sql = str_replace("UNION ALL END", "", $sql);

        $renewSql .= "END";
        $renewSql = str_replace("UNION ALL END", "", $renewSql);

        $sumResult = $conn->query($sql);
        $renewSumResult = $conn->query($renewSql);
        $sumData = [];
        $amount = 0;
        while ($row = $sumResult->fetch_assoc()) {

            $sumData[] = $row;
        }
        foreach ($sumData as $key => $value) {

            $amount += $sumData[$key]['AMOUNT'];
        }
        $renewsumData = [];
        $renewamount = 0;

        while ($row = $renewSumResult->fetch_assoc()) {

            $renewsumData[] = $row;
        }
        foreach ($renewsumData as $key => $value) {

            $renewamount += $renewsumData[$key]['AMOUNT'];
        }

        $totamount = $amount + $renewamount;
        
        
        $sql = "UPDATE soka_check_20221003 set INTERACTION_AMOUNT = $amount,RENEWAL_AMOUNT=$renewamount, TOTAL_AMOUNT = $totamount where MSISDN = '{$msisdn}'";
        echo $sql."\n";
        $conn->query($sql);
    }
}

function connect_db() {

    $conn = new mysqli("localhost", "trivia_consumer", "bJ++r5tL>xQdG:7H", "trivia_tigotz_vas");
    if ($conn) {
        return $conn;
    } else {
        return false;
    }
}

