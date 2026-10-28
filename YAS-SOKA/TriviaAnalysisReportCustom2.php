<?php
date_default_timezone_set('Africa/Dar_ES_Salaam');


require_once '../PHPMailer/MailerScript.php';

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$conn = connect_tigo_trivia();



$days = "-46";
$curdate = date("Y-m-d",strtotime($days." Days"));

$startDateTime = $curdate . " 00:00:00";
$endDateTime = date("Y-m-d", strtotime($curdate . " +1 day")) . " 00:00:00";

$revenueTable  = 'vas_transaction_trivia_'.date("Ymd",strtotime($days." Days"));
$interactionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers   where ANSWER_DATE >= '{$startDateTime}' AND ANSWER_DATE < '{$endDateTime}'";


$interactionResultArray = $conn->query($interactionsQuery);
$interactionResult = $interactionResultArray->fetch_assoc();


$totalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$totalSubscribersResultArray = $conn->query($totalSubscribersQuery);
$totalSubscribersResult = $totalSubscribersResultArray->fetch_assoc();



$subscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}' ";
$subscribersGainedResultArray = $conn->query($subscribersGainedQuery);
$subscribersGainedResult = $subscribersGainedResultArray->fetch_assoc();

$unsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$totalunSubscribersResultArray = $conn->query($unsubscribersQuery);
$totalunSubscribersResult = $totalunSubscribersResultArray->fetch_assoc();

$todayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where CREATED_AT >= '{$startDateTime}' AND CREATED_AT < '{$endDateTime}'";
$todayUnsubResultArray = $conn->query($todayUnsubQuery);
$todayUnsubResult = $todayUnsubResultArray->fetch_assoc();

$totalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
$totalRevenueResultArray = $conn->query($totalRevenueQuery);
$totalRevenueResult = $totalRevenueResultArray->fetch_assoc();
// $totalRevenueResult['REVENUE'] = "TOB";

$curdate = date("Y-m-d",strtotime($days." Days"));







$vodacomIshikistaaconn = connect("102.218.75.3", "trivia_vodacom_vas");



$halotelSoka  = connect("102.218.75.3", "trivia_halotel_vas");

$halotelIshikistaaconn = connect("102.218.75.3", "ishikistaa_halotel_vas");


//halotel soka trivia

$revenueTable  = 'vas_transaction_trivia_'.date("Ymd",strtotime($days." Days"));
$halotelconn  = connect("102.218.75.3", "trivia_halotel_vas");
$halotelinteractionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers  where ANSWER_DATE >= '{$startDateTime}' AND ANSWER_DATE < '{$endDateTime}' AND BILLING_STATUS = 'CHARGING_SUCCESS'";
$halotelinteractionResultArray = $halotelconn->query($halotelinteractionsQuery);
$halotelinteractionResult = $halotelinteractionResultArray->fetch_assoc();



$haloteltotalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$haloteltotalSubscribersResultArray = $halotelconn->query($haloteltotalSubscribersQuery);
$haloteltotalSubscribersResult = $haloteltotalSubscribersResultArray->fetch_assoc();

$halotelsubscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}'";
$halotelsubscribersGainedResultArray = $halotelconn->query($halotelsubscribersGainedQuery);
$halotelsubscribersGainedResult = $halotelsubscribersGainedResultArray->fetch_assoc();

$halotelunsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$haloteltotalunSubscribersResultArray = $halotelconn->query($halotelunsubscribersQuery);
$haloteltotalunSubscribersResult = $haloteltotalunSubscribersResultArray->fetch_assoc();

$haloteltodayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where CREATED_AT >= '{$startDateTime}' AND CREATED_AT < '{$endDateTime}'";
$haloteltodayUnsubResultArray = $halotelconn->query($haloteltodayUnsubQuery);
$haloteltodayUnsubResult = $haloteltodayUnsubResultArray->fetch_assoc();

$haloteltotalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
$haloteltotalRevenueResultArray = $halotelconn->query($haloteltotalRevenueQuery);
$haloteltotalRevenueResult = $haloteltotalRevenueResultArray->fetch_assoc();





