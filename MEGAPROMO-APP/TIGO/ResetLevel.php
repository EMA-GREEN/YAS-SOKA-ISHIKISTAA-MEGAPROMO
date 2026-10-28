<?php





$dbfile = loadfile('db.config');
$conn = __connect($dbfile);
if($conn){
$sql = "UPDATE tbl_promo_subscribers set LOST_LEVEL_ID = '1' ";
$conn->query($sql);
}

function __connect($dbfile=[]) {
        $mysql = new \mysqli($dbfile['DB_HOST'], $dbfile['PUBLISHER_USER'],$dbfile['DB_PASSWORD'] , $dbfile['DB_NAME']);
        return $mysql;
    }



  function loadfile($file) {
        $result = file_get_contents($file);
        $data = parse_ini_string($result);
        return $data;
    }
