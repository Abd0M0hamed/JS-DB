<?php
/*
* Project: JS Database. 
* Description: Simple backend application for Javascript Projects.
* Author: Abdelrahman Helaly
* Contact: AH3laly@gmail.com
* Contact: https://Github.com/AH3laly
* License: Science not for Monopoly.
*/

namespace JSDB;

if(!defined("JSDB_ROOT")) die("Invalid Request");

header("Access-Control-Allow-Origin: *");
define("JSDB_DATA", JSDB_ROOT."/var/data/");
define("JSDB_LOG", JSDB_ROOT."/var/log/");

class Config {
    private static $configuration = [
        
        /**
         * Display all errors and warnings
         */
        "debug_mode" => 0,
        /**
         * Execute all commands on testing database "test.jsdb"
         */
        "testing_mode" => 0,
        
        // Enable or disable Logging
        "logging" => 0,
        /**
         * Basic commands are (select, insert, update, delete)
         * If basic commands are disabled, then only custom actions defined in custom.php will be allowed
         */
        "allow_basic_commands" => 0,
        
        // Tables in db_write_protected_tables are not readable anyway
        "db_read_protected_tables" => ["__jsdb_core"],
        
        // Tables in db_write_protected_tables are not writable anyway
        "db_write_protected_tables" => ["__jsdb_core"],

        // Domains allowed to hit the API (Referers)
        "allowed_domains" => []
    ];
    public static function isDebugMode(){
        return self::get("debug_mode");
    }
    public static function isTestingMode(){
        return self::get("testing_mode");
    }
    public static function isReadProtectedTable($table){
        return in_array($table, self::$configuration["db_read_protected_tables"]);
    }
    public static function isWriteProtectedTable($table){
        return in_array($table, self::$configuration["db_write_protected_tables"]);
    }
    public static function get($key){
        return self::$configuration[$key];
    }
    public static function set($key, $value){
        self::$configuration[$key] = $value;
    }
}

class Core {
    public $response;
    public $logger;
    public function __construct(){
        $this->response = new Response();
    }
    protected function initialize(){
        $this->logger = new Logger();
    }
}

class Session {
    private static function initialize(){
        if(!session_id()){
            session_start();
        }
        if(!isset($_SESSION["JSDBDATA"])){
            $_SESSION["JSDBDATA"] = [];
        }
    }
    public static function set($key, $value){
        self::initialize();
        $_SESSION["JSDBDATA"][$key] = $value;
    }
    public static function get($key){
        self::initialize();
        return isset($_SESSION["JSDBDATA"][$key]) ? $_SESSION["JSDBDATA"][$key] : false;
    }
}

class Security {
    private static function isIpAddress($ip){
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        return false;
    }
    private static function validateReferer(){
        if(!isset($_SERVER['HTTP_REFERER'])){
            throw new \Exception("Unauthorized Access");
        }
        $refererUrlInfo = parse_url($_SERVER['HTTP_REFERER']);
        if(!Config::isTestingMode()){
            if(!isset($refererUrlInfo['scheme']) || $refererUrlInfo['scheme'] != "https"){
                throw new \Exception("Secure connection is Must");
            }
            if(self::isIpAddress($refererUrlInfo['host'])){
                // We don't accept request from referers as IP, Must be a Domain name.
                throw new \Exception("Unauthorized Access");
            }
            if(!in_array($refererUrlInfo['host'], Config::get("allowed_domains"))){
                // Referer Hostname must be in the allowed_domains array
                throw new \Exception("Unauthorized Access");
            }
            // Make sure it's a valid and public host name
            $refererIps = gethostbynamel($refererUrlInfo['host']);
            if(!is_array($refererIps) || count($refererIps) == 0){
                throw new \Exception("Unauthorized Access");
            }
        }
        
        return $refererUrlInfo;
    }
    public static function authenticate(){
        // Validate Referer
        $referer = self::validateReferer();
        if(!Config::isTestingMode()){
            // Require SSL
            if(strtolower($_SERVER["REQUEST_SCHEME"]) != "https"){
                throw new \Exception("Unauthorized Access");
            }
        }
        // Create Authentication Session 
        Session::set("authInfo", [
            "createdAt" => microtime(true), 
            "validForIP" => $_SERVER["REMOTE_ADDR"]
        ]);

        header("Access-Control-Allow-Origin: ".$referer['scheme']."://".$referer['host']);
        return true;
    }
    public static function isAuthenticated(){
        if(!$authInfo = Session::get("authInfo")){
            return false;
        }
        if(!isset($authInfo["validForIP"]) || !isset($authInfo["createdAt"]) || empty($authInfo["validForIP"]) || empty($authInfo["createdAt"])){
            return false;
        }
        if(!isset($_SERVER["REMOTE_ADDR"]) || $authInfo["validForIP"] != $_SERVER["REMOTE_ADDR"]){
            return false;
        }
        if(microtime(true) - $authInfo["createdAt"] > 3600){
            // Session Is not valid after 3600 Seconds (1 Hour)
            return false;
        }
        return true;
    }
}