//halotel ishikistaa trivia

$halotelIshikistaainteractionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers  where ANSWER_DATE >= '{$startDateTime}' AND ANSWER_DATE < '{$endDateTime}' AND BILLING_STATUS = 'CHARGING_SUCCESS'";

$halotelIshikistaainteractionResultArray = $halotelIshikistaaconn->query($halotelIshikistaainteractionsQuery);
$halotelIshikistaainteractionResult = $halotelIshikistaainteractionResultArray->fetch_assoc();

$halotelIshikistaatotalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$halotelIshikistaatotalSubscribersResultArray = $halotelIshikistaaconn->query($halotelIshikistaatotalSubscribersQuery);
$halotelIshikistaatotalSubscribersResult = $halotelIshikistaatotalSubscribersResultArray->fetch_assoc();



$halotelIshikistaasubscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}'";
$halotelIshikistaasubscribersGainedResultArray = $halotelIshikistaaconn->query($halotelIshikistaasubscribersGainedQuery);
$halotelIshikistaasubscribersGainedResult = $halotelIshikistaasubscribersGainedResultArray->fetch_assoc();

$halotelIshikistaaunsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$halotelIshikistaatotalunSubscribersResultArray = $halotelIshikistaaconn->query($halotelIshikistaaunsubscribersQuery);
$halotelIshikistaatotalunSubscribersResult = $halotelIshikistaatotalunSubscribersResultArray->fetch_assoc();

$halotelIshikistaatodayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where CREATED_AT >= '{$startDateTime}' AND CREATED_AT < '{$endDateTime}'";
$halotelIshikistaatodayUnsubResultArray = $halotelIshikistaaconn->query($halotelIshikistaatodayUnsubQuery);
$halotelIshikistaatodayUnsubResult = $halotelIshikistaatodayUnsubResultArray->fetch_assoc();

$halotelIshikistaatotalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
$halotelIshikistaatotalRevenueResultArray = $halotelIshikistaaconn->query($halotelIshikistaatotalRevenueQuery);
$halotelIshikistaatotalRevenueResult = $halotelIshikistaatotalRevenueResultArray->fetch_assoc();




$vodacomIshikistaainteractionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers  where ANSWER_DATE >= '{$startDateTime}' AND ANSWER_DATE < '{$endDateTime}' AND BILLING_STATUS = 'CHARGING_SUCCESS'";

$vodacomIshikistaainteractionResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaainteractionsQuery);
$vodacomIshikistaainteractionResult = $vodacomIshikistaainteractionResultArray->fetch_assoc();

$vodacomIshikistaatotalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$vodacomIshikistaatotalSubscribersResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaatotalSubscribersQuery);
$vodacomIshikistaatotalSubscribersResult = $vodacomIshikistaatotalSubscribersResultArray->fetch_assoc();



$vodacomIshikistaasubscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}'";
$vodacomIshikistaasubscribersGainedResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaasubscribersGainedQuery);
$vodacomIshikistaasubscribersGainedResult = $vodacomIshikistaasubscribersGainedResultArray->fetch_assoc();

$vodacomIshikistaaunsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$vodacomIshikistaatotalunSubscribersResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaaunsubscribersQuery);
$vodacomIshikistaatotalunSubscribersResult = $vodacomIshikistaatotalunSubscribersResultArray->fetch_assoc();

$vodacomIshikistaatodayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where CREATED_AT >= '{$startDateTime}' AND CREATED_AT < '{$endDateTime}'";
$vodacomIshikistaatodayUnsubResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaatodayUnsubQuery);
$vodacomIshikistaatodayUnsubResult = $vodacomIshikistaatodayUnsubResultArray->fetch_assoc();

$vodacomIshikistaatotalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
// $vodacomIshikistaatotalRevenueResultArray = $vodacomIshikistaaconn->query($vodacomIshikistaatotalRevenueQuery);
// $vodacomIshikistaatotalRevenueResult = $vodacomIshikistaatotalRevenueResultArray->fetch_assoc();
$vodacomIshikistaatotalRevenueResult['REVENUE'] = "TOB";








