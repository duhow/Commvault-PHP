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

        $posible = [
            "json", "xml", "summary",
            "id", "status", "jobs", "lastjob", "size"
        ];
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
        }elseif($extra == "size"){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }

            $sizes = self::client_size($search, TRUE);
            if(empty($sizes)){
                die( self::$Lang['error_client_no_jobs'] );
            }
            $spacer = strlen(max($sizes));
            $spacergb = strlen(self::parserSize(max($sizes), "GB"));

            $percentage = number_format(($sizes[0] - $sizes[1]) / $sizes[0] * 100, 2);

            echo "App:  " .str_pad($sizes[0], $spacer, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($sizes[0], "GB"), $spacergb, " ", STR_PAD_LEFT) ." GB\n"
                ."Disk: " .str_pad($sizes[1], $spacer, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($sizes[1], "GB"), $spacergb, " ", STR_PAD_LEFT) ." GB ($percentage%)\n";
            die();
        }elseif(in_array($extra, ["jobs", "lastjob"])){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }

            if($extra == "lastjob"){ return self::client_lastjob($search); }
            return self::client_jobs($search); // All
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

        $posible = ["clients", "proxies", "size"];
        if(in_array($search, $posible) and !empty($filter)){
            $tmp = $filter;
            $filter = $search;
            $search = $tmp;
            unset($tmp);
        }

        if($filter == "size"){
            return self::clientgroup_size($search);
        }

        if(!is_numeric($search)){
            $search = self::$Commvault->getClientGroupId($search);
            if(!$search){
                die( self::$Lang['error_clientgroup_not_found'] );
            }
        }

        $cg = self::$Commvault->getClientGroup($search);
        if(!$cg){
            if(!$search){
                die( self::$Lang['error_clientgroup_not_found'] );
            }
        }

        if($filter == "clients"){
            return self::clientgroup_clients($cg, $output);
        }elseif($filter == "proxies"){
            return self::clientgroup_proxies($cg, $output);
        }

        if($output == "xml"){
            // Pretty
            $dom = dom_import_simplexml($cg)->ownerDocument;
            $dom->formatOutput = TRUE;
            echo $dom->saveXML();
        }else{
            var_dump($cg);
        }
    }

    private function clientgroup_clients($cgobj, $output = NULL){
        $clis = array();

        foreach($cgobj->clientGroupDetail->associatedClients as $cli){
            $id = intval($cli['clientId']);
            $name = strval($cli['clientName']);
            $host = strval($cli['hostName']);
            $clis[$id] = [
                'id' => $id,
                'name' => $name,
                'host' => $host
            ];
        }

        return self::generic_export_colarray($clis, $output);
    }

    private function clientgroup_proxies($cgobj, $output = NULL){
        $clis = array();

        foreach($cgobj->clientGroupDetail->firewallConfiguration->proxyEntities as $cli){
            $id = intval($cli['clientId']);
            $name = strval($cli['clientName']);
            $clis[$id] = $name;
        }

        return self::generic_export_keyval($clis, $output);
    }

    private function clientgroup_size($cgid){

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
        return self::generic_export_keyval($cgs, $output);
    }

    public function library($search = NULL, $extra = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $posible = ["size", "jobs", "csv", "json", "text"];
        if(in_array($search, $posible)){
            $tmp = $extra;
            $extra = $search;
            $search = $tmp;
            unset($tmp);
        }

        // Por defecto mostrar todas las librerias
        if($search == "sizes"){
            return self::library_sizes($extra);
        }elseif($extra == "jobs" and !empty($search)){
            return self::library_jobs($search);
        }elseif(empty($search)){
            $libraries = self::$Commvault->getLibrary();
            return self::generic_export_keyval($libraries, $extra);
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
        }elseif($extra == "xml"){
            $dom = dom_import_simplexml($lib)->ownerDocument;
            $dom->formatOutput = TRUE;
            $str = $dom->saveXML();
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

    private function library_jobs($libname, $output = "text"){
        $command = self::$Commvault->QCommand("qoperation execscript -sn QS_FindJobsOnStore -si 0 -si '$libname'");
        $xml = simplexml_load_string($command);
        $jobstxt = array();
        foreach($xml->FieldValue as $l){
            foreach($l->attributes() as $t){
                $text = trim(strval(current($t)));
                if(!empty($text)){ $jobstxt[] = $text; }
            }
        }

        /* if($output == "text"){
            die( implode("\n", $jobstxt) ."\n" );
        } */

        $lib = self::$Commvault->getLibrary($libname);
        $devn = array();
        foreach($lib->libraryInfo->MountPathList as $mpl){
            $drive = strval($mpl['mountPathName']);
            $name = strval($mpl->deviceInfo['name']);
            $devn[$name] = $drive;
        }

        foreach($jobstxt as $job){
            $data = array();
            $exp = explode(" ", $job);
            foreach($exp as $k){
                if(strlen($k) > 0){ $data[] = $k; }
            }
            $jobs[$data[0]] = [
                'jobId'             => $data[0],
                'storagePolicyName' => $data[1],
                'copyName'          => $data[2],
                'storeId'           => $data[3],
                'clientName'        => $data[4],
                'mountPathName'     => $data[5],
                'deviceName'        => $devn[$data[6]],
            ];
        }

        if($output == "text"){
            $lens = array();
            $cols = ['jobId', 'clientName', 'deviceName', 'storagePolicyName'];
            foreach($cols as $col){
                $ln = array_unique(array_column($jobs, $col));
                $lens[$col] = 0;
                foreach($jobs as $job){
                    if(strlen($job[$col]) > $lens[$col]){
                        $lens[$col] = strlen($job[$col]);
                    }
                }
            }

            foreach($jobs as $job){
                $str = "";
                foreach($cols as $col){
                    $str .= str_pad($job[$col], $lens[$col] + 4);
                }
                echo trim($str) ."\n";
            }
        }

        return self::generic_export_array($jobs, $output);
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

        return self::generic_export_keyval($policies, $output);
    }

    private function client_all($output = "text"){
        if(empty($output)){ $output = "text"; }
        $clients = self::$Commvault->getClient();

        return self::generic_export_keyval($clients, $output);
    }

    private function client_jobs($clientid){
        $jobs = self::$Commvault->getClientJobs($clientid);
        if(empty($jobs)){ die( self::$Lang['error_client_no_jobs'] ); }

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

    private function client_size($clientid, $all = FALSE){
        self::$Commvault->limit = 10000; // HACK
        $jobs = self::$Commvault->getClientJobs($clientid);
        if(empty($jobs)){ return NULL; }

        foreach($jobs as $k => $job){
            if(isset($job->sizeOfMediaOnDisk) and $job->isAged){ unset($jobs[$k]); }
        }

        $disk = array_sum(array_column($jobs, 'sizeOfMediaOnDisk'));
        $app  = array_sum(array_column($jobs, 'sizeOfApplication'));

        if(!$all){ return $disk; }
        return [$app, $disk];
    }

    private function log_severity($id){
        $severity = [
            0 => "INFO",
            3 => "MINOR",
            6 => "MAJOR",
            9 => "CRITICAL",
        ];

        if($id === TRUE){ return $severity; }
        if(isset($severity[$id])){ return $severity[$id]; }
        return NULL;
    }

    public function log($client = NULL, $filter = NULL){
        if(!self::load_token()){
            die( self::$Lang['error_token'] );
        }

        $extra = $client;
        $posible = [
            "job", "lastid", "full",
            "info", "minor", "major", "critical"
        ];

        if(in_array($client, $posible)){ $client = NULL; }
        elseif(in_array($filter, $posible)){ $extra = $filter; }

        // -------

        if(in_array($extra, ["full", "complete", "all"])){
            return self::log_full($client);
        }

        $conditions = array();

        if(!empty($filter)){
            $filter = strtoupper($filter);
            $filter = str_replace([",", ";"], " ", $filter);
            $filter = explode(" ", $filter);
            if(!is_array($filter)){ $filter = [$filter]; }

            $conditions = array(
                'showInfo' => "False",
                'showMinor' => "False",
                'showMajor' => "False",
                'showCritical' => "False",
            );

            foreach($filter as $f){
                if(self::log_severity($f)){
                    $n = ucwords(strtolower($f));
                    unset($conditions['show' .$n]);
                }
            }
        }

        $eventfinal = self::events_get($client, $conditions);

        if($extra == "lastid"){
            $event = array_pop($eventfinal);
            die( intval($event['id']) ."\n" );
        }

        echo self::events_output($eventfinal, "text");
    }

    private function log_full($client = NULL){
        $events = array();
        foreach(self::log_severity(TRUE) as $type){
            $conditions = array(
                'showInfo' => "False",
                'showMinor' => "False",
                'showMajor' => "False",
                'showCritical' => "False",
            );

            $n = 'show' .ucwords(strtolower($type));
            unset($conditions[$n]);

            foreach(self::events_get($client, $conditions) as $event){
                $events[$event['id']] = $event;
            }
        }

        ksort($events);
        echo self::events_output($events, "text");
    }

    private function events_get($client = NULL, $conditions = array()){
        $events = self::$Commvault->getEvents($client, $conditions);

        // Parse and reverse to get old -> new
        $final = array();
        foreach($events as $e){
            $data = array();
            foreach($e->attributes() as $name => $val){
                $data[$name] = strval($val);
            }
            foreach($e->clientEntity->attributes() as $name => $val){
                $data[$name] = strval($val);
            }
            $final[$data['id']] = $data;
        }

        return array_reverse($final);
    }

    private function events_output($data, $format = "text"){
        $str = "";

        if($format == "text"){
            foreach($data as $event){
                $id = $event['id'];
                if(isset($event['jobId'])){ $id = $event['jobId']; }
                $str .= date("M d H:i:s", intval($event['timeSource'])) ." " .strval($event['clientName']) ." "
                    .$event['subsystem'] ."[$id]: " .self::log_severity($event["severity"]) ." "
                    .$event["description"]
                    ."\n";
            }
        }

        return $str;
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

    private function generic_export_array($array, $output){
        if($output == "json"){
            echo json_encode($array, JSON_PRETTY_PRINT) ."\n";
        }elseif($output == "csv"){
            foreach($array as $cli){
                echo implode(";", array_values($cli)) ."\n";
            }
        }
    }

    private function generic_export_colarray($array, $output, $id = "id", $name = "name", $host = "host"){
        if($output == "json"){
            echo json_encode($array, JSON_PRETTY_PRINT) ."\n";
        }elseif($output == "csv"){
            foreach($array as $cli){
                echo $cli[$id] .";" .$cli[$name] .";" .$cli[$name] ."\n";
            }
        }elseif(in_array($output, ["id", "ids"])){
            foreach($array as $cli){
                echo $cli[$id] ."\n";
            }
        }elseif(in_array($output, ["name", "names"])){
            foreach($array as $cli){
                echo $cli[$name] ."\n";
            }
        }elseif(in_array($output, ["host", "hosts", "hostname"])){
            foreach($array as $cli){
                echo $cli[$name] ."\n";
            }
        }else{
            foreach($array as $id => $val){
                echo str_pad($id, 10);
                if(!is_array($val)){
                    echo "$val\n";
                }else{
                    echo $val[$name] ."\n";
                }
            }
        }
    }

    private function generic_export_keyval($array, $output){
        if($output == "json"){
            echo json_encode($array, JSON_PRETTY_PRINT) ."\n";
        }elseif($output == "csv"){
            foreach($array as $id => $name){
                echo "$id;$name\n";
            }
        }elseif(in_array($output, ["name", "names"])){
            foreach($array as $name){
                echo $name ."\n";
            }
        }elseif(in_array($output, ["id", "ids"])){
            foreach($array as $id => $name){
                echo $id ."\n";
            }
        }else{
            foreach($array as $id => $name){
                echo str_pad($id, 10) ."$name\n";
            }
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
