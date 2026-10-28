<?php
namespace MegaPromoServices;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of MegaPromoServices
 *
 * @author Green Telecom
 */
class Database {
    
    var $userType,$host,$user,$password,$dbname;
    function __construct($username,$password,$db,$host) {
        $this->user = $username;
        $this->password = $password;
        $this->host = $host;
        $this->dbname = $db;
    }
    
    function __connect(){
        
        $mysql = new \mysqli($this->host,$this->user,$this->password,$this->dbname);
        return $mysql;
    }
}