class Logger {
    private static $errorFile = null;
    private static $infoFile = null;
    private static $apiFile = null;
    private static function open($file){
        switch($file){
            case "error":
                if(!self::$errorFile){
                    if(!self::$errorFile = fopen(JSDB_LOG . "errors.log", "a")){
                        throw new \Exception("Error opening file errors.log");
                    }
                }
            break;
            case "info":
                if(!self::$infoFile){
                    if(!self::$infoFile = fopen(JSDB_LOG . "info.log", "a")){
                        throw new \Exception("Error opening file info.log");
                    }
                }
            break;
            case "api":
                if(!self::$apiFile){
                    if(!self::$apiFile = fopen(JSDB_LOG . "api.log", "a")){
                        throw new \Exception("Error opening file api.log");
                    }
                }
            break;
        }
    }
    private static function write($hwnd, $data){
        if(!fwrite($hwnd, date("Y-m-d H:i (D)") .": ". $data . "\r\n")){
            throw new \Exception("Error writing to log files.");
        }
    }
    public static function logError($data){
        self::open("error");
        self::write(self::$errorFile, $data);
    }
    public static function logInfo($data){
        self::open("info");
        self::write(self::$infoFile, $data);
    }
    public static function logApi($data){
        self::open("api");
        self::write(self::$apiFile, $data);
    }
    public static function close(){
        if(self::$errorFile){
            fclose(self::$errorFile);
        }
        if(self::$infoFile){
            fclose(self::$infoFile);
        }
        if(self::$apiFile){
            fclose(self::$apiFile);
        }
    }
}

class Response {
    /**
     * @$response["error"] boolean
     * @$response["code"] Integer
     * @$response["messages"] array of arrays ["message", "type"]
     * @$response["data"] array or Object
     */
    private $response = null;
    public function __construct(){
        $this->reset();
    }
    private function reset(){
        $this->response = [
            "error" => 0,
            "code" => "",
            "messages" => [],
            "data" => []
        ];
        return $this;
    }
    public function setCode($code){
        $this->response["code"] = $code;
        return $this;
    }
    public function setError($error = 1){
        $this->response["error"] = $error;
        return $this;
    }
    public function addMessage($message, $type = "info"){
        array_push($this->response["messages"], [$message, $type]);
        return $this;
    }
    public function get(){
        return $this->response;
    }
    public function setData($data){
        $this->response["data"] = $data;
        return $this;
    }
    public function send(){
        if(!Config::isDebugMode()){
            ob_start();
            ob_end_clean();
        } else {
            $this->response["debug_mode"] = 1;
        }
        if(Config::isTestingMode()){
            $this->response["testing_mode"] = 1;
        }
        echo json_encode($this->response);
        $this->reset();
    }
}

/**
 * Database Class
 */
