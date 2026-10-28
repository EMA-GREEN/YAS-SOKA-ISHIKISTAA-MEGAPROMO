<?php
//error_reporting(0);
set_time_limit(0);
ini_set('memory_limit', '-1');


$response = file_get_contents("SokaRenewals-20220818.txt");





$arr = explode('"CP_NOTIFICATION"}', $response);



//var_dump($arr);
//die();


//$conn = connect_db();



$string = "";
  foreach($arr as $key=>$value) {
    echo $arr[$key].'"CP_NOTIFICATION"}';      


  }

  //$sql .= ",)";

  //$sqlquery = str_replace(",,)","",$sql);

//  file_put_contents("03.txt",$string);


if($conn){
      $conn->query($sql);
  }

function connect_db(){

    $conn = new mysqli("127.0.0.1","trivia_halotel_consumer",'bJ++r5tL>xQdG:7H','trivia_halotel_vas');
    if($conn){
        return $conn;
    }
    else{

        return false;
    }

}

function get_string_between($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