$tigoishikistaaconn = connect_tigoishikistaa_trivia();

$tigoishikistaainteractionsQuery = "SELECT count(*) AS 'INTERACTIONS',count(distinct MSISDN) AS 'UNIQUE_PLAYERS' from tbl_qn_answers  where ANSWER_DATE >= '{$startDateTime}' AND ANSWER_DATE < '{$endDateTime}' AND BILLING_STATUS = 'CHARGING_SUCCESS'";
$tigoishikistaainteractionResultArray = $tigoishikistaaconn->query($tigoishikistaainteractionsQuery);
$tigoishikistaainteractionResult = $tigoishikistaainteractionResultArray->fetch_assoc();

$tigoishikistaatotalSubscribersQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers WHERE ACTIVITY_STATUS = 'ACTIVE'";
$tigoishikistaatotalSubscribersResultArray = $tigoishikistaaconn->query($tigoishikistaatotalSubscribersQuery);
$tigoishikistaatotalSubscribersResult = $tigoishikistaatotalSubscribersResultArray->fetch_assoc();

$tigoishikistaasubscribersGainedQuery = "SELECT count(*) AS 'SUBSCRIBERS' from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}' AND ACTIVITY_STATUS = 'ACTIVE'";
$tigoishikistaasubscribersGainedResultArray = $tigoishikistaaconn->query($tigoishikistaasubscribersGainedQuery);
$tigoishikistaasubscribersGainedResult = $tigoishikistaasubscribersGainedResultArray->fetch_assoc();

$tigoishikistaaunsubscribersQuery = "SELECT count(*) AS 'UNSUBS' from tbl_qn_subscribers where ACTIVITY_STATUS = 'INACTIVE'";
$tigoishikistaatotalunSubscribersResultArray = $tigoishikistaaconn->query($tigoishikistaaunsubscribersQuery);
$tigoishikistaatotalunSubscribersResult = $tigoishikistaatotalunSubscribersResultArray->fetch_assoc();

$tigoishikistaatodayUnsubQuery = "SELECT count(*) AS 'UNSUBS' from tbl_unsubs where CREATED_AT >= '{$startDateTime}' AND CREATED_AT < '{$endDateTime}'";
$tigoishikistaatodayUnsubResultArray = $tigoishikistaaconn->query($tigoishikistaatodayUnsubQuery);
$tigoishikistaatodayUnsubResult = $tigoishikistaatodayUnsubResultArray->fetch_assoc();

$tigoishikistaatotalRevenueQuery = "SELECT count(*),SUM(AMOUNT)AS 'REVENUE' from {$revenueTable} WHERE TXN_STATUS like '%SUCCESS%' ";
$tigoishikistaatotalRevenueResultArray = $tigoishikistaaconn->query($tigoishikistaatotalRevenueQuery);
$tigoishikistaatotalRevenueResult = $tigoishikistaatotalRevenueResultArray->fetch_assoc();

//zantel soka


$zantelsokaConn = connect("102.218.75.3", "vas_zantel_soka");


$zantelsokaSubScriberQuery = "SELECT count(*) AS 'SUB_TOTAL'  from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}' ";
$zantelsokaSubscriberArray = $zantelsokaConn->query($zantelsokaSubScriberQuery);
$zantelsokaSubscriberResult = $zantelsokaSubscriberArray->fetch_assoc();

$zantelsokaUnSubScriberQuery = "SELECT count(*) AS 'UNSUB_TOTAL'  from tbl_audit_trail where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}'";
$zantelsokaUnSubscriberArray = $zantelsokaConn->query($zantelsokaUnSubScriberQuery);
$zantelsokaUnSubscriberResult = $zantelsokaUnSubscriberArray->fetch_assoc();

$zantelsokaRevenueQuery = "SELECT sum(amount) AS 'REVENUE' FROM  zantel_response  where _DATE >= '{$startDateTime}' AND _DATE < '{$endDateTime}' AND SERVICE_ID = 'soka' and status_code = 'SUCCESS'  ";
$zantelsokaRevenueArray = $zantelsokaConn->query($zantelsokaRevenueQuery);
$zantelsokaRevenueResult = $zantelsokaRevenueArray->fetch_assoc();
$zantelsokaConn->close();