class Schema extends Core {
    private $database = null;
    private $database_file = null;
    private $table = null;
    private $columns = [];
    private $where = [];
    private $resultArray = [];
    private $valid_operators = ["==", "<=", ">=",">","<"];
    public function __construct(){
        if(Config::get("testing_mode")){
            $this->database_file = JSDB_DATA . "test.jsdb";
        } else {
            $this->database_file = JSDB_DATA . "main.jsdb";
        }
        // Check if Main Database Exists
        if(!$this->isDatabaseExists()){
            $this->initializeMainDatabase();
        }
    }
    private function loadDefaultSchema(){
        $defaultSchema = new \stdClass();
        $defaultSchema->__jsdb_core = [
            ["created"=>date("Y-m-d H:i:s")]
        ];
        $this->database = $defaultSchema;
    }
    private function getLock(){
        if(!file_exists(JSDB_DATA."jsdb.lock")){
            return false;
        }
        return file_get_contents(JSDB_DATA."jsdb.lock");
    }
    private function unlockDatabase(){
        $lockFile = JSDB_DATA."jsdb.lock";
        if(file_exists($lockFile)){
            if(!unlink($lockFile)){
                throw new \Exception("Error unlocking the Database.");
            }
        }
    }
    private function lockDatabase(){
        // Will Unlock Database if locked for more than $autoUnlockSeconds
        $autoUnlockSeconds = 10;
        while($isLocked = $this->getLock()){
            if(microtime(true) - $isLocked > $autoUnlockSeconds){
                Logger::logInfo("Database is Already Locked.");
                $this->unlockDatabase();
                Logger::logInfo("Automatically Unlocked Database.");
                break;
            }
            sleep(1);
        }
        if(!file_put_contents(JSDB_DATA."jsdb.lock", explode(".", microtime(true))[0])){
            throw new \Exception("Error locking the Database");
        }
    }
    private function commit(){
        if(!file_put_contents($this->database_file, json_encode($this->database))){
            throw new \Exception("Database file is not writable, Make sure JSDB_DATA is writable.");
        }
    }
    private function initializeMainDatabase(){
        $this->loadDefaultSchema();
        $this->commit();
    }
    private function isDatabaseExists(){
        return file_exists($this->database_file);
    }
    private function open(){
        $this->database = json_decode(file_get_contents($this->database_file), true);
    }
    private function reset(){
        $this->table = null;
        $this->where = [];
    }
    private function checkCondition($row){
        $condition_results = [];
        foreach($this->where as $v){
            $condition_result = 0;
            $operation = $v[0];
            $column = $v[1];
            $operator = $v[2];
            $value = $v[3];
            if(!in_array($operator, $this->valid_operators)){
                throw new \Exception("Invalid Operator {$operator}");
            }
            if(!isset($row[$column])){
                $condition_result = 0;
                break;
                //return false;
            }
            // >= and <= operaotrs used for numbers only
            if($operator != "==" && !is_numeric($value)){
                $condition_result = 0;
                break;
                //return false;
            }
            switch($operator){
                case "==":
                    if($row[$column] == $value){
                        $condition_result = 1;
                    }
                    break;
                case ">=":
                    if($row[$column] >= $value){
                        $condition_result = 1;
                    }
                    break;
                case "<=":
                    if($row[$column] <= $value){
                        $condition_result = 1;
                    }
                    break;
                case ">":
                    if($row[$column] > $value){
                        $condition_result = 1;
                    }
                    break;
                case "<":
                    if($row[$column] < $value){
                        $condition_result = 1;
                    }
                    break;
                default:
                    throw new \Exception("Invalid Operator {$operator}");
            }
            array_push($condition_results, $operation);
            array_push($condition_results, $condition_result);
        }
        array_shift($condition_results);
        if(count($this->where)==1){
            //No Need to Extended conditions
            return $condition_result;
        }
        // Proceed to Extended Permissions
        for($i=0,$j=1; $i <= count($condition_results)-2; $i+=2, $j+=2){
            $operator = $condition_results[$j];
            switch($operator){
                case "and":
                    return ($condition_results[$i] && $condition_results[$i+2]);
                break;
                case "or":
                    return ($condition_results[$i] || $condition_results[$i+2]);
                break;
            }                
        }
    }
    public function table($table){
        $this->reset();
        $this->table = $table;
        return $this;
    }
    public function columns($columns){
        $this->columns = $columns;
        return $this;
    }
    public function where(){
        // Validate first where
        if(func_num_args() != 3 && count($this->where) == 0){
            throw new \Exception("Invalid first 'where' values");
        }
        // Catch Where Arguments
        if(func_num_args() == 4){
            $operation = trim(strtolower(func_get_arg(0)));
            $column = trim(func_get_arg(1));
            $operator = func_get_arg(2);
            $value = func_get_arg(3);
        } else if(func_num_args() == 3){
            $operation = "and";
            $column = trim(func_get_arg(0));
            $operator = func_get_arg(1);
            $value = func_get_arg(2);
        }
        if(!in_array($operation, ["and","or"])){
            throw new \Exception("Invalid operation {$operation}");
        }
        if(count($this->where) == 0){
            $operation = "";
        }
        $this->where[] = [$operation, $column, $operator, $value];
        return $this;
    }
    public function insert($values){
        if(Config::isWriteProtectedTable($this->table)){
            throw new \Exception("Table ".$this->table." is write protected.");
        }
        $this->open();
        if(!isset($this->database[$this->table]) || !is_array($this->database[$this->table])){
            $this->database[$this->table] = [];
        }
        $this->database[$this->table][] = $values;
        $this->commit();
        return $this;
    }
    public function update($values){
        if(Config::isWriteProtectedTable($this->table)){
            throw new \Exception("Table ".$this->table." is write protected.");
        }
        $this->open();
        if(!isset($this->database[$this->table]) || !is_array($this->database[$this->table])){
            return false;
        }
        $hasConditions = (count($this->where) > 0) ? true : false;
        foreach($this->database[$this->table] as &$v){
            if($hasConditions){
                if(!$this->checkCondition($v)){
                    continue;
                }
            }
            foreach($values as $key => $value){
                $v[$key] = $value;
            }
        }
        $this->commit();
        return $this;
    }
    /**
     * @$where array of criteria
     */
    public function select($limit = 100){
        if(Config::isReadProtectedTable($this->table)){
            throw new \Exception("Table ".$this->table." is read protected.");
        }
        $this->open();
        if(!isset($this->database[$this->table]) || !is_array($this->database[$this->table])){
            return $this;
        }
        $this->resultArray = $this->database[$this->table];
        // Apply Where Filter
        if(count($this->where) > 0 && count($this->resultArray) > 0){
            $this->resultArray = array_filter($this->resultArray, function($row){
                return $this->checkCondition($row);
            });
        }
        // Filter Selected Columns
        if(count($this->columns) > 0){
            array_walk($this->resultArray, function(&$row, $key){
                foreach($row as $k => $v){
                    if(!in_array($k, $this->columns)){
                        unset($row[$k]);
                    }
                }
            });
        }
        return $this;
    }
    public function fetchAll(){
        $itemsCount = count($this->resultArray);
        if(count($this->resultArray) === 1){
            $this->resultArray = array_pop($this->resultArray);
        }
        return ["items" => $this->resultArray, "itemsCount" => $itemsCount];
    }
    public function rowsCount(){
        return count($this->resultArray);
    }
    public function delete(){
        if(Config::isWriteProtectedTable($this->table)){
            throw new \Exception("Table ".$this->table." is write protected.");
        }
        $this->open();
        if(!isset($this->database[$this->table]) || !is_array($this->database[$this->table])){
            return false;
        }
        $hasConditions = (count($this->where) > 0) ? true : false;
        foreach($this->database[$this->table] as $k => $v){
            if($hasConditions){
                if(!$this->checkCondition($v)){
                    continue;
                }
            }
            unset($this->database[$this->table][$k]);
        }
        $this->commit();
        return $this;
    }
}

