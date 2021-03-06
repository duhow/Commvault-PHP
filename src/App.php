<?php

if(php_sapi_name() !== 'cli'){ die("Please use as CLI app."); }
// Require php-xml and php-curl.
foreach(['xml', 'curl'] as $ext){
    if (!extension_loaded($ext) and !dl("$ext.so")){ die("Could not load php-$ext."); }
}
require 'Commlib.php';

class App {
    private static $Lang = array();
    private static $Commvault = NULL;
    private static $Config = array();
    private static $ConfigFile = NULL;
    private static $Version = "11.9.1227.1";

    public function init(){
        self::$Commvault = new Commvault;
        self::$Lang = require 'locale/es.php';
        self::$ConfigFile = $_SERVER['HOME'] .'/.config/Commvault-PHP/login.conf';
    }

    private function load_token(){
        $path = self::$ConfigFile;
        if(!file_exists($path) or !is_readable($path)){ return FALSE; }
        if(filemtime($path) + 1800 < time()){ return FALSE; }

        $conf = parse_ini_file($path);
        if(!$conf){ return FALSE; }

        $token = $conf['token'];
        $token = bin2hex(base64_decode($token));
        if(empty($token)){ return FALSE; }
        $token = substr($token, 0, -1); // Residual zero -> 545

        self::$Commvault->url = $conf['url'];
        self::$Commvault->setToken($token);

        self::$Config = $conf;
        touch($path);

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

            case 'version':
                $str = "Commvault-PHP " .self::$Version;
                if(file_exists("version")){
                    $git = file_get_contents("version");
                    $str .= " git " .substr($git, 0, 7) ."+";
                }
            break;

            case 'help':
            default:
                $str = self::help("version");
                $str .= sprintf(self::$Lang['help_usage'], basename($argv[0]), self::$Lang['command']) . "\n";

                $commands = [
                    'client', 'clients', 'clientgroup', 'clientgroups',
                    'ping', 'storagepolicy', 'library', 'backend',
                    'job', 'jobs', 'log',
                    'execute', 'console',
                    'login', 'logout'
                ];

                foreach($commands as $cmd){
                    $str .= str_pad("", 4) .str_pad($cmd, 15, " ", STR_PAD_RIGHT) .self::$Lang['help_' .$cmd] ."\n";
                }
            break;
        }

