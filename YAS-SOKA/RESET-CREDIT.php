<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once 'DbConfig.php';

$conn = connect_db();
if($conn){
    $sql = "UPDATE tbl_qn_subscribers SET CREDIT = 0,DISCOUNT_FLAG='FALSE'"; 	
    $conn->query($sql);
    $conn->close();
}
function connect_db(){
    $conn = new mysqli(DB_HOST,DB_CONSUMER_USER,PASSWORD,TRIVIA_DB);
    if($conn){    
        return $conn;
    }
    else{
        
        return false;
    }
}
