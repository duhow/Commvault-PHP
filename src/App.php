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

    public function ping($client = NULL, $extra = "plain"){
        if(empty($client)){
            die( self::help("ping") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $name = $client;

        if(is_numeric($client)){
            $client = self::$Commvault->getClient($client);
            $client = strval($client->clientProperties->client['displayName']);
            if(!$client){
                die( self::$Lang['error_code_2'] ."\n" );
            }
            $name = $client;
        }

        $posible = ["plain", "summary", "sm", "html", "detail", "detailed", "dt"];
        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $client;
            $client = $tmp;
            unset($tmp);
        }
        if(in_array($extra, ["dt", "detailed"])){ $extra = "detail"; }
        if(in_array($extra, ["sm"])){ $extra = "summary"; }

        $flag = "-sm";
        if($extra == "detail"){ $flag = "-dt"; }

        $res = self::$Commvault->QCommand("qoperation checkready -c $client $flag");

        try {
            $xml = @simplexml_load_string($res);
            if(empty($xml)){
                throw new Exception();
            }
            // var_dump($xml);
            if(isset($xml['errorCode'])){
                $numcode = intval($xml['errorCode']);
                if(isset(self::$Lang['error_code_' .$numcode])){
                    die( self::$Lang['error_code_' .$numcode] ."\n");
                }
                die( strval($xml['errorMessage']) );
            }
        } catch (Exception $e) {
            // Es HTML sin error.

            if(in_array($extra, ["plain", "summary"])){
                $res = substr($res, strpos($res, "</style") + strlen("</style>"));
                $res = strip_tags($res);
            }

            if(strpos($res, "ClientReady") !== FALSE){
                $res = sprintf(self::$Lang['client_ping_ok'], $name);
            }

            die( $res ."\n" );
        }
    }

    public function client($search = NULL, $extra = NULL){
        if(empty($search)){
            die( self::help("client") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        if($search == "all"){
            return self::client_all($extra);
        }

        $posible = ["json", "xml", "summary", "id"];
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
        }elseif($extra == "id"){
            die( strval($cli->clientProperties->client->clientEntity['clientId']) ."\n" );
        }elseif($extra == "summary" or empty($extra)){
            $prop = $cli->clientProperties;
            $str = str_pad(self::$Lang['client_displayName'] .":", 24) ."#" .strval($prop->client->clientEntity['clientId']) ." " .strval($prop->client['displayName']) ."\n"
                    .str_pad(self::$Lang['client_hostName'] .":", 24) .strval($prop->client->clientEntity['hostName'])  ."\n"
                    .str_pad(self::$Lang['client_commCellName'] .":", 24) .strval($prop->client->clientEntity['commCellName']) ."\n"
                    .str_pad(self::$Lang['client_IsVirtualClient'] .":", 24) . ( (bool) $cli['IsVirtualClient'] ? self::$Lang['virtual'] : self::$Lang['physical'] ) ."\n"
                    ."\n";

            $sp = explode(",", strval($prop->client->versionInfo['version']));
            $version = intval($prop->client->versionInfo->GalaxyRelease['ReleaseString']) ." "
                        .$sp[0]; // strval($prop->client->versionInfo->PatchStatus[0]['BaselineUpdates'])

            $str .= str_pad(self::$Lang['client_OSName'] .":", 24) .strval($prop->client->osInfo->OsDisplayInfo['OSName']) ."\n"
                    .str_pad(self::$Lang['client_ProcessorType'] .":", 24) .strval($prop->client->osInfo->OsDisplayInfo['ProcessorType']) ."\n"
                    .str_pad(self::$Lang['client_versionInfo'] .":", 24) .$version ."\n"
                    ."\n";

            $cgs = array();
            foreach($prop->clientGroups as $cg){
                $cgs[] = strval($cg['clientGroupName']);
            }

            $str .= str_pad(self::$Lang['client_clientGroups'] .":", 24) .implode(", ", $cgs) ."\n";
            // Enable Backup / Restore / Data Aging
            // Description

            $str .= "\n";
            die( $str );
        }
    }

    public function library($search = NULL, $extra = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        // Por defecto mostrar todas las librerias
        if(empty($search)){
            $libraries = self::$Commvault->getLibrary();
            foreach($libraries as $id => $lib){
                echo str_pad($id, 10) .$lib ."\n";
            }
            die();
        }elseif($search == "sizes"){
            return self::library_sizes($extra);
        }

        $posible = ["size"];
        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $search;
            $search = $tmp;
            unset($tmp);
        }
    }

    private function library_sizes($output = "text"){
        if(empty($output)){ $output = "text"; }
        // $output = used, free, text, json, csv

        $libraries = self::$Commvault->getLibrary();
        $libsinfo = array();
        $libsizes = array();
        $c = 1;

        // Cargar las libs
        foreach($libraries as $id => $lib){
            echo "[" . self::progressbar($c++, count($libraries), 24) ."]\r";
            $libsinfo[$id] = self::$Commvault->getLibrary($id);
        }

        // Clear line
        echo str_pad("", 26) ."\r";

        // Extraer los bytes
        foreach($libsinfo as $id => $lib){
            $s = $lib->libraryInfo->magLibSummary;
            $data = [
                'available' => self::parserSize($s['totalAvailableSpace']),
                'capacity' => self::parserSize($s['totalCapacity']),
                'free' => self::parserSize($s['totalFreeSpace']),
            ];
            $data['used'] = $data['capacity'] - $data['free'];
            $data['percent'] = (float) number_format(($data['used'] / $data['capacity']) * 100, 2);
            $libsizes[$id] = $data;
        }

        if($output == "text"){
            $spacer = 0;
            // Calcular spacer
            foreach($libraries as $name){
                if(strlen($name) > $spacer){ $spacer = strlen($name); }
            }

            foreach($libsizes as $id => $sizes){
                echo str_pad($libraries[$id], $spacer + 4) .str_pad($sizes['percent'], 5, " ", STR_PAD_LEFT) ."% - "
                    .str_pad(self::parserSize($sizes['free'], "GB"), 8, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($sizes['capacity'], "GB"), 8, " ", STR_PAD_LEFT) ." GB\n";
            }
        }
    }

    private function client_all($output = "text"){
        if(empty($output)){ $output = "text"; }
        $clients = self::$Commvault->getClient();

        if($output == "text"){
            foreach($clients as $id => $name){
                echo str_pad($id, 10) .$name ."\n";
            }
        }elseif($output == "json"){
            echo json_encode($clients, JSON_PRETTY_PRINT);
        }elseif($output == "csv"){
            foreach($clients as $id => $name){
                echo $id .";" .$name ."\n";
            }
        }elseif(in_array($output, ["name", "names"])){
            foreach($clients as $name){
                echo $name ."\n";
            }
        }elseif(in_array($output, ["id", "ids"])){
            foreach($clients as $id => $name){
                echo $id ."\n";
            }
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

    private function progressbar($val, $max = 100, $chars = 12, $chfull = NULL, $chempty = NULL){
    	$chfull  = (empty($chfull) ? json_decode('"\u2588"') : $this->emoji($chfull));
    	$chempty = (empty($chempty) ? json_decode('"\u2592"') : $this->emoji($chempty));
    	$nfull = floor(($val / $max) * $chars);
    	if($nfull < 0){ $nfull = 0; }
    	$nempty = max(($chars - $nfull), 0);
    	$str = "";
    	for($i = 0; $i < $nfull; $i++){ $str .= $chfull; }
    	for($i = 0; $i < $nempty; $i++){ $str .= $chempty; }
    	return $str;
    }

    private function parserSize($str, $to = NULL, $dec = 2){
    	$str = trim($str);
    	$sizeType = substr($str, -2);
    	if(is_numeric($sizeType)){
    		$size = floatval($str);
    	}else{
    		$sizeType = strtoupper($sizeType);
    		$size = floatval(trim(substr($str, 0, -2)));
    	}

    	$types = [
    		"PB" => (1024 * 1024 * 1024 * 1024 * 1024),
    		"TB" => (1024 * 1024 * 1024 * 1024),
    		"GB" => (1024 * 1024 * 1024),
    		"MB" => (1024 * 1024),
    		"KB" => (1024)
    	];

    	if(in_array($sizeType, array_keys($types))){
    		$size = $size * $types[$sizeType];
    	}

    	if(empty($to)){ return round($size); }

    	$to = strtoupper($to);
    	if(in_array($to, array_keys($types))){
    		return number_format($size / $types[$to], $dec, ".", "");
    	}
    }
}

App::init();

if(count($argv) == 1){
    die( App::help() );
}

$command = strtolower($argv[1]);
if($command == "init" or !method_exists("App", $command)){
    die( App::help() );
}

$callback = $argv;
array_shift($callback); // Command
array_shift($callback); // Action

call_user_func_array(array("App", $command), $callback);

?>