        $str .= "\n";
        echo $str;
    }

    public function ping($client = NULL, $extra = "plain"){
        if(empty($client)){
            return self::help("ping");
        }

        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
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
                echo self::$Lang['error_code_2'] ."\n";
                return FALSE;
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
            return FALSE;
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

    public function clients($output = NULL, $param = NULL){
        $command = strtolower($output);
        if($command == "backend"){
            return self::clients_backend($param);
        }elseif($command == "assoc"){
            return self::clients_assoc($param);
        }
        return self::client("all", $output);
    }

    public function client($search = NULL, $extra = NULL){
        if(empty($search)){
            return self::help("client");
        }

        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        if(in_array($search, ["all", "list"])){
            return self::client_all($extra);
        }

        if($search == "search"){
            $output = self::client_search($extra);
            if(is_numeric($output)){
                $search = $output;
                $extra = NULL;
            }else{
                return $output;
            }
        }

        $posible = [
            "json", "xml", "summary",
            "id", "status", "ping", "jobs", "size", "backend",
            "lastjob", "lastfull"
        ];
        if(!empty($extra) and !in_array($extra, $posible)){
            // Rotate if not contains command TODO
            $tmp = $extra;
            $extra = $search;
            $search = $tmp;
            unset($tmp);
        }

        // Ping
        if(in_array($extra, ["status", "ping"])){
            return self::ping($search);
        }elseif($extra == "backend"){
            return self::client_backend($search);
        }elseif($extra == "size"){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }

            $sizes = self::client_size($search, TRUE);
            if(empty($sizes)){
                echo self::$Lang['error_client_no_jobs'] ."\n";
                return NULL;
            }
            $spacer = strlen(max($sizes));
            $spacergb = strlen(self::parserSize(max($sizes), "GB"));

            $percentage = number_format(($sizes[0] - $sizes[1]) / $sizes[0] * 100, 2);

            echo "App:  " .str_pad($sizes[0], $spacer, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($sizes[0], "GB"), $spacergb, " ", STR_PAD_LEFT) ." GB\n"
                ."Disk: " .str_pad($sizes[1], $spacer, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($sizes[1], "GB"), $spacergb, " ", STR_PAD_LEFT) ." GB ($percentage%)\n";
            return TRUE;
        }elseif(in_array($extra, ["jobs", "lastjob", "lastfull"])){
            if(!is_numeric($search)){
                $search = self::$Commvault->getClientId($search);
            }

            if($extra == "lastjob"){ return self::client_lastjob($search); }
            elseif($extra == "lastfull"){ return self::client_lastfull($search); }
            return self::client_jobs($search); // All
        }

        $cli = self::$Commvault->getClient($search);
        if(empty($cli) or !isset($cli->clientProperties)){
            echo self::$Lang['error_client_not_found'];
            return NULL;
        }

        if($extra == "json"){
            echo json_encode( $cli, JSON_PRETTY_PRINT);
        }elseif($extra == "xml"){
            echo $cli->asXML();
        }elseif($extra == "id"){
            echo strval($cli->clientProperties->client->clientEntity['clientId']) ."\n";
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
            echo $str;
        }
    }

    private function client_search($search = NULL, $return = FALSE){
        if(empty(trim($search))){
            echo self::$Lang['error_search_empty'];
            return FALSE;
        }

        $search = strtolower($search);
        $clients = self::$Commvault->getClient();
        $posible = array();

        foreach($clients as $id => $cli){
            $cli = strtolower($cli);
            if($search == $cli){ return $id; }
            elseif(strpos($cli, $search) !== FALSE){ $posible[] = $id; }
        }

        if(count($posible) == 1){ return current($posible); }

        $final = array();
        foreach($posible as $p){
            $final[$p] = $clients[$p];
        }

        return self::generic_export_keyval($final, "text");
    }

    public function clientgroup($search = NULL, $filter = NULL, $output = NULL){
        if(empty($search)){
            return self::help("clientgroup");
        }

        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $posible = ["clients", "proxies", "size", "backend"];
        if(in_array($search, $posible) and !empty($filter)){
            $tmp = $filter;
            $filter = $search;
            $search = $tmp;
            unset($tmp);
        }

        if(empty($output) and $filter == "xml"){
            $output = $filter;
            $filter = NULL;
        }

        if(!is_numeric($search)){
            $search = self::$Commvault->getClientGroupId($search);
            if(!$search){
                echo self::$Lang['error_clientgroup_not_found'];
                return FALSE;
            }
        }

        if($filter == "size"){
            return self::clientgroup_size($search, $output);
        }

        $cg = self::$Commvault->getClientGroup($search);
        if(!$cg or isset($cg['errorMessage'])){
            echo self::$Lang['error_clientgroup_not_found'];
            return FALSE;
        }

        if($filter == "clients"){
            return self::clientgroup_clients($cg, $output);
        }elseif($filter == "proxies"){
            return self::clientgroup_proxies($cg, $output);
        }elseif($filter == "backend"){
            return self::clientgroup_backend($cg, $output);
        }

        if($output == "xml"){
            // Pretty
            $dom = dom_import_simplexml($cg)->ownerDocument;
            $dom->formatOutput = TRUE;
            echo $dom->saveXML();
        }else{
            $cg = $cg->clientGroupDetail;
            $str = "#" .strval($cg->clientGroup['clientGroupId']) ." - "
                        .strval($cg->clientGroup['clientGroupName']) ."\n";

            $str .= count($cg->associatedClients) ." " .strtolower(self::$Lang['clients']) ."\n";

            $str .= "\n" . self::$Lang['roles'] .":\n";
            $access = array();
            foreach($cg->securityAssociations->associations as $uginfo){
                $key = strval($uginfo->userOrGroup['_type_']);
                if($key == 15){
                    $key = "userGroup";
                }elseif($key == 13){
                    $key = "user";
                }else{
                    continue;
                }

                $roles = array();
                if(isset($uginfo->properties->role['roleName'])){
                    $roles[] = strval($uginfo->properties->role['roleName']);
                }else{
                    foreach($uginfo->properties->permissions as $perm){
                        $roles[] = strval($perm['permissionName']);
                    }
                }

                $access[] = [
                    'Id' => intval($uginfo->userOrGroup[$key .'Id']),
                    'Name' => strval($uginfo->userOrGroup[$key .'Name']),
                    'Roles' => $roles,
                ];
            }

            $spacer = 0;
            foreach($access as $a){
                if(strlen($a['Name']) > $spacer){
                    $spacer = strlen($a['Name']);
                }
            }

            foreach($access as $a){
                $str .= str_pad("", 4) .str_pad($a['Name'], $spacer);
                $roltxt = "";
                foreach($a['Roles'] as $role){
                    $roltxt .= " - $role" ."\n" . str_pad("", 4 + $spacer);
                }
                $str .= " " .trim($roltxt) ."\n";
            }
            echo $str;
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

    private function clientgroup_size($cgid, $output = "text"){
        $showall = FALSE;
        if(empty($output)){ $output = "text"; }
        elseif($output == "all"){
            $output = "text";
            $showall = TRUE;
        }
        $clients = self::$Commvault->getClientGroupClients($cgid);

        $spacer = 0;
        foreach($clients as $id => $name){
            if(strlen($name) > $spacer){
                $spacer = strlen($name);
            }
        }

        if($output == "text"){
            echo sprintf(self::$Lang['clientgroup_processing_amount'], count($clients)) ."\n";
        }

        foreach($clients as $id => $name){
            $sizes = self::client_size($id, TRUE);
            $clients[$id] = [
                'clientId' => $id,
                'clientName' => $name,
                'sizeOfApplication' => $sizes[0],
                'sizeOfMediaOnDisk' => $sizes[1],
            ];
            if($output == "csv"){
                echo implode(";", $clients[$id]) ."\n";
            }elseif($output == "text"){
                echo str_pad($name, $spacer + 4)
                    .str_pad(self::parserSize($sizes[1], "GB"), 9, " ", STR_PAD_LEFT) ." GB";
                if($showall){
                    echo str_pad(self::parserSize($sizes[0], "GB"), 11, " ", STR_PAD_LEFT) ." GB";
                }
                echo "\n";
            }
        }

        if($output == "text"){
            // TOTAL
            $size = array_sum(array_column($clients, 'sizeOfMediaOnDisk'));
            echo str_pad("TOTAL", $spacer + 4)
                .str_pad(self::parserSize($size, "GB"), 9, " ", STR_PAD_LEFT) ." GB";
            if($showall){
                $size = array_sum(array_column($clients, 'sizeOfApplication'));
                echo str_pad(self::parserSize($size, "GB"), 12, " ", STR_PAD_LEFT) ." GB";
            }
            echo "\n";
        }elseif($output == "json"){
            echo json_encode($clients, JSON_PRETTY_PRINT) ."\n";
        }
    }

    public function backend($output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        if(!in_array($output, ["xml", "json"])){
            $clients = self::$Commvault->getClient();
            $clients = array_flip($clients); // Name -> ID
        }
        if($output == "xml"){
            $command = self::$Commvault->QCommand("qoperation execscript -sn BackendStorage -si @i_backupType='All'");
            $command = str_replace("/><", "/>\n<", $command);
            echo $command;
            return $command;
        }

        $backend = self::$Commvault->BackendStorage();

        if($output == "csv"){
            foreach($backend as $cli => $backups){
                if(!array_key_exists($cli, $clients)){ continue; } // HACK
                foreach($backups as $backup){
                    $app = self::parserSize($backup['ApplicationSize'] ."GB");
                    $backend = self::parserSize($backup['BackendSize'] ."GB");

                    $show = [
                        $clients[$cli],
                        $cli,
                        $app,
                        $backend,
                        $backup['Agent'],
                        $backup['StoragePolicy'],
                        $backup['SubClient'],
                    ];
                    echo implode(";", $show) ."\n";
                }
            }
        }elseif($output == "json"){
            echo json_encode($backend, JSON_PRETTY_PRINT);
        }else{
            $output = array();
            foreach($backend as $cli => $backups){
                foreach($backups as $backup){
                    $output[] = [
                        $clients[$cli],
                        $cli,
                        $backup['ApplicationSize'] ." GB",
                        $backup['BackendSize'] ." GB",
                        $backup['Agent'],
                        $backup['StoragePolicy'],
                        $backup['SubClient'],
                    ];
                }
            }

            $spacer = [0,0,0,0,0,0,0];
            foreach($output as $r){
                foreach($r as $k => $v){
                    if(strlen($v)+1 > $spacer[$k]){
                        $spacer[$k] = strlen($v)+1;
                    }
                }
            }

            foreach($output as $r){
                foreach($r as $k => $v){
                    echo str_pad($v, $spacer[$k]);
                }
                echo "\n";
            }
        }
    }

    private function clientgroup_backend($cgobj, $output = NULL){
        $clis = array();
        foreach($cgobj->clientGroupDetail->associatedClients as $cli){
            $clis[] = strval($cli['clientName']);
        }

        $backend = self::$Commvault->BackendStorage();
        $sizes = array();
        foreach($clis as $cli){
            $sizes[$cli] = NULL;
            if(!isset($backend[$cli])){ continue; }
            $sizes[$cli] = [
                array_sum(array_column($backend[$cli], 'ApplicationSize')),
                array_sum(array_column($backend[$cli], 'BackendSize'))
            ];
        }

        return self::backend_output($sizes, $output);
    }

    private function client_backend($cliname, $output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }
        if($output == "xml"){
            $command = self::$Commvault->QCommand("qoperation execscript -sn BackendStorage -si @i_backupType='All'");
            $command = str_replace("/><", "/>\n<", $command);
            $command = explode("\n", $command);
            $command = preg_grep("/$cliname/", $command);
            $command = implode("\n", $command);
            echo $command ."\n";
            return $command;
        }

        $backend = self::$Commvault->BackendStorage();
        if(!isset($backend[$cliname])){
            echo self::$Lang['error_client_no_jobs'];
            return FALSE;
        }

        foreach($backend[$cliname] as $row){
            echo $row['BackendSize'] ." " .$row['ApplicationSize'] ."\n";
        }
    }

    private function clients_backend($output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }
        if($output == "xml"){
            $command = self::$Commvault->QCommand("qoperation execscript -sn BackendStorage -si @i_backupType='All'");
            echo $command;
            return $command;
        }

        $backend = self::$Commvault->BackendStorage();
        $sizes = array();
        foreach($backend as $cli => $data){
            $sizes[$cli] = [
                array_sum(array_column($backend[$cli], 'ApplicationSize')),
                array_sum(array_column($backend[$cli], 'BackendSize'))
            ];
        }

        return self::backend_output($sizes, $output);
    }

    private function backend_output($sizes, $output = NULL){
        $output = strtolower($output);

        if($output === TRUE){ return $sizes; }
        elseif($output == "csv"){
            foreach($sizes as $cli => $data){
                $app = self::parserSize($data[0] ." GB");
                $backend = self::parserSize($data[1] ." GB");
                echo "$cli;$app;$backend\n";
            }
        }elseif($output == "total"){
            $total = self::parserSize(array_sum(array_column($sizes, 1)) ." GB");
            echo $total ."\n";
            return $total;
        }elseif(in_array($output, ["app", "application"])){
            $app = self::parserSize(array_sum(array_column($sizes, 0)) ." GB");
            echo $app ."\n";
            return $app;
        }elseif(in_array($output, ["totalwithapp", "totalapp", "total-with-app"])){
            $app = self::parserSize(array_sum(array_column($sizes, 0)) ." GB");
            $backend = self::parserSize(array_sum(array_column($sizes, 1)) ." GB");

            echo "$app $backend\n";
            return [$app, $backend];
        }elseif($output == "csv"){
            foreach($sizes as $cli => $data){
                $app = self::parserSize($data[0] ." GB");
                $backend = self::parserSize($data[1] ." GB");
                echo "$cli;$app;$backend\n";
            }
        }else{ // if text or empty
            $str = "";
            $sep = 1;
            foreach($sizes as $cli => $data){
                if(strlen($cli) > $sep){ $sep = strlen($cli); }
            }
            foreach($sizes as $cli => $data){
                $app = number_format($data[0], 2, ".", "");
                $backend = number_format($data[1], 2, ".", "");
                $str .= str_pad($cli, $sep) ." " .str_pad($app, 11, " ", STR_PAD_LEFT) ." / " .str_pad($backend, 8, " ", STR_PAD_LEFT) ." GB" ."\n";
            }
            $app = number_format(array_sum(array_column($sizes, 0)), 2, ".", "");
            $backend = number_format(array_sum(array_column($sizes, 1)), 2, ".", "");
            $str .= str_pad("TOTAL", $sep) ." "
                .str_pad($app, 11, " ", STR_PAD_LEFT) ." / "
                .str_pad($backend, 8, " ", STR_PAD_LEFT) ." GB"
            ."\n";
            echo $str;
            return $str;
        }
    }

    private function clients_assoc($output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $clients = self::$Commvault->getClient();
        $cgs = self::$Commvault->getClientGroup();

        $assoc = array();

        foreach($cgs as $id => $name){
            $vals = self::$Commvault->getClientGroupClients($id);
            if($vals){ $vals = array_keys($vals); }
            $assoc[$id] = $vals;
        }

        if($output === TRUE){
            return $assoc;
        }elseif($output == "csv"){
            foreach($assoc as $cg => $clis){
                foreach($clis as $cli){
                    if(!array_key_exists($cli, $clients)){ continue; }
                    echo $cgs[$cg] .";" .$clients[$cli] ."\n";
                }
            }
        }elseif($output == "id"){
            foreach($assoc as $cg => $clis){
                foreach($clis as $cli){
                    if(!array_key_exists($cli, $clients)){ continue; }
                    echo $cg .";" .$cli ."\n";
                }
            }
        }else{
            foreach($assoc as $cg => $clis){
                echo $cgs[$cg] ."\n";
                foreach($clis as $cli){
                    if(!array_key_exists($cli, $clients)){ continue; }
                    echo "\t" ."#$cli - " .$clients[$cli] ."\n";
                }
            }
        }
    }

    public function clientgroups($output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
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
            echo self::$Lang['error_token'];
            return FALSE;
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
        }elseif($search == "drives"){
            return self::library_drives($extra);
        }elseif($extra == "jobs" and !empty($search)){
            return self::library_jobs($search);
        }elseif(empty($search)){
            $libraries = self::$Commvault->getLibrary();
            return self::generic_export_keyval($libraries, $extra);
        }

        $lib = self::$Commvault->getLibrary($search);
        if(!$lib){
            echo self::$Lang['error_library_exist'] ."\n";
            return FALSE;
        }
        $mls = $lib->libraryInfo->magLibSummary;

        if($extra == "size"){
            $drives = self::$Commvault->getLibraryDrives($lib);

            $str = self::size_describe(
                $lib->libraryInfo->library['libraryName'],
                self::parserSize($mls['totalFreeSpace']),
                self::parserSize($mls['totalCapacity'])
            );

            $str .= "\n";
            foreach($drives as $drive){
                $free = intval($drive->mountPathSummary['totalFreeSpace']) * 1024 * 1024;
                $total = intval($drive->mountPathSummary['totalSpace']) * 1024 * 1024;

                $str .= self::size_describe(
                    $drive->mountPathSummary['libraryName'],
                    $free,
                    $total
                ) ."\n";
            }
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

    private function size_describe($title, $free, $total){
        $used = $total - $free;
        $str = strval($title) ."\n"
                .str_pad(self::$Lang['bytes_free'] .":", 20) .self::parserSize($free, "GB") ." GB - " .number_format($free / $total * 100, 2) ."%\n"
                .str_pad(self::$Lang['bytes_used'] .":", 20) .self::parserSize($used, "GB") ." GB - " .number_format($used / $total * 100, 2) ."%\n"
                .str_pad(self::$Lang['bytes_total'] .":", 20) .self::parserSize($total, "GB")  ." GB\n"
                ."[" .self::progressbar($used, $total, 28) ."] " .round($used/$total * 100)   ."%\n";
        return $str;
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
            $data['percent'] = 0;
            if($data['capacity'] > 0){ // Avoid DIV 0.
                $data['percent'] = (float) number_format(($data['used'] / $data['capacity']) * 100, 2);
            }
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

    private function library_drives($output = "text"){
        if(empty($output)){ $output = "text"; }

        $libs = self::$Commvault->getLibrary();
        $drivesId = array();
        $drives = array();
        $drivesParsed = array();

        $c = 1;
        foreach($libs as $lib){
            if(in_array($output, ["text", "bar"])){
                echo "\r[" . self::progressbar($c++, count($libs), 24) ."]";
            }
            $libInfo = self::$Commvault->getLibrary($lib);
            foreach($libInfo->libraryInfo->MountPathList as $mpl){
                $drivesId[] = intval($mpl['mountPathId']);
            }
        }
        $drivesId = array_unique($drivesId);
        sort($drivesId);

        $c = 1;
        foreach($drivesId as $id){
            if(in_array($output, ["text", "bar"])){
                echo "\r[" . self::progressbar($c++, count($drivesId), 24) ."]";
            }
            $drives[$id] = self::$Commvault->getDriveController($id);
        }
        if(in_array($output, ["text", "bar"])){
            echo "\r" .str_pad(" ", 28) ."\r";
        }

        $spacer = 0;
        foreach($drives as $id => $drive){
            $data = [
                'id' => $id,
                'name' => strval($drive->mountPathSummary['libraryName']),
                'free' => intval($drive->mountPathSummary['totalFreeSpace']) * 1024 * 1024,
                'total' => intval($drive->mountPathSummary['totalSpace']) * 1024 * 1024
            ];
            $data['used'] = $data['total'] - $data['free'];
            $data['percent'] = number_format($data['used'] / $data['total'] * 100, 2);

            $drivesParsed[$id] = $data;

            if(strlen($data['name']) > $spacer){
                $spacer = strlen($data['name']);
            }
        }
        $spacer++;

        if($output == "text"){
            foreach($drivesParsed as $id => $drive){
                echo str_pad($drive['name'], $spacer + 4)
                    .str_pad($drive['percent'], 5, " ", STR_PAD_LEFT) ."% - "
                    .str_pad(self::parserSize($drive['free'], "GB"), 8, " ", STR_PAD_LEFT) ." / "
                    .str_pad(self::parserSize($drive['total'], "GB"), 8, " ", STR_PAD_LEFT) ." GB\n";
            }
        }elseif(in_array($output, ["bar", "progress", "progressbar"])){
            foreach($drivesParsed as $id => $drive){
                echo str_pad($drive['name'], $spacer)
                    ."[" . self::progressbar($drive['used'], $drive['total'], 24) ."] "
                    .number_format($drive['used'] / $drive['total'] * 100, 2) ."% - "
                    .self::parserSize($drive['free'], "GB") ." GB"
                 ."\n";
            }
        }elseif($output == "csv"){
            foreach($drivesParsed as $id => $drive){
                $display = [
                    $id, ('"' .$drive['name'] .'"'),
                    $drive['free'], $drive['used'],
                    $drive['total'], round($drive['percent'])
                ];
                echo implode(";", $display) ."\n";
            }
        }elseif($output == "json"){
            echo json_encode($drivesParsed, JSON_PRETTY_PRINT) ."\n";
        }
    }

    private function library_jobs($libname, $output = "text"){
        if(is_numeric($libname)){
            $lib = self::$Commvault->getLibrary($libname);
            if(!$lib or isset($lib['errorCode'])){
                echo self::$Lang['error_library_exist'] ."\n";
                return;
            }
            $libname = strval($lib->libraryInfo->library['libraryName']);
        }
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
            echo implode("\n", $jobstxt) ."\n";
            return NULL;
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
                'deviceName'        => @$devn[$data[6]], // Posible undefinex index
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
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $actions = ["kill", "resume", "suspend", "pause"];

        if(in_array($client, $actions)){
            return self::jobs_action($client);
        }elseif(in_array($client, ["count", "summary"])){
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
        }elseif(empty($client)){
            $jobs = self::$Commvault->QCommand("qoperation execscript -sn GetAllRunningJobs");
            if($jobs === FALSE){
                echo self::$Lang['error_token'] ."\n";
                return FALSE;
            }

            $xml = simplexml_load_string($jobs);

            // ID, Operation, Name, Agent, subclient, jobtype,
            // phase, storage, media, status, progress, errors, delay

            $jobs = array();

            foreach($xml->FieldValue as $job){
                $data = array();
                foreach($job->attributes() as $k => $v){
                    $data[$k] = strval($v);
                }
                $jobs[$data['jobID']] = $data;
            }

            ksort($jobs);
            $jobs = array_reverse($jobs); // Nuevos primero

            foreach($jobs as $job){
                $jobtype = $job['OperationType'];
                $jobphase = $job['currentPhaseName'];

                if(!empty($job['JobType']) and strtoupper($job['JobType']) != "NULL"){
                    if(!in_array($job['JobType'], ['Synthetic Full'])){
                        $jobtype .= " " .$job['JobType'];
                    }
                    if($job['JobType'] == "Incremental" and $jobphase == "Differential Backup"){
                        $jobphase = "Differential";
                    }
                }

                $date = date("d/m H:i", $job['jobStartTime']);
                if(
                    (strtoupper($job['StateName']) == "PENDING" and intval($job['percentcomplete']) == 5) or
                    intval($job['percentcomplete']) == 0
                ){
                    self::console_color("yellow");
                }
                echo str_pad($job['jobID'], 8)
                    .str_pad(strtoupper($job['StateName']), 10)
                    .str_pad($jobtype, 19)
                    .str_pad("- " .$jobphase, 20)
                    .str_pad($job['clientName'], 30)
                    .$date . " "
                    ."[" .self::progressbar($job['percentcomplete'], 100, 8) ."] ";

                if(intval($job['percentcomplete']) > 0){
                    echo str_pad($job['percentcomplete'] ."%", 3, "0", STR_PAD_LEFT);
                }

                if(
                    (strtoupper($job['StateName']) == "PENDING" and intval($job['percentcomplete']) == 5) or
                    intval($job['percentcomplete']) == 0
                ){
                    self::console_color("reset");
                }

                echo "\n";
            }
        }
    }

    private function console_color($color, $retcol = FALSE){
        if(is_string($color)){ $color = strtolower($color); }
        if(empty($color) or $color === FALSE){
            $str = "\033[0m";
            if($retcol){ return $str; }
            echo $str;
            return TRUE;
        }

        $colors = [
            'green' => '0;32',
            'yellow' => '1;33',
            'black' => '0;30',
            'darkgray' => '1;30',
            'red' => '0;31',
            'lightred' => '1;31',
            'green' => '0;32',
            'lightgreen' => '1;32',
            'brownorange' => '0;33',
            'blue' => '0;34',
            'lightblue' => '1;34',
            'purple' => '0;35',
            'lightpurple' => '1;35',
            'cyan' => '0;36',
            'lightcyan' => '1;36',
            'lightgray' => '0;37',
            'white' => '1;37',

            'normal' => '0',
            'reset' => '0',
            'bold' => '1',
            'underline' => '4',
            'blink' => '5',
        ];

        if(!in_array($color, array_keys($colors))){
            return ($retcol ? NULL : FALSE);
        }
        $str = "\033[" .$colors[$color] ."m";
        if($retcol){ return $str; }
        echo $str;
        return TRUE;
    }

    public function job($jobid, $output = NULL){
        if(empty($jobid)){
            return self::help("job");
        }

        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $actions = ["kill", "resume", "suspend", "pause"];

        if($jobid == "all" and in_array($output, $actions)){
            return self::jobs_action($output);
        }

        $job = self::$Commvault->getJob($jobid);
        if(!isset($job->jobSummary)){
            echo self::$Lang['job_not_exist'] ."\n";
            return NULL;
        }

        $job = $job->jobSummary;
        if($output == "xml"){
            echo $job->asXML() ."\n";
        }elseif(in_array($output, $actions)){
            return self::job_action($jobid, $output);
        }else{
            echo "JOB #" .$job['jobId'] ." - " .$job['percentComplete'] ."% " .$job['status'] ."\n"
                ."CLI #" .$job->subclient['clientId'] ." - " .$job->subclient['clientName'] ."\n"
                .$job['jobType']." " .$job['backupLevelName']." " .$job['appTypeName'] ."\n";
                if(intval($job['jobStartTime']) > 0){
                    echo date("d/m/y H:i", intval($job['jobStartTime']));
                }
                if(intval($job['lastUpdateTime']) > 0){
                    echo " - " .date("d/m/y H:i", intval($job['lastUpdateTime']));
                }
                if(intval($job['jobElapsedTime']) > 0){
                    $time = intval($job['jobElapsedTime']);
                    $days = "";
                    if($time > 86400){ $days = floor($time / 86400) ."d "; }
                    echo " ($days" .gmdate("H:i:s", $time) .")";
                }
                echo "\n";

            if($job['jobType'] == "Backup"){
                if(intval($job['sizeOfApplication']) > 0){
                    echo self::parserSize($job['sizeOfApplication'], "GB") ." GB -> "
                        .self::parserSize($job['sizeOfMediaOnDisk'], "GB") ." GB (-"
                        .number_format(floatval($job['percentSavings']), 2) ."%)"
                        ."\n";
                }

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
                if(!empty($files)){
                    echo implode(", ", $files) ."\n";
                }
            }

            if(isset($job['pendingReason']) and !empty($job['pendingReason'])){
                echo strip_tags(strval($job['pendingReason']));
            }
            echo "\n";
        }
    }

    private function job_action($jobid, $action){
        if($action == "suspend"){ $action = "pause"; }
        $res = self::$Commvault->jobAction($jobid, $action);
        if($res !== TRUE){
            echo self::$Lang['error_job_action'] ." [$res]" ."\n";
            return FALSE;
        }
    }

    private function jobs_action($action){
        if($action == "suspend"){ $action = "pause"; }
        if(!self::confirm_user()){ return FALSE; }
        $res = self::$Commvault->QCommand("qoperation jobcontrol -all -o $action");
        echo $res ."\n"; // TODO Debug output
    }

    public function execute($file = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        if(empty($file)){
            echo self::$Lang['error_execute_no_file'];
            return FALSE;
        }

        if(!file_exists($file) or !is_readable($file)){
            echo self::$Lang['error_execute_file_exists'];
            return FALSE;
        }

        $output = self::$Commvault->QOperation($file);
        $xml = self::parseXml($output);
        if(is_array($xml) and array_key_exists('jobs', $xml)){
            echo self::$Lang['execute_jobs_created'] .' ' . implode(", ", $xml['jobs']) ."\n";
        }else{
            echo $output;
        }
        return $output;
    }

    private function parseXml($string){
        $xml = @simplexml_load_string($string);
        if($xml === FALSE){ return FALSE; }
        if($xml->getName() == "TMMsg_CreateTaskResp"){
            $taskId = $xml['taskId']; // should get it?
            $jobs = array();
            foreach($xml->jobIds as $job){
                $jobs[] = intval($job['val']);
            }
            // if(count($jobs) == 1){ return current($jobs); }
            if(count($jobs) > 0){ return ['jobs' => $jobs]; }
            return FALSE;
        }
        return $xml;
    }

    public function storagepolicy($MA = NULL, $output = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
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
        self::$Commvault->limit = 1000;
        $jobs = self::$Commvault->getClientJobs($clientid);
        if(empty($jobs)){
            echo self::$Lang['error_client_no_jobs'];
            return FALSE;
        }

        ksort($jobs); // Sort array

        $status = array();
        foreach($jobs as $job){
            if(!isset($status[$job->status])){ $status[$job->status] = 0; }
            $status[$job->status]++;
        }

        $amount = min(10, count($jobs)) - 1;
        $lastjobs[] = end($jobs); // Mover al final y coger uno
        for($i = 0; $i < $amount; $i++){
            $lastjobs[] = prev($jobs);
        }

        $lastjobs = array_reverse($lastjobs); // Nuevos al final
        $spacer = 0;
        foreach($lastjobs as $job){
            $tmp = "$job->jobType $job->backupLevelName $job->appTypeName";
            if(strlen($tmp) > $spacer){
                $spacer = strlen($tmp);
            }
        }
        $spacer++;

        foreach($lastjobs as $job){
            echo str_pad($job->jobId, 8)
                .date("d/m H:i", $job->jobStartTime)
                ."  " .str_pad("$job->jobType $job->backupLevelName $job->appTypeName", $spacer)
                .$job->status
            ."\n";
        }
        echo "\n";

        foreach($status as $name => $amount){
            echo str_pad($name, 34) .$amount ."\n";
        }

        $lastjob = max(array_keys($jobs));
        if($lastjob){
            echo "\n";
            return self::client_lastjob($jobs[$lastjob]);
        }
    }

    public function usersandgroups($export = "text"){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $r = self::$Commvault->getUsersAndGroups();
        if(!isset($r->users)){
            echo self::$Lang['error_client_no_jobs']; // TODO
            return FALSE;
        }
        foreach($r->users as $user){
            echo
                str_pad("#" .$user->associatedUserOrUserGroup['userId'], 6) ."- "
                .str_pad($user->associatedUserOrUserGroup['userName'], 26)
                .$user['fullName']
            ."\n";
        }
        echo "\n\n";
        foreach($r->userGroups as $group){
            echo
                str_pad("#" .$group->associatedUserOrUserGroup['userGroupId'], 6) ."- "
                .$group->associatedUserOrUserGroup['userGroupName']
            ."\n";
        }
    }

    public function user($search = NULL, $extra = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        if(empty($search)){
            return FALSE;
        }

        $user = self::$Commvault->getUser($search);
        if(!$user){
            echo self::$Lang['error_client_not_found'];
            return FALSE;
        }

        var_dump($user);
        $name = trim(strval($user->users['fullName']));
        if($name){ $name = " $name"; }
        $str =
            "#" .intval($user->users->userEntity['userId']) .$name ." - " .strval($user->users->userEntity['userName']) ." "
                .(intval($user->users['enableUser']) ? "" : "BLOCKED ")
                .(intval($user->users['loggedInMode']) ? "LOGIN " : "")
                ."(" .date("Y-m-d H:i", intval($user->users['lastLogIntime'])) .")\n"

                .strval($user->users['email']) ."\n"
                .strval($user->users['description']) ."\n";
        $str .= "\n";
        // xml, text, groups, roles, delete, apps, settings
        echo $str;
    }

    private function users_groups_belong($asArray = FALSE){
        $users = self::$Commvault->getUsersAndGroups(TRUE);
        $ret = array();
        foreach($users['user'] as $id => $user){
            $userinfo = self::$Commvault->getUser($id);
            $groups = array();
            foreach($userinfo->users->associatedUserGroups as $group){
                $groups[] = strval($group['userGroupName']);
            }

            $ret[$user] = $groups;
            if(!$asArray){
                echo $user .";" .implode(", ", $groups) ."\n";
            }
        }
        return $ret;
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
            .date("d/m/y H:i", $job->jobStartTime);
            if(intval($job->lastUpdateTime) > 0){
                echo " - " .date("d/m/y H:i", $job->lastUpdateTime);
            }
            if(intval($job->jobElapsedTime) > 0){
                echo " (";
                if(intval($job->jobElapsedTime) > 86400){
                    echo floor($job->jobElapsedTime / 86400) ."d ";
                }
                echo gmdate("H:i:s", $job->jobElapsedTime) .")";
            }
            echo "\n";
        if(isset($job->pendingReason)){
            echo $job->pendingReason ."\n";
        }
        echo "\n";
    }

    private function client_lastfull($clientid){
        self::$Commvault->limit = 10000;
        $jobs = self::$Commvault->getClientJobs($clientid);

        $last = array();
        foreach($jobs as $job){
            if(
                stripos($job->backupLevel, "Full") === FALSE or
                trim($job->status) != "Completed"
            ){ continue; }
            $key = "$job->jobType $job->backupLevelName $job->appTypeName $job->subclientName";
            $index = md5($key);
            if(
                !array_key_exists($index, $last) or
                (
                    array_key_exists($index, $last) and
                    intval($last[$index]->jobEndTime) < intval($job->jobEndTime)
                )
            ){
                $last[$index] = $job;
            }

        }

        if(empty($last)){
            echo self::$Lang['error_no_full_backups_found'];
            return FALSE;
        }

        $max = 0;
        foreach($last as $job){
            $time = intval($job->jobEndTime);
            if($time > $max){ $max = $time; }

            echo $job->jobId .' ' .date("Y-m-d H:i", $job->jobEndTime) .' '
                ."$job->jobType $job->backupLevelName $job->appTypeName $job->subclientName" ."\n";
        }

        return $max;
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
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $extra = $client;
        $posible = [
            "job", "lastid", "full", "monitor",
            "info", "minor", "major", "critical"
        ];

        if(in_array($client, $posible)){ $client = NULL; }
        if(in_array($filter, $posible)){ $extra = $filter; }

        // -------

        if(in_array($extra, ["full", "complete", "all"])){
            return self::log_full($client);
        }elseif($extra == "monitor"){
            self::log(); // Show current logs
            $last = self::log("lastid");
            if(!empty($filter) and !is_numeric($filter)){
                $filter = 5;
            }
            self::log_monitor($last, $filter);
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
            echo intval($event['id']) ."\n";
            return intval($event['id']);
        }else{
            echo self::events_output($eventfinal, "text");
        }
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

    private function log_monitor($lastid, $sleep = 5){
        $events = self::events_get();
        foreach($events as $event){
            if($event['id'] > $lastid){
                $lastid = $event['id'];
                echo self::events_output([$event], "text");
            }
        }
        sleep($sleep);
        unset($events); // Optimize RAM
        self::log_monitor($lastid, $sleep);
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
                $severity = self::log_severity($event["severity"]);
                $severity_col = "reset";
                if($severity == "MAJOR"){ $severity_col = "bold"; }
                if($severity == "CRITICAL"){ $severity_col = "lightred"; }
                if(isset($event['jobId'])){ $id = $event['jobId']; }
                $str .= date("M d H:i:s", intval($event['timeSource'])) ." " .strval($event['clientName']) ." "
                    .$event['subsystem'] ."[$id]: " .self::console_color($severity_col, TRUE) .$severity ." "
                    .$event["description"] .self::console_color('reset', TRUE)
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
            if(isset(self::$Config['username'])){
                $username = self::$Config['username'];
                echo self::$Lang['input_username'] ." $username\n";
            }else{
                $username = readline(self::$Lang['input_username'] ." ");
            }
        }

        if(empty($password)){
            if(isset(self::$Config['password'])){
                $password = base64_decode(self::$Config['password']);
            }else{
                echo self::$Lang['input_password'] ." ";

                system('stty -echo');
                $password = trim(fgets(STDIN));
                system('stty echo');

                echo "\n";
            }
        }

        self::$Commvault->username = $username;
        self::$Commvault->password = $password;
        self::$Commvault->url = $host;

        echo self::$Lang['login_connecting'] ."\r";

        $login = self::$Commvault->login();
        if($login !== TRUE){
            return FALSE;
        }

        $token = trim(substr(self::$Commvault->getToken(), 4)) ."0";
        $token = base64_encode(hex2bin($token));

        self::$Config['url'] = $host;
        self::$Config['token'] = $token;

        if(isset(self::$Config['password'])){
            // HACK for Ini file and B64 encoding
            self::$Config['password'] = '"' .self::$Config['password'] .'"';
        }

        $conf = "";
        $spacer = 8;
        foreach(self::$Config as $field => $val){
            if(strlen($field) > $spacer){
                $spacer = strlen($field);
            }
        }
        $spacer = ($spacer + 2);
        foreach(self::$Config as $field => $val){
            $conf .= str_pad($field, $spacer) ."= $val\n";
        }

        $path = self::$ConfigFile;
        if(!is_dir(dirname($path))){
            mkdir(dirname($path) .'/', 0770, TRUE);
        }
        file_put_contents($path, $conf);
        chmod($path, 0600);

        if(file_exists($path) and is_readable($path)){
            echo self::$Lang['login_ok'] ."\n";
        }
    }

    public function logout(){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        $query = self::$Commvault->logout();
        unlink(self::$ConfigFile);
    }

    public function console(){
        $exit = ["exit", "quit", "close"];
        $qcoms = [
            "qcreate", "qdelete", "qmodify", "qlist",
            "qmedia", "qinfo", "qoperation", "qdrive"
        ];
        $command = NULL;
        while(!in_array($command, $exit)){
            $argv = trim(readline("> "));
            if(empty($argv)){ continue; } // Empty enter
            $command = explode(" ", $argv);
            $callback = $command;

            if(is_array($command)){
                array_shift($callback); // Remove command
                $command = $command[0];
            }

            if(in_array($command, $exit)){ continue; }
            if(in_array($command, $qcoms)){
                echo self::command($argv) ."\n";
                continue;
            }
            if(!method_exists("App", $command)){
                echo self::$Lang['unknown_command'] ."\n";
                continue;
            }

            $r = new ReflectionMethod("App", $command);
            if(!$r->isPublic()){
                echo self::$Lang['unknown_command'] ."\n";
                continue;
            }

            call_user_func_array(array("App", $command), $callback);
        }
    }

    private function command($text = NULL){
        if(!self::load_token()){
            echo self::$Lang['error_token'];
            return FALSE;
        }

        if(is_array($text)){ $text = implode(" ", $text); }
        return self::$Commvault->QCommand($text);
    }

    private function confirm_user(){
        $confirm = NULL;
        while($confirm === NULL){
            $confirm = readline(self::$Lang['user_confirm']);
            $confirm = strtolower($confirm);
            $yes = self::$Lang['user_confirm_yes'];
            $no = self::$Lang['user_confirm_no'];
            if(
                $confirm == $yes or
                in_array($confirm, self::$Lang['user_confirm_yes_multi']) or
                substr($confirm, 0, 1) == substr($yes, 0, 1)
            ){
                $confirm = TRUE;
            }elseif(
                $confirm == $no or
                in_array($confirm, self::$Lang['user_confirm_no_multi']) or
                substr($confirm, 0, 1) == substr($no, 0, 1)
            ){
                $confirm = FALSE;
            }else{
                $confirm = NULL;
            }
        }
        return $confirm;
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
if(in_array($command, ["init", "?"]) or !method_exists("App", $command)){
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
