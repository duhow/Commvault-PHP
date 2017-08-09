<?php

class Commvault {
    private $token;
    public $url;
    public $username;
    public $password;
    public $debug = FALSE;
    public $limit = NULL;
    public $offset = NULL;

    public function __construct($username = NULL, $password = NULL, $url = NULL){
        if(!empty($username)){
            $this->login($username, $password, $url);
        }
    }

    public function login($username = NULL, $password = NULL, $url = NULL){
        if(empty($username) and empty($this->username)){ return FALSE; }
        if(empty($password) and empty($this->password)){ return FALSE; }
        if(empty($url) and empty($this->url)){ return FALSE; }

        if(empty($username)){ $username = $this->username; }
        if(empty($password)){ $password = $this->password; }
        if(empty($url)){ $url = $this->url; }

        // UPDATE
        $this->username = $username;
        $this->password = $password;
        $this->url = $url;

        $password = base64_encode($password);
        $query = "<DM2ContentIndexing_CheckCredentialReq username=\"$username\" password=\"$password\" />";

        $res = $this->query("Login", $query);

        if(isset($res->errList)){
            $str = "Error: " .strval($res->errList['errLogMessage']);
            error_log($str);
            return FALSE;
        }elseif(!isset($res['token'])){
            $str = "Error: Token not get";
            error_log($str);
            return FALSE;
        }

        $this->token = strval($res['token']);
        return TRUE;
    }