//zantel soma

$zantelsomaConn = connect("102.218.75.3", "soma_zantel_vas");



$zantelsomaSubScriberQuery = "SELECT count(*) AS 'SUB_TOTAL'  from tbl_qn_subscribers where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}' ";
$zantelsomaSubscriberArray = $zantelsomaConn->query($zantelsomaSubScriberQuery);
$zantelsomaSubscriberResult = $zantelsomaSubscriberArray->fetch_assoc();

$zantelsomaUnSubScriberQuery = "SELECT count(*) AS 'UNSUB_TOTAL'  from tbl_soma_audit_trail where record_date >= '{$startDateTime}' AND record_date < '{$endDateTime}'";
$zantelsomaUnSubscriberArray = $zantelsomaConn->query($zantelsomaUnSubScriberQuery);
$zantelsomaUnSubscriberResult = $zantelsomaUnSubscriberArray->fetch_assoc();

$zantelsomaRevenueQuery = "SELECT sum(amount) AS 'REVENUE' FROM  zantel_response  where _DATE >= '{$startDateTime}' AND _DATE < '{$endDateTime}' and status_code = 'SUCCESS'  ";
$zantelsomaRevenueArray = $zantelsomaConn->query($zantelsomaRevenueQuery);
$zantelsomaRevenueResult = $zantelsomaRevenueArray->fetch_assoc();



$message = "<b><u>TIGO-SOKA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For '.$curdate.'</th><th>Interactions</th><th>Unique Players</th><th>Revenue Generated</th></tr>';
$message .="<tr><td>".$totalSubscribersResult['SUBSCRIBERS']."</td><td>".$totalunSubscribersResult['UNSUBS']."</td><td>".$subscribersGainedResult['SUBSCRIBERS']."</td><td>".$todayUnsubResult['UNSUBS']."</td><td>".$interactionResult['INTERACTIONS']."</td><td>".$interactionResult['UNIQUE_PLAYERS']."</td><td>".$totalRevenueResult['REVENUE']."</td></tr></table>";
$message .="<br/> <br/>";



$message .= "<b><u>HALOTEL-SOKA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For '.$curdate.'</th><th>Interactions</th><th>Unique Players</th><th>Revenue Generated</th></tr>';
$message .="<tr><td>".$haloteltotalSubscribersResult['SUBSCRIBERS']."</td><td>".$haloteltotalunSubscribersResult['UNSUBS']."</td><td>".$halotelsubscribersGainedResult['SUBSCRIBERS']."</td><td>".$haloteltodayUnsubResult['UNSUBS']."</td><td>".$halotelinteractionResult['INTERACTIONS']."</td><td>".$halotelinteractionResult['UNIQUE_PLAYERS']."</td><td>".$haloteltotalRevenueResult['REVENUE']."</td></tr></table>";
$message .="<br/> <br/>";

$message .= "<b><u>HALOTEL-ISHIKISTAA-TRIVIA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For '.$curdate.'</th><th>Interactions</th><th>Unique Players</th><th>Revenue Generated</th></tr>';
$message .="<tr><td>".$halotelIshikistaatotalSubscribersResult['SUBSCRIBERS']."</td><td>".$halotelIshikistaatotalunSubscribersResult['UNSUBS']."</td><td>".$halotelIshikistaasubscribersGainedResult['SUBSCRIBERS']."</td><td>".$halotelIshikistaatodayUnsubResult['UNSUBS']."</td><td>".$halotelIshikistaainteractionResult['INTERACTIONS']."</td><td>".$halotelIshikistaainteractionResult['UNIQUE_PLAYERS']."</td><td>".$halotelIshikistaatotalRevenueResult['REVENUE']."</td></tr></table>";
$message .="<br/> <br/>";



