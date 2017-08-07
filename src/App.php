<?php

if(php_sapi_name() !== 'cli'){ die("Please use as CLI app."); }
require 'Commlib.php';

class App {
    private static $Lang = array();
    private static $Commvault = NULL;

    public function init(){
        self::$Commvault = new Commvault;
        self::$Lang = require 'locale/es.php';
    }

    public function help(){
        global $argv;

        $str = "Uso: $argv[0] <COMANDO>\n";

        $commands = [
            'ping', 'client', 'clients', 'clientgroup', 'library',
            'jobs', 'job'
        ];

        foreach($commands as $cmd){
            $str .= "\t" .str_pad($cmd, 15, " ", STR_PAD_RIGHT) .self::$Lang['help_' .$cmd] ."\n";
        }

        $str .= "\n";
        return $str;
    }
}

App::init();

if(count($argv) == 1){
    die( App::help() );
}

?>