    public function logout(){
        if(empty($this->url) or empty($this->token)){ return FALSE; }
        $headers = array(
            "Accept: application/xml",
            "Authtoken: " .$this->token,
            "Content-Length: 0",
        );
        $ch = curl_init($this->url . "Logout");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, TRUE);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($result == "User logged out" or $http_code == 401){ return TRUE; }
        return $result;
    }

    public function getLibrary($id = NULL){
        // Get all storage media
        if(empty($id)){
            $storage = $this->query("Library");
            if(!$storage or empty($storage)){ return array(); }

            $final = array();
            foreach($storage->response as $st){
                $final[intval($st->entityInfo['id'])] = strval($st->entityInfo['name']);
            }
            ksort($final);
            return $final;
        }

        $storage = $this->query("Library/$id");
        if(!$storage or empty($storage)){ return array(); }
        return $storage;
    }

    public function detectTape($MA = NULL){
        if(is_array($MA)){ $MA = implode(",", $MA); }
        $body = '<CVGui_EZGetTapeLibrariesReq autoDetect="1">'
                .'<hdr tag="0" />'
                .'<mediaAgentIdList val="' .$MA .'" />'
                .'</CVGui_EZGetTapeLibrariesReq>';

        $query = $this->query("Library?Action=detect", $body);

        return $query;
    }

    public function getClient($id = NULL, $additional = FALSE){
        if(empty($id)){
            $clients = $this->query("Client");
            if(!$clients or empty($clients)){ return array(); }

            $final = array();
            foreach($clients->clientProperties as $cli){
                $id = intval($cli->client->clientEntity['clientId']);
                $name = strval($cli->client->clientEntity['clientName']);

                $final[$id] = $name;
            }
            ksort($final);

            return $final;
        }

        if(is_numeric($id)){
            $query = "Client/$id";
            if($additional){ $query .= "/AdditionalSettings"; }
            $client = $this->query($query);
        }else{
            $client = $this->query("Client/byName(clientName='$id')");
        }

        return $client;
    }

    public function getClientId($name){
        $cli = $this->getClient($name);
        if(!$cli or !isset($cli->clientProperties)){ return FALSE; }
        return intval($cli->clientProperties->client->clientEntity['clientId']);
    }

    // Lista de CG a los que pertenece un Cli
    public function getClientGroupsMember($id){
        $cli = $this->getClient($id);
        if(!$cli){ return FALSE; }

        $groups = array();
        foreach($cli->clientProperties->clientGroups as $group){
            $id = intval($group['clientGroupId']);
            $name = strval($group['clientGroupName']);
            $groups[$id] = $name;
        }
        ksort($groups);
        return $groups;
    }

    public function getClientJobs($id, $completed = FALSE){
        $jobs = $this->query("Job?clientId=$id"); // &jobFilter=backup

        $final = array();
        foreach($jobs->jobs as $job){
            if($completed and strval($job->jobSummary['status']) != "Completed"){
                continue;
            }
            $id = intval($job->jobSummary['jobId']);
            $data = array();
            foreach($job->jobSummary->attributes() as $k => $v){
                $v = strval($v);

                if($v == "false"){ $v = FALSE; }
                elseif($v == "true"){ $v = TRUE; }

                $data[$k] = $v;
            }
            $final[$id] = (object) $data;
        }

        return $final;
        // return $jobs->jobs;
    }

    public function getClientGroup($id = NULL){
        if(empty($id)){
            $groups = $this->query("ClientGroup");
            if(!$groups or empty($groups)){ return array(); }

            $final = array();
            foreach($groups->groups as $group){
                $id = intval($group['Id']);
                $name = strval($group['name']);
                $final[$id] = $name;
            }

            return $final;
        }elseif(is_string($id)){
            $k = $this->getClientGroupId($id);
            if($k === FALSE){ return FALSE; }
            return $this->getClientGroup($k);
        }

        $group = $this->query("ClientGroup/$id");
        return $group;
    }

    public function getClientGroupClients($id, $full = FALSE){
        if(!is_numeric($id)){ $id = $this->getClientGroupId($id); }
        $group = $this->query("ClientGroup/$id");

        if(!$group or !isset($group->clientGroupDetail)){ return FALSE; }
        if(isset($group['errorCode'])){
            echo "Error " .$group['errorCode'] .": " .$group['errorMessage'] ." - $id" ."\n";
        }
        $clis = array();
        foreach($group->clientGroupDetail->associatedClients as $cli){
            $id = intval($cli['clientId']);
            $name = strval($cli['clientName']);
            $host = strval($cli['hostName']);

            if($full){
                $clis[$id] = [
                    'clientName' => $name,
                    'hostName' => $host
                ];
            }else{
                $clis[$id] = $name;
            }
        }

        return $clis;
    }

    public function getClientGroupId($name){
        $groups = $this->getClientGroup(NULL);
        $k = array_search($name, $groups);
        return $k;
    }

    public function getJob($id){
        $job = $this->query("Job/$id");
        return $job->jobs;
    }

    public function getEvents($cli = NULL, $extra = array()){
        $data = array();
        if(!empty($cli)){
            if(!is_numeric($cli)){ $cli = $this->getClientId($cli); }
            if(is_numeric($cli)){ $data['clientId'] = $cli; }
        }

        if(!empty($extra) and is_array($extra)){
            foreach($extra as $k => $v){
                $data[$k] = $v;
            }
        }

        if(!empty($data)){
            $data = "?" .http_build_query($data);
        }else{
            $data = "";
        }
        $res = $this->query("Events" .$data);
        return $res;
    }

    public function getStoragePolicy($id = NULL){
        if(empty($id)){
            $query = $this->query("StoragePolicy");
            if(!$query){ return array(); }

            $policies = array();
            foreach($query->policies as $pol){
                $id = intval($pol['storagePolicyId']);
                $name = strval($pol['storagePolicyName']);
                $policies[$id] = $name;
            }

            return $policies;
        }elseif(is_string($id)){
            $pols = $this->getStoragePolicy(NULL);
            $k = array_search($id, $pols);
            if($k === FALSE){ return FALSE; }
            return $k; // STR -> ID
        }elseif(is_numeric($id)){
            $pols = $this->getStoragePolicy(NULL);
            if(isset($pols[$id])){ return $pols[$id]; } // ID -> STR
            return FALSE;
        }
    }

    public function getStoragePolicyMA($MA){
        $query = $this->query("StoragePolicyListAssociatedToMediaAgent?MediaAgent=$MA");
        if(!$query or !isset($query->storagePolicyInformationAssociatedToMA)){
            return array();
        }

        $storages = array();
        foreach($query->storagePolicyInformationAssociatedToMA as $st){
            $data = array();
            foreach($st->attributes() as $k => $v){
                if(strpos($k, "is") === 0){
                    $data[$k] = (bool) intval($v);
                }else{
                    $data[$k] = strval($v);
                }
            }
            $data['mediaAgent'] = intval($st->mediaAgent['mediaAgentId']);
            foreach($st->storagePolicyAndCopy->attributes() as $k => $v){
                $data[$k] = strval($v);
            }

            $storages[] = $data;
        }
        return $storages;
    }

    public function QCommand($command){
        if(empty($this->url)){ return FALSE; }
        $headers = array(
            "Accept: application/xml",
            'Content-Type: text/plain'
        );
        if(!empty($this->token)){ $headers[] = "Authtoken: " .$this->token; }
        if($this->limit != NULL){
            $headers[] = "limit: " .$this->limit;
            $this->limit = NULL;
        }
        if($this->offset != NULL){
            $headers[] = "offset: " .$this->offset;
            $this->offset = NULL;
        }
        $ch = curl_init($this->url . "QCommand");

        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $command);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        try {
            if($this->debug){ var_dump($result); }
            return $result;
        } catch (Exception $e) {
            return NULL;
        }
    }

    private function query($action, $data = NULL, $accept = "xml"){
        if(empty($this->url)){ return FALSE; }
        $headers = array(
            "Accept: application/$accept",
            'Content-Type: application/xml'
        );
        if(!empty($this->token)){ $headers[] = "Authtoken: " .$this->token; }
        if($this->limit != NULL){
            $headers[] = "limit: " .$this->limit;
            $this->limit = NULL;
        }
        if($this->offset != NULL){
            $headers[] = "offset: " .$this->offset;
            $this->offset = NULL;
        }
        $ch = curl_init($this->url . $action);
        if(!empty($data)){
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        try {
            if($http_code == 401){ throw new Exception("TOKENAUTH"); }
            $xml = @simplexml_load_string($result);
            if($this->debug){ var_dump($xml); }
            return $xml;
        } catch (Exception $e) {
            if($e->getMessage() == "TOKENAUTH"){
                echo "Token not authorized.\n";
            }else{
                echo "Not XML: $result";
            }
            die();
        }
    }

    public function getToken(){
        return $this->token;
    }

    public function setToken($token){
        if(substr($token, 0, 4) != "QSDK"){
            $token = "QSDK " .trim($token);
        }

        $this->token = $token;
        return $this;
    }
}

?>
