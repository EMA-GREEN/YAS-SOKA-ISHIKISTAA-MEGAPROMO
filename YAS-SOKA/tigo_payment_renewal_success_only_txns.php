<?php

set_time_limit(0);
ini_set('memory_limit', '-1');

//open db conn
$local_db = local_db_conn();

echo "Start Payment Renewals success only txns extraction\n";

$tbl_initial = "tbl_payment_renewal_notifications_202";
$db_name = "tigo_megapromo";
$sql = "SHOW TABLES LIKE '%".$tbl_initial."%'";

$startDate = DateTime::createFromFormat('Ymd', '20231101');
$endDate = DateTime::createFromFormat('Ymd', '20240312');

if($local_db){
	$txn_tbl_rslts = mysqli_query($local_db, $sql);
	while ($row = mysqli_fetch_array($txn_tbl_rslts)) {
		$table = $row['Tables_in_'.$db_name.' (%'.$tbl_initial.'%)'];

		$tbl_date = substr($table, strrpos($table, "_") + 1);

		$success_table = str_replace($tbl_date, "success_".$tbl_date, $table);

		$date = DateTime::createFromFormat('Ymd', $tbl_date);
		if ($date >= $startDate && $date <= $endDate) {
		    $create_query = "CREATE TABLE ".$success_table." AS SELECT * FROM ".$table." WHERE PAYMENT_STATUS='SUCCESS'";
		    $drop_query = "DROP TABLE ".$table."";
			mysqli_query($local_db, $create_query);
			mysqli_query($local_db, $drop_query);
			echo "...".$table." done\n";
		}
	}
	mysqli_close($local_db);
}

echo "Done Payment Renewals success only txns extraction at ".date('Y-m-d H:i:s')."\n";

function local_db_conn(){
	try {
       	$local_conn = mysqli_connect("localhost","trivia_publisher", "bJ++r5tL>xQdG:7H","tigo_megapromo");
		if($local_conn){
			return $local_conn;
		} else {
			return false;
		}
    } catch (mysqli_sql_exception $e) {
        return false;
    } catch (Exception $e) {
       return false;
    }
}