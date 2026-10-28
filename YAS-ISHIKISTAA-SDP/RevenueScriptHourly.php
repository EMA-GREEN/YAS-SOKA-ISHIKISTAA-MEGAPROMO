<?php

date_default_timezone_set('Africa/Dar_ES_Salaam');

require_once 'MailerScript.php';



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


processMail($message);


function processMail($message){
    $subject = "Tigo Ishikistaa Trivia- " . date("Y-m-d");
        $to = [];
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']          =  "Emanuel Julius";
$to[1]['Mail']          = 'reports@greentelecom.co.tz';
$to[1]['Name']          = 'Reports';
$cc [0]['Mail']         = 'emanuel@greentelecom.co.tz';
$cc [0]['Name']         = 'Emanuel Julius';
$cc [1]['Mail']         = 'richard@greentelecom.co.tz';
$cc [1]['Name']         = 'Richard Matemba Joseph';
$cc [2]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc [2]['Name']         = 'Hemedi Ali Hemedi';

    sendEmail($message, $subject,$to,$cc);

}
function connect_db() {

    $conn = new mysqli("localhost", "analysis_email", "xc28FhrWV7wtXVeg@@@", "ishikistaa_tigo_vas");
    if ($conn) {

        return $conn;
    } else {

        return FALSE;
    }
}


