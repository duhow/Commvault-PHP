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
                $str = sprintf(self::$Lang['help_usage'], basename($argv[0]), self::$Lang['command']) . "\n";

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

        $posible = ["plain", "summary", "sm", "html", "detail", "detailed", "dt", "clientgroup", "group"];
        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $client;
            $client = $tmp;
            unset($tmp);
        }
        if(in_array($extra, ["group", "clientgroup"])){
            return self::ping_clientgroup($client);
        }
        if(in_array($extra, ["dt", "detailed"])){ $extra = "detail"; }
        if(in_array($extra, ["sm"])){ $extra = "summary"; }

        $flag = "-sm";
        if($extra == "detail"){ $flag = "-dt"; }

        $name = $client;

        if(is_numeric($client)){
            $client = self::$Commvault->getClient($client);
            $client = strval($client->clientProperties->client['displayName']);
            if(!$client){
                die( self::$Lang['error_code_2'] ."\n" );
            }
            $name = $client;
        }

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
                    echo self::$Lang['error_code_' .$numcode] ."\n";
                }else{
                    echo strval($xml['errorMessage']);
                }
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

            echo $res ."\n";
        }
    }

    private function ping_clientgroup($cgid){
        $clients = self::$Commvault->getClientGroupClients($cgid);
        if(!$clients){
            echo self::$Lang['error_clientgroup_not_found'];
            die();
        }

        $spacer = 0;
        foreach($clients as $name){
            if(strlen($name) > $spacer){ $spacer = strlen($name); }
        }
        $spacer = $spacer + 4;

        foreach($clients as $id => $name){
            echo str_pad($name, $spacer);
            $res = self::$Commvault->QCommand("qoperation checkready -c $name -sm");
            try {
                $xml = @simplexml_load_string($res);
                if(empty($xml)){
                    throw new Exception();
                }
                // var_dump($xml);
                if(isset($xml['errorCode'])){
                    echo "KO";
                    // intval($xml['errorCode']);
                }
            } catch (Exception $e) {
                $res = substr($res, strpos($res, "</style") + strlen("</style>"));
                $res = strip_tags($res);

                if(strpos($res, "ClientReady") !== FALSE){
                    echo "OK";
                }
            }
            echo "\n";
        }
    }

    public function client($search = NULL, $extra = NULL){
        if(empty($search)){
            die( self::help("client") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        if(in_array($search, ["all", "list"])){
            return self::client_all($extra);
        }

        $posible = ["json", "xml", "summary", "id", "status", "jobs", "lastjob"];
        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $search;
            $search = $tmp;
            unset($tmp);
        }

        // Ping
        if($extra == "status"){
            return self::ping($search);
        }elseif($extra == "jobs"){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }
            return self::client_jobs($search);
        }elseif($extra == "lastjob"){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }
            return self::client_lastjob($search);
        }

        $cli = self::$Commvault->getClient($search);
        if(empty($cli) or !isset($cli->clientProperties)){
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

    public function clientgroup($search = NULL, $filter = NULL, $output = NULL){
        if(empty($search)){
            die( self::help("clientgroup") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        if(!is_numeric($search)){
            $cgs = self::$Commvault->getClientGroup();
            $k = array_search($search, $cgs);
            if($k === FALSE){
                die("No se encuentra el grupo de clientes." ."\n");
            }
            $search = $k;
        }
        $cg = self::$Commvault->getClientGroup($k);

        if($output == "xml"){
            // Pretty
            $dom = dom_import_simplexml($cg)->ownerDocument;
            $dom->formatOutput = TRUE;
            echo $dom->saveXML();
        }else{
            var_dump($cg);
        }

    }

    public function clientgroups($output = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        return self::clientgroup_list($output);
    }

    private function clientgroup_list($output = NULL){
        $cgs = self::$Commvault->getClientGroup();

        if(!$cgs){ return NULL; }
        if(empty($output)){ $output = "text"; }

        if($output == "json"){
            echo json_encode($cgs, JSON_PRETTY_PRINT) ."\n";
        }elseif($output == "csv"){
            foreach($cgs as $id => $name){
                echo "$id;$name\n";
            }
        }elseif(in_array($output, ["name", "names"])){
            foreach($cgs as $name){
                echo $name ."\n";
            }
        }elseif(in_array($output, ["id", "ids"])){
            foreach($cgs as $id => $name){
                echo $id ."\n";
            }
        }else{
            foreach($cgs as $id => $name){
                echo str_pad($id, 10) ."$name\n";
            }
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

        if(is_string($search)){
            // Pasar a numero
            $libraries = self::$Commvault->getLibrary();
            $k = array_search($search, $libraries);
            if($k === FALSE){
                die( self::$Lang['error_library_exist'] ."\n" );
            }
            $search = $k;
        }

        $lib = self::$Commvault->getLibrary($search);
        $mls = $lib->libraryInfo->magLibSummary;

        if($extra == "size"){
            $data = [
                'free' => self::parserSize($mls['totalFreeSpace']),
                'total' => self::parserSize($mls['totalCapacity']),
            ];
            $data['used'] = $data['total'] - $data['free'];
            $data['percent_used'] = number_format($data['used'] / $data['total'] * 100, 2);
            $data['percent_free'] = number_format($data['free'] / $data['total'] * 100, 2);


            $str = strval($lib->libraryInfo->library['libraryName']) ."\n"
                    .str_pad(self::$Lang['bytes_free'] .":", 20) .self::parserSize($data['free'], "GB") ." GB - " .$data['percent_free'] ."%\n"
                    .str_pad(self::$Lang['bytes_used'] .":", 20) .self::parserSize($data['used'], "GB") ." GB - " .$data['percent_used'] ."%\n"
                    .str_pad(self::$Lang['bytes_total'] .":", 20) .self::parserSize($data['total'], "GB")  ." GB\n"
                    ."[" .self::progressbar($data['percent_used'], 100, 28) ."]\n";
        }else{
            $MAs = explode(",", trim(strval($mls['associatedMediaAgents'])));

            $str = strval($lib->libraryInfo->library['libraryName']) .' - ' .strval($mls['isOnline']) ."\n"
                    .str_pad("MA:", 16) .implode(", ", $MAs);

            $str .= "\n";

            $backup1 = self::parserSize($mls['bytesBackedupInLast1H'], "GB");
            $backup24 = self::parserSize($mls['bytesBackedupInLast24H'], "GB");
            $free = self::parserSize($mls['totalFreeSpace'], "GB");
            $percent = number_format($free / self::parserSize($mls['totalCapacity'], "GB") * 100, 2);

            $str .= str_pad(self::$Lang['lastbackup'], 16) .date("d/m/Y H:i", strtotime(strval($mls['lastBackupTime']))) ."\n"
                    .str_pad(self::$Lang['library_backupgiga'], 16) .$backup1 ." / " .$backup24 ."\n"
                    .str_pad(self::$Lang['library_freespace'], 16) ."$free GB ($percent%)" ."\n";
        }

        echo $str;
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
            if($output == "text"){
                echo "[" . self::progressbar($c++, count($libraries), 24) ."]\r";
            }
            $libsinfo[$id] = self::$Commvault->getLibrary($id);
        }

        if($output == "text"){
            // Clear line
            echo str_pad("", 26) ."\r";
        }

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
        }elseif(in_array($output, ["bar", "progress", "progressbar"])){
            $spacer = 0;
            // Calcular spacer
            foreach($libraries as $name){
                if(strlen($name) > $spacer){ $spacer = strlen($name); }
            }

            foreach($libsizes as $id => $sizes){
                echo str_pad($libraries[$id], $spacer + 4)
                    ."[" .self::progressbar($sizes['percent'], 100, 28) ."] "
                    .str_pad($sizes['percent'], 5, " ", STR_PAD_LEFT) ."%\n";
            }
        }elseif($output == "csv"){
            foreach($libsizes as $id => $sizes){
                echo $libraries[$id] .";" .$sizes['free'] .";" .$sizes['used'] .";" .$sizes['capacity'] .";" .round($sizes['percent']) ."\n";
            }
        }elseif($output == "json"){
            $data = array();
            foreach($libsizes as $id => $sizes){
                $data[$libraries[$id]] = [
                    'free' => $sizes['free'],
                    'used' => $sizes['used'],
                    'total' => $sizes['capacity'],
                    'percent' => $sizes['percent']
                ];
            }
            echo json_encode($data, JSON_PRETTY_PRINT);
        }
    }

    public function jobs($client = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        if(empty($client)){
            $jobs = self::$Commvault->QCommand("qlist jobsummary -c ");

            // Separate string
            $jobs = explode("\n", $jobs);
            $headers = explode(" ", $jobs[0]);
            foreach($headers as $k => $v){
                if(empty($v)){ unset($headers[$k]); }
            }
            $headers = array_values($headers);

            $values = explode(" ", $jobs[2]); // Values
            foreach($values as $k => $v){
                if(strlen($v) == 0){ unset($values[$k]); }
            }
            $values = array_values($values);

            unset($jobs);
            foreach($headers as $k => $n){
                $jobs[$n] = $values[$k];
            }

            foreach($jobs as $name => $amount){
                echo str_pad($name, 12) .$amount ."\n";
            }
        }
    }

    public function job($jobid, $output = NULL){
        if(empty($jobid)){
            die( self::help("job") );
        }

        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $job = self::$Commvault->getJob($jobid);
        if(!isset($job->jobSummary)){
            die( self::$Lang['job_not_exist'] ."\n" );
        }

        $job = $job->jobSummary;
        echo "JOB #" .$job['jobId'] ." - " .$job['percentComplete'] ."% " .$job['status'] ."\n"
            ."CLI #" .$job->subclient['clientId'] ." - " .$job->subclient['clientName'] ."\n"
            .$job['jobType']." " .$job['backupLevelName']." " .$job['appTypeName'] ."\n"
            .date("d/m/y H:i", intval($job['jobStartTime'])) ." - " .date("d/m/y H:i", intval($job['lastUpdateTime']))
            ." (" .gmdate("H:i:s", intval($job['jobElapsedTime'])) .")\n";

        if($job['jobType'] == "Backup"){
            echo self::parserSize($job['sizeOfApplication'], "GB") ." GB -> "
                .self::parserSize($job['sizeOfMediaOnDisk'], "GB") ." GB (-"
                .number_format(floatval($job['percentSavings']), 2) ."%)"
                ."\n";

            $files = array();
            if($job['totalFailedFiles'] > 0){
                $files[] = "F: " .$job['totalFailedFiles'];
            }
            if($job['totalFailedFolders'] > 0){
                $files[] = "D: " .$job['totalFailedFolders'];
            }
            if($job['totalNumOfFiles'] > 0){
                $files[] = "A: " .$job['totalNumOfFiles'];
            }

            echo implode(", ", $files);

        }

        echo "\n";

    }

    public function storagepolicy($MA = NULL, $output = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $posible = ["text", "id", "ids", "name", "names", "json", "csv"];
        if(in_array($MA, $posible)){
            $tmp = $output;
            $output = $MA;
            $MA = $tmp;
            unset($tmp);
        }

        $policies = array();
        if(empty($MA)){
            $policies = self::$Commvault->getStoragePolicy();
        }else{
            if(!is_numeric($MA)){
                $MA = self::$Commvault->getClientId($MA);
                if(empty($MA)){ return FALSE; }
            }

            $storages = self::$Commvault->getStoragePolicyMA($MA);
            foreach($storages as $st){
                $policies[$st['storagePolicyId']] = $st['storagePolicyName'];
            }
        }

        if($output == "json"){
            echo json_encode($policies, JSON_PRETTY_PRINT) ."\n";
        }elseif($output == "csv"){
            foreach($policies as $id => $name){
                echo "$id;$name\n";
            }
        }elseif(in_array($output, ["id", "ids"])){
            foreach($policies as $id => $name){
                echo "$id\n";
            }
        }elseif(in_array($output, ["name", "names"])){
            foreach($policies as $id => $name){
                echo "$name\n";
            }
        }else{
            foreach($policies as $id => $name){
                echo str_pad($id, 10) ."$name\n";
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
            echo json_encode($clients, JSON_PRETTY_PRINT) ."\n";
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

    private function client_jobs($clientid){
        $jobs = self::$Commvault->getClientJobs($clientid);

        $status = array();
        foreach($jobs as $job){
            if(!isset($status[$job->status])){ $status[$job->status] = 0; }
            $status[$job->status]++;
        }

        foreach($status as $name => $amount){
            echo str_pad($name, 32) .$amount ."\n";
        }

        $lastjob = max(array_keys($jobs));
        if($lastjob){
            echo "\n";
            return self::client_lastjob($jobs[$lastjob]);
        }
    }

    private function client_lastjob($clientid){
        if(!is_object($clientid) and is_numeric($clientid)){
            $jobs = self::$Commvault->getClientJobs($clientid);
            if(empty($jobs)){ return FALSE; }
            $lastjob = max(array_keys($jobs));
            $job = $jobs[$lastjob];
        }else{ // Si pasamos el object Job directamente
            $job = $clientid;
        }

        echo self::$Lang['lastjob'] ." #$job->jobId - $job->status\n"
            ."$job->jobType $job->backupLevelName $job->appTypeName\n"
            .date("d/m/y H:i", $job->jobStartTime) ." - " .date("d/m/y H:i", $job->lastUpdateTime)
            ." (" .gmdate("H:i:s", $job->jobElapsedTime) .")\n"
            ."\n";
    }

    public function login($username = NULL, $password = NULL, $host = NULL){
        if(!empty($username) and empty($password) and empty($host)){
            $host = $username;
            $username = NULL;
        }

        if(empty($host)){
            $host = "localhost";
            if(file_exists(self::$ConfigFile) and is_readable(self::$ConfigFile)){
                self::$Config = parse_ini_file(self::$ConfigFile);
                if(isset(self::$Config['url'])){
                    $host = self::$Config['url'];
                    echo self::$Lang['login_reconnect_host'] ." $host\n";
                }
            }
        }
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

        echo self::$Lang['login_connecting'] ."\r";

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
            die(self::$Lang['login_ok'] ."\n");
        }
    }

    public function logout(){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $query = self::$Commvault->logout();
        unlink(self::$ConfigFile);
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

// Block private methods
$r = new ReflectionMethod("App", $command);
if(!$r->isPublic()){
    die( App::help() );
}

$callback = $argv;
array_shift($callback); // Command
array_shift($callback); // Action

call_user_func_array(array("App", $command), $callback);

?>
