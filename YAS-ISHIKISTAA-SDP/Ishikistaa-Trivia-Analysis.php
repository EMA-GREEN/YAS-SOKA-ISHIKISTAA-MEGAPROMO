<?php

date_default_timezone_set('Africa/Dar_ES_Salaam');
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


$curdate = date("Y-m-d");
$revenueTable = 'vas_transaction_trivia_' . date("Ymd");


$tigoishikistaaconn = connect_db();

$tigoishikistaainteractionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers  where date(ANSWER_DATE) = '{$curdate}' AND BILLING_STATUS = 'CHARGING_SUCCESS'";
$tigoishikistaainteractionResultArray = $tigoishikistaaconn->query($tigoishikistaainteractionsQuery);
$tigoishikistaainteractionResult = $tigoishikistaainteractionResultArray->fetch_assoc();

$tigoishikistaatotalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$tigoishikistaatotalSubscribersResultArray = $tigoishikistaaconn->query($tigoishikistaatotalSubscribersQuery);
$tigoishikistaatotalSubscribersResult = $tigoishikistaatotalSubscribersResultArray->fetch_assoc();

$tigoishikistaasubscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where date(record_date) = '{$curdate}' AND ACTIVITY_STATUS = 'ACTIVE'";
$tigoishikistaasubscribersGainedResultArray = $tigoishikistaaconn->query($tigoishikistaasubscribersGainedQuery);
$tigoishikistaasubscribersGainedResult = $tigoishikistaasubscribersGainedResultArray->fetch_assoc();

$tigoishikistaaunsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$tigoishikistaatotalunSubscribersResultArray = $tigoishikistaaconn->query($tigoishikistaaunsubscribersQuery);
$tigoishikistaatotalunSubscribersResult = $tigoishikistaatotalunSubscribersResultArray->fetch_assoc();

$tigoishikistaatodayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where date(CREATED_AT) = '{$curdate}'";
$tigoishikistaatodayUnsubResultArray = $tigoishikistaaconn->query($tigoishikistaatodayUnsubQuery);
$tigoishikistaatodayUnsubResult = $tigoishikistaatodayUnsubResultArray->fetch_assoc();

$tigoishikistaatotalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
$tigoishikistaatotalRevenueResultArray = $tigoishikistaaconn->query($tigoishikistaatotalRevenueQuery);
$tigoishikistaatotalRevenueResult = $tigoishikistaatotalRevenueResultArray->fetch_assoc();










$message = "<b><u>TIGO-ISHIKISTAA</u></b>";
$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For ' . $curdate . '</th><th>Interactions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>" . $tigoishikistaatotalSubscribersResult['SUBSCRIBERS'] . "</td><td>" . $tigoishikistaatotalunSubscribersResult['UNSUBS'] . "</td><td>" . $tigoishikistaasubscribersGainedResult['SUBSCRIBERS'] . "</td><td>" . $tigoishikistaatodayUnsubResult['UNSUBS'] . "</td><td>" . $tigoishikistaainteractionResult['INTERACTIONS'] . "</td><td>" . $tigoishikistaatotalRevenueResult['REVENUE'] . "</td></tr></table>";
$message .= "<br/> <br/>";

$to = "Rahul Sahni <rahul@greentelecom.co.tz>";
$header = "From:Green Telecom Reporter <noreply@greentelecom.co.tz> \r\n";
$header .= "Cc:Hemedi <hemedi@greentelecom.co.tz> \r\n";
$header .= "Cc:Koushik Govind Raju <koushik@greentelecom.co.tz> \r\n";
$header .= "MIME-Version: 1.0\r\n";
$header .= "Content-type: text/html\r\n";
$subject = "Tigo Ishikistaa Trivia- " . date("Y-m-d");
$retval = mail ($to,$subject,$message,$header);
function connect_db() {

    $conn = new mysqli("localhost", "analysis_email", "xc28FhrWV7wtXVeg@@@", "ishikistaa_tigo_vas");
    if ($conn) {

        return $conn;
    } else {

        return FALSE;
    }
}

