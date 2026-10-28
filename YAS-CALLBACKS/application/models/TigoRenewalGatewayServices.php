<?php
error_reporting(0);

date_default_timezone_set('Africa/Dar_Es_Salaam');

class TigoRenewalGatewayServices  extends CI_Model{

    var $renewal;	
    function __construct() {

    parent::__construct();
        $this->renewal = $this->load->database('renewal',TRUE); 
    }

    function _recordData($data,$database,$table){
        $this->renewal->insert($table,$data);
    	$id = $this->renewal->insert_id();
    	return $id;
    }

}

?>