class JSDB extends Core {

    public $schema = null;
    public $api = null;
    public function __construct(){
        parent::__construct();
    }
    public function initialize(){
        
        if(Config::isDebugMode()){
            ini_set("display_errors", "on");
            ini_set("error_reporting", E_ALL);
        } else {
            ini_set("display_errors", "off");
            error_reporting(0);
        }

        $this->schema = new Schema();
        $this->api = new API();

        parent::initialize();
    }
    public function shutdown(){
        Logger::logInfo("Shotdown.");
        Logger::close();
        exit;
    }
}

class API_Validator {
    
    private static $command = null;
    private static $table = null;
    private static $where = [];
    private static $values = [];
    private static $columns = [];
    private static function validateCommand($request){
        $availableCommands = ["select", "insert", "update", "delete"];
        if(!isset($request["command"]) || !in_array($request["command"], $availableCommands)){
            throw new \Exception("Bad command");
        }
        self::$command = $request["command"];
    }
    private static function validateTable($request){
        if(!isset($request["table"]) || !preg_match("/^[a-zA-Z0-9_]+(\.)*[a-zA-Z0-9_]+$/", $request["table"])){
            throw new \Exception("Bad table name, Valid: Caracters Numbers _ . ");
        }
        self::$table = $request["table"];
    }
    private static function validateWhere($request){
        if(!isset($request["where"])){
            return true;
        }
        if(!is_array($request["where"])){
            throw new \Exception("Invalid 'where' syntax");
        }
        self::$where = $request["where"];
    }
    private static function validateValues($request){
        if(!isset($request["values"])){
            return true;
        }
        if(!is_array($request["values"])){
            throw new \Exception("Invalid 'values' syntax");
        }
        self::$values = $request["values"];
    }
    private static function validateColumns($request){
        if(!isset($request["columns"])){
            return true;
        }
        if(!is_array($request["columns"])){
            throw new \Exception("Invalid 'columns' syntax");
        }
        self::$columns = $request["columns"];
    }
    private static function validateSelect(){
        
    }
    private static function validateInsert(){
        if(count(self::$values)==0){
            throw new \Exception("Values required");
        }
    }
    private static function validateUpdate(){
        if(count(self::$values)==0){
            throw new \Exception("Values required");
        }
    }
    private static function validateDelete(){
        
    }
    public static function validate($request){
        self::validateCommand($request);
        self::validateTable($request);
        self::validateWhere($request);
        self::validateValues($request);
        switch(self::$command){
            case "select":
                self::validateSelect();
            break;
            case "insert":
                self::validateInsert();
            break;
            case "update":
                self::validateUpdate();
            break;
            case "delete":
                self::validateDelete();
            break;
            default:
                throw new \Exception("Invalid command");
        }
    }

}

