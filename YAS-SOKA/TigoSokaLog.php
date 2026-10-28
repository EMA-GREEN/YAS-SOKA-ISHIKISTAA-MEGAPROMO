<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');

function tigoSokaLog($log_threshold, $data){
    $log_folder = "/var/www/html/TIGO-SOKA-LOGS/";
    $log_file = $log_folder."logs-".date("YmdH") . ".log";
    // Analog::handler (Analog\Handler\File::init ($log_file));

    // The log level (3rd value) must be formatted as a string
    Analog::$format = "%s - %s - %s - %s\n";
    Analog::handler (Analog\Handler\LevelName::init (
        Analog\Handler\File::init ($log_file)
    ));

    if($log_threshold === "INFO"){
        $log_threshold = Analog::INFO;
    }else{
        $log_threshold = Analog::DEBUG;
    }

    if(is_array($data)){
        $data = json_encode($data);
    }

    Analog::log($data, $log_threshold);
}

?>