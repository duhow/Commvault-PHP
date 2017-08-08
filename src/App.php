<?php

if(php_sapi_name() !== 'cli'){ die("Please use as CLI app."); }
require 'Commlib.php';

class App {
    private static $Lang = array();
    private static $Commvault = NULL;
    private static $Config = array();
    private static $ConfigFile = NULL;

    public function init(){
        self::$Commvault = new Commvault;
        self::$Lang = require 'locale/es.php';
        self::$ConfigFile = $_SERVER['HOME'] .'/.config/Commvault-PHP/login.conf';
    }

    private function load_token(){
        $path = self::$ConfigFile;
        if(!file_exists($path) or !is_readable($path)){ return FALSE; }

        $conf = parse_ini_file($path);
        if(!$conf){ return FALSE; }

        $token = $conf['token'];
        $token = bin2hex(base64_decode($token));
        if(empty($token)){ return FALSE; }
        $token = substr($token, 0, -1); // Residual zero -> 545

        self::$Commvault->url = $conf['url'];
        self::$Commvault->setToken($token);

        self::$Config = $conf;

        return TRUE;
    }

    public function help($command = NULL){
        global $argv;

        if(empty($command)){ $command = "help"; }
        $str = "";

        switch ($command) {
            case 'ping':
                $str = sprintf(self::$Lang['help_usage'], $argv[0], "ping HOST") . "\n";
                $str .= self::$Lang['help_ping_extended'];
            break;

            case 'help':
            default:
                $str = sprintf(self::$Lang['help_usage'], $argv[0], self::$Lang['command']) . "\n";

                $commands = [
                    'ping', 'client', 'clients', 'clientgroup', 'library',
                    'jobs', 'job'
                ];

                foreach($commands as $cmd){
                    $str .= "\t" .str_pad($cmd, 15, " ", STR_PAD_RIGHT) .self::$Lang['help_' .$cmd] ."\n";
                }
            break;
        }

        $str .= "\n";
        return $str;
    }

    public function ping($client = NULL){
        if(empty($client)){
            die( self::help("ping") );
        }

        if(is_numeric($client)){
            // TODO get client name
        }
        // TODO qcommand
        $command = "qoperation checkready $client";
        // -dt : detail
        // -sm : summary
    }

    public function client($search = NULL, $extra = NULL){
        if(empty($search)){
            die( self::help("client") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $posible = ["json", "xml", "summary"];

        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $search;
            $search = $tmp;
            unset($tmp);
        }

        /* if(!is_numeric($search)){
            // Lookup name to ID
            $id = self::$Commvault->getClientId($search);
            if(empty($id)){
                die( self::$Lang['error_client_not_found'] );
            }
        } */

        $cli = self::$Commvault->getClient($search);
        if(empty($cli)){
            die( self::$Lang['error_client_not_found'] );
        }

        if($extra == "json"){
            die( json_encode( $cli, JSON_PRETTY_PRINT) );
        }elseif($extra == "xml"){
            die( $cli->asXML() );
        }elseif($extra == "summary" or empty($extra)){
            $prop = $cli->clientProperties;
            $str = str_pad("Client Name:", 20) ."#" .strval($prop->client->clientEntity['clientId']) ." " .strval($prop->client['displayName']) ."\n"
                    .str_pad("Host Name:", 20) .strval($prop->client->clientEntity['hostName'])  ."\n"
                    .str_pad("CommServe HostName:", 20) .strval($prop->client->clientEntity['commCellName']) ."\n"
                    .str_pad("Physical/Virtual:", 20) . ( (bool) $cli['IsVirtualClient'] ? "Virtual" : "Physical" ) ."\n"
                    ."\n";

            $sp = explode(",", strval($prop->client->versionInfo['version']));
            $version = intval($prop->client->versionInfo->GalaxyRelease['ReleaseString']) ." "
                        .$sp[0]; // strval($prop->client->versionInfo->PatchStatus[0]['BaselineUpdates'])

            $str .= str_pad("OS:", 20) .strval($prop->client->osInfo->OsDisplayInfo['OSName']) ."\n"
                    .str_pad("Platform:", 20) .strval($prop->client->osInfo->OsDisplayInfo['ProcessorType']) ."\n"
                    .str_pad("CommVault Version:", 20) .$version ."\n"
                    ."\n";

            $cgs = array();
            foreach($prop->clientGroups as $cg){
                $cgs[] = strval($cg['clientGroupName']);
            }

            $str .= str_pad("Client Groups:", 20) .implode(", ", $cgs) ."\n";

            $str .= "\n";
            die( $str );

            // CG
            // Enable Backup / Restore / Data Aging

            // Description
        }
    }

    public function login($username = NULL, $password = NULL, $host = NULL){
        if(!empty($username) and empty($password) and empty($host)){
            $host = $username;
            $username = NULL;
        }

        if(empty($host)){ $host = "localhost"; }
        if(strpos($host, "http") === FALSE){
            $host = "http://$host";
        }
        if(strpos($host, ":", 6) === FALSE){
            $host = "$host:81";
        }

        if(strpos($host, "Service") === FALSE){
            $host .= "/SearchSvc/CVWebService.svc/";
        }

        if(empty($username)){
            $username = readline(self::$Lang['input_username'] ." ");
        }

        if(empty($password)){
            echo self::$Lang['input_password'] ." ";

            system('stty -echo');
            $password = trim(fgets(STDIN));
            system('stty echo');

            echo "\n";
        }

        self::$Commvault->username = $username;
        self::$Commvault->password = $password;
        self::$Commvault->url = $host;

        $login = self::$Commvault->login();
        if($login !== TRUE){
            die();
        }

        $token = trim(substr(self::$Commvault->getToken(), 4)) ."0";
        $token = base64_encode(hex2bin($token));

        $conf = str_pad("url", 10)    ."= $host\n"
                .str_pad("token", 10) ."= $token\n";

        $path = self::$ConfigFile;
        if(!is_dir(dirname($path))){
            mkdir(dirname($path) .'/', 0770, TRUE);
        }
        file_put_contents($path, $conf);
        chmod($path, 0600);

        if(file_exists($path) and is_readable($path)){
            die("Login correcto.");
        }

    }
}

App::init();

if(count($argv) == 1){
    die( App::help() );
}

// call_user_func_array

$command = strtolower($argv[1]);
if($command == "init" or !method_exists("App", $command)){
    die( App::help() );
}

$callback = $argv;
array_shift($callback); // Command
array_shift($callback); // Action

call_user_func_array(array("App", $command), $callback);

?>