class API extends JSDB {
    private $request = [];
    public function __construct(){
        self::authenticate();
        parent::__construct();
        $this->request = $_REQUEST;
    }
    public function getRequest(){
        return $this->request;
    }
    public function getParam($paramName){
        return isset($this->request[$paramName]) ? $this->request[$paramName] : false;
    }
    private function authenticate(){
        if(!Security::isAuthenticated()){
            if(!Security::authenticate()){
                throw new \Exception("Unauthorized Access");
            }
        }
    }
    public function isBasicCommand(){
        $basicCommands = ["select", "insert", "update", "delete"];
        return in_array($this->request["command"], $basicCommands);
    }
    public function handleRequest(){
        API_Validator::validate($this->request);
        
        switch($this->request["command"]){
            case "select":
                $this->select();
            break;
            case "insert":
                $this->insert();
            break;
            case "update":
                $this->update();
            break;
            case "delete":
                $this->delete();
            break;
        }
    }
    private function hasWhere(){
        if(isset($this->request["where"]) && count($this->request["where"]) > 0){
            return true;
        }
        return false;
    }
    private function select(){
        $query = $this->schema->table($this->request["table"]);
        if($this->hasWhere()){
            foreach($this->request["where"] as $w){
                if(count($w) == 3){
                    $query->where($w[0], $w[1], $w[2]);
                } else if(count($w) == 4) {
                    $query->where($w[0], $w[1], $w[2], $w[3]);
                }
            }
        }
        if(isset($this->request["columns"]) && is_array($this->request["columns"])){
            $query->columns($this->request["columns"]);
        }
        $data = $query->select()->fetchAll();
        $this->response->setData($data)->send();
    }
    private function insert(){
        $query = $this->schema->table($this->request["table"])->insert($this->request["values"]);
        $this->response->setData(1)->send();
    }
    private function update(){
        $query = $this->schema->table($this->request["table"]);
        if($this->hasWhere()){
            foreach($this->request["where"] as $w){
                if(count($w) == 3){
                    $query->where($w[0], $w[1], $w[2]);
                } else if(count($w) == 4) {
                    $query->where($w[0], $w[1], $w[2], $w[3]);
                }
            }
        }
        $query->update($this->request["values"]);
        $this->response->setData(1)->send();
    }
    private function delete(){
        $query = $query = $this->schema->table($this->request["table"]);
        if($this->hasWhere()){
            foreach($this->request["where"] as $w){
                if(count($w) == 3){
                    $query->where($w[0], $w[1], $w[2]);
                } else if(count($w) == 4) {
                    $query->where($w[0], $w[1], $w[2], $w[3]);
                }
            }
        }
        $query->delete();
        $this->response->setData(1)->send();
    }
}