$message .= "<b><u>VODACOM-CHEMESHABONGO-TRIVIA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For '.$curdate.'</th><th>Interactions</th><th>Unique Players</th><th>Revenue Generated</th></tr>';
$message .="<tr><td>".$vodacomIshikistaatotalSubscribersResult['SUBSCRIBERS']."</td><td>".$vodacomIshikistaatotalunSubscribersResult['UNSUBS']."</td><td>".$vodacomIshikistaasubscribersGainedResult['SUBSCRIBERS']."</td><td>".$vodacomIshikistaatodayUnsubResult['UNSUBS']."</td><td>".$vodacomIshikistaainteractionResult['INTERACTIONS']."</td><td>".$vodacomIshikistaainteractionResult['UNIQUE_PLAYERS']."</td><td>".$vodacomIshikistaatotalRevenueResult['REVENUE']."</td></tr></table>";
$message .="<br/> <br/>";


$message .= "<b><u>TIGO-ISHIKISTAA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Subscribers</th><th>Total Unsubscriptions</th><th>Subscribers Gained</th><th>Unsubscriptions For '.$curdate.'</th><th>Interactions</th><th>Revenue Generated</th></tr>';
$message .="<tr><td>".$tigoishikistaatotalSubscribersResult['SUBSCRIBERS']."</td><td>".$tigoishikistaatotalunSubscribersResult['UNSUBS']."</td><td>".$tigoishikistaasubscribersGainedResult['SUBSCRIBERS']."</td><td>".$tigoishikistaatodayUnsubResult['UNSUBS']."</td><td>".$tigoishikistaainteractionResult['INTERACTIONS']."</td><td>".$tigoishikistaatotalRevenueResult['REVENUE']."</td></tr></table>";
$message .="<br/> <br/>";



$message .= "<b><u>ZANTEL-SOKA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>SOKA</td><td>" . $zantelsokaSubscriberResult['SUB_TOTAL'] . "</td><td>" . $zantelsokaUnSubscriberResult['UNSUB_TOTAL'] . "</td><td>" . $zantelsokaRevenueResult['REVENUE'] . "</td></tr></table>";


$message .= "<b><u>ZANTEL-SOMA</u></b>";
$message .= '<table border="2" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:2px;border-style:None;border-collapse:collapse;font:200% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>soma</td><td>" . $zantelsomaSubscriberResult['SUB_TOTAL'] . "</td><td>" . $zantelsomaUnSubscriberResult['UNSUB_TOTAL'] . "</td><td>" . $zantelsomaRevenueResult['REVENUE'] . "</td></tr></table>";


$body = "</pre>
<div>";
$body .= $message;
$body .= "</div>" ;


processMail($body, $days);

function  processMail($message, $days){
$subject = "Trivia Analysis Report".date("Y-m-d",strtotime($days." day"));
    $to = [];
    $cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']      =  "Emanuel Julius";
$to[1]['Mail']          = 'reports@greentelecom.co.tz';
$to[1]['Name']      = 'Reports';
$cc[0]['Mail']         = 'richard@greentelecom.co.tz';
$cc[0]['Name']         = 'Richard Matemba Joseph';
$cc[1]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc[1]['Name']         = 'Hemedi Ali Hemedi';
$cc[2]['Mail']         = 'emanuel@greentelecom.co.tz';
$cc[2]['Name']         = 'Emanuel Julius Mpinga';


    sendEmail($message, $subject,$to,$cc);
}







function connect_tigo_trivia(){
    $conn = new mysqli("localhost","analysis_email","xc28FhrWV7wtXVeg@@@","trivia_tigotz_vas");
    if($conn){

        return $conn;
    }
    else{

        return FALSE;
    }
}



function connect_tigoishikistaa_trivia(){

       $conn = new Mysqli("localhost","analysis_email","xc28FhrWV7wtXVeg@@@","ishikistaa_tigo_vas");
    if($conn){

        return $conn;
    }
    else{

        return FALSE;
    }

}

function Connect($host, $database) {

    $conn = new Mysqli($host, "analysis_email", "xc28FhrWV7wtXVeg@@@", $database);
    if ($conn) {

        return $conn;
    } else {

        return FALSE;
    }
}