<?php
/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * @author     Marc Ole Bulling
 * @copyright  2019 Marc Ole Bulling
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      File available since Release 1.0
 */


/**
 * Helper file for database connection
 *
 * @author     Marc Ole Bulling
 * @copyright  2019 Marc Ole Bulling
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      File available since Release 1.0
 */
require_once __DIR__ . "/processing.inc.php";
require_once __DIR__ . "/PluginLoader.php";
require_once __DIR__ . "/api.inc.php";
require_once __DIR__ . "/websocketconnection.inc.php";


const BB_VERSION          = "1310";
const BB_VERSION_READABLE = "1.3.1.0";



//States to tell the script what to do with the barcodes that were scanned
const STATE_CONSUME         = 0;
const STATE_CONSUME_SPOILED = 1;
const STATE_PURCHASE        = 2;
const STATE_OPEN            = 3;
const STATE_GETSTOCK        = 4;
const STATE_ADD_SL          = 5;

const SECTION_KNOWN_BARCODES   = "known";
const SECTION_UNKNOWN_BARCODES = "unknown";
const SECTION_LOGS             = "log";


class DatabaseConnection {


/* 1 is used for true and 0 for false, as PHP interpretes the String "false" as Boolean "true" */
const DEFAULT_VALUES      = array("DEFAULT_BARCODE_C"          => "BBUDDY-C",
				 "DEFAULT_BARCODE_CS"          => "BBUDDY-CS",
				 "DEFAULT_BARCODE_P"           => "BBUDDY-P",
				 "DEFAULT_BARCODE_O"           => "BBUDDY-O",
				 "DEFAULT_BARCODE_GS"          => "BBUDDY-I",
				 "DEFAULT_BARCODE_Q"           => "BBUDDY-Q-",
				 "DEFAULT_BARCODE_AS"          => "BBUDDY-AS",
				 "DEFAULT_REVERT_TIME"         => "10",
				 "DEFAULT_REVERT_SINGLE"       => "1",
				 "DEFAULT_MORE_VERBOSE"        => "1",
				 "DEFAULT_GROCY_API_URL"       => null,
				 "DEFAULT_GROCY_API_KEY"       => null,
				 "DEFAULT_LAST_BARCODE"        => null,
				 "DEFAULT_LAST_PRODUCT"        => null,
				 "DEFAULT_WS_USE"              => "0",
				 "DEFAULT_WS_PORT"             => "47631",
				 "DEFAULT_WS_PORT_EXT"         => "47631",
				 "DEFAULT_WS_SSL_USE"          => "0",
				 "DEFAULT_WS_SSL_URL"          => null,
				 "DEFAULT_WS_FULLSCREEN"       => "0",
				 "DEFAULT_SHOPPINGLIST_REMOVE" => "1");


const DB_INT_VALUES = array("REVERT_TIME", "WS_PORT", "WS_PORT_EXT");

private $db = null;


    function __construct() {
        $this->initDb();
    }
    
    
    //Initiate database and create if not existent
    private function initDb() {
        global $BBCONFIG;
        
        self::checkPermissions();
        $this->db = new SQLite3(DATABASE_PATH);
        $this->db->busyTimeout(5000);
        $this->db->exec("CREATE TABLE IF NOT EXISTS Barcodes(id INTEGER PRIMARY KEY, barcode TEXT NOT NULL, name TEXT NOT NULL, possibleMatch INTEGER, amount INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS Tags(id INTEGER PRIMARY KEY, tag TEXT NOT NULL, itemId INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS TransactionState(id INTEGER PRIMARY KEY, currentState TINYINT NOT NULL, since INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS BarcodeLogs(id INTEGER PRIMARY KEY, log TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS BBConfig(id INTEGER PRIMARY KEY, data TEXT UNIQUE NOT NULL, value TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS ChoreBarcodes(id INTEGER PRIMARY KEY, choreId INTEGER UNIQUE, barcode TEXT NOT NULL )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS Quantities(id INTEGER PRIMARY KEY, barcode TEXT NOT NULL UNIQUE, quantitiy INTEGER NOT NULL, product TEXT)");
        $this->insertDefaultValues();
        $this->getConfig();
        $previousVersion = $BBCONFIG["version"];
        if ($previousVersion < BB_VERSION) {
            $this->upgradeBarcodeBuddy($previousVersion);
            $this->getConfig();
        }
    }
    
    
    private function insertDefaultValues() {
        $this->db->exec("INSERT INTO TransactionState(id,currentState,since) SELECT 1, 0, datetime('now','localtime') WHERE NOT EXISTS(SELECT 1 FROM TransactionState WHERE id = 1)");
        $this->db->exec("INSERT INTO BBConfig(id,data,value) SELECT 1, \"version\", \"" . BB_VERSION . "\" WHERE NOT EXISTS(SELECT 1 FROM BBConfig WHERE id = 1)");
        foreach (self::DEFAULT_VALUES as $key => $value) {
            $name = str_replace("DEFAULT_", "", $key);
            $this->db->exec("INSERT INTO BBConfig(data,value) SELECT \"" . $name . "\", \"" . $value . "\" WHERE NOT EXISTS(SELECT 1 FROM BBConfig WHERE data = '$name')");
        }
    }
    
    
    private function getConfig() {
        global $BBCONFIG;
        $BBCONFIG = array();
        $res      = $this->db->query("SELECT * FROM BBConfig");
        while ($row = $res->fetchArray()) {
            $BBCONFIG[$row['data']] = $row['value'];
        }
        if (sizeof($BBCONFIG) == 0) {
            die("DB Error: Could not get configuration");
        }
        $BBCONFIG["GROCY_BASE_URL"] = strrtrim($BBCONFIG["GROCY_API_URL"], "api/");
    }
    
    
    public function updateConfig($key, $value) {
        global $BBCONFIG;
        if (in_array($key, self::DB_INT_VALUES)) {
            checkIfNumeric($value);
        }
        $this->db->exec("UPDATE BBConfig SET value='" . $value . "' WHERE data='$key'");
        $BBCONFIG[$key] = $value;
    }
    
    public function saveLastBarcode($barcode, $name = null) {
        $this->updateConfig("LAST_BARCODE", $barcode);
        $this->updateConfig("LAST_PRODUCT", $name);
    }
    
    
    private function checkPermissions() {
        if (file_exists(DATABASE_PATH)) {
            if (!is_writable(DATABASE_PATH)) {
                die("DB Error: Database file is not writable");
            }
        } else {
            if (!is_writable(dirname(DATABASE_PATH))) {
                die("DB Error: Database file cannot be created, as folder is not writable. Please check your permissions.<br>
                 Have a look at this link to find out how to do this:
                 <a href='https://github.com/olab/Open-Labyrinth/wiki/How-do-I-make-files-and-folders-writable-for-the-web-server%3F'>" . "How do I make files and folders writable for the web server?</a>");
            }
        }
    }
    
    private function upgradeBarcodeBuddy($previousVersion) {
        global $BBCONFIG;
        global $ERROR_MESSAGE;
        //Place for future update protocols
        $this->db->exec("UPDATE BBConfig SET value='" . BB_VERSION . "' WHERE data='version'");
        if ($previousVersion < 1211) {
            $this->getConfig();
            $this->updateConfig("BARCODE_C", strtoupper($BBCONFIG["BARCODE_C"]));
            $this->updateConfig("BARCODE_O", strtoupper($BBCONFIG["BARCODE_O"]));
            $this->updateConfig("BARCODE_P", strtoupper($BBCONFIG["BARCODE_P"]));
            $this->updateConfig("BARCODE_CS", strtoupper($BBCONFIG["BARCODE_CS"]));
        }
        if ($previousVersion < 1303) {
            $this->getConfig();
            $version = getGrocyVersion();
            if (!isSupportedGrocyVersion($version)) {
                $this->updateConfig("GROCY_API_KEY", null);
                $ERROR_MESSAGE = "Grocy " . MIN_GROCY_VERSION . " or newer required. You are running $version, please upgrade your Grocy instance. Click <a href=\"./setup.php\">here</a> to re-enter your credentials.";
                include __DIR__ . "/../error.php";
                die();
            }
        }
    }
    
    
    
    //Getting the state TODO change date
    public function getTransactionState() {
        global $BBCONFIG;
        
        $res = $this->db->query("SELECT * FROM TransactionState");
        if ($row = $res->fetchArray()) {
            $state = $row["currentState"];
            $since = $row["since"];
            if ($state == STATE_CONSUME) {
                return STATE_CONSUME;
            } else {
                $stateSet            = strtotime($since);
                $now                 = strtotime($this->getDbTimeInLC());
                $differenceInMinutes = round(abs($now - $stateSet) / 60, 0);
                if ($differenceInMinutes > $BBCONFIG["REVERT_TIME"]) {
                    $this->setTransactionState(STATE_CONSUME);
                    return STATE_CONSUME;
                } else {
                    return $state;
                }
            }
        } else {
            die("DB Error");
        }
    }
    
    private function getDbTimeInLC() {
        return $this->db->querySingle("SELECT datetime('now','localtime');");
    }
    
    //Setting the state
    public function setTransactionState($state) {
        $this->db->exec("UPDATE TransactionState SET currentState=$state, since=datetime('now','localtime')");
        sendWebsocketStateChange($state);
    }
    
    //Gets an array of locally stored barcodes
    public function getStoredBarcodes() {
        $res                 = $this->db->query('SELECT * FROM Barcodes');
        $barcodes            = array();
        $barcodes["known"]   = array();
        $barcodes["unknown"] = array();
        while ($row = $res->fetchArray()) {
            $item            = array();
            $item['id']      = $row['id'];
            $item['barcode'] = $row['barcode'];
            $item['amount']  = $row['amount'];
            $item['name']    = $row['name'];
            $item['match']   = $row['possibleMatch'];
            if ($row['name'] != "N/A") {
                array_push($barcodes["known"], $item);
            } else {
                array_push($barcodes["unknown"], $item);
            }
        }
        return $barcodes;
    }
    
    public function getStoredBarcodeAmount($barcode) {
        $res = $this->db->query("SELECT * FROM Barcodes WHERE barcode='$barcode'");
        if ($row = $res->fetchArray()) {
            return $row['amount'];
        } else {
            return 0;
        }
    }
    
    public function getBarcodeById($id) {
        $this->db->query("SELECT * FROM Barcodes WHERE id='$id'");
        $row = $res->fetchArray();
        return $row;
    }
    
    
    //Gets an array of locally stored quantities
    public function getQuantities() {
        $res      = $this->db->query('SELECT * FROM Quantities');
        $barcodes = array();
        while ($row = $res->fetchArray()) {
            $item              = array();
            $item['id']        = $row['id'];
            $item['barcode']   = $row['barcode'];
            $item['quantitiy'] = $row['quantitiy'];
            $item['product']   = $row['product'];
            array_push($barcodes, $item);
        }
        return $barcodes;
    }
    
    
    //Gets quantitiy for stored barcode quantities
    public function getQuantityByBarcode($barcode) {
        $res      = $this->db->query("SELECT * FROM Quantities WHERE barcode='$barcode'");
        $barcodes = array();
        if ($row = $res->fetchArray()) {
            return $row['quantitiy'];
        } else {
            return 1;
        }
    }
    
    
    //Save product name if already stored as Quantitiy
    public function refreshQuantityProductName($barcode, $productname) {
        $res      = $this->db->query("SELECT * FROM Quantities WHERE barcode='$barcode'");
        $barcodes = array();
        if ($row = $res->fetchArray()) {
            $this->db->exec("UPDATE Quantities SET product='$productname' WHERE barcode='$barcode'");
        }
    }
    
    
    
    //Gets an array of locally stored tags
    public function getStoredTags() {
        $res  = $this->db->query('SELECT * FROM Tags');
        $tags = array();
        while ($row = $res->fetchArray()) {
            $item           = array();
            $item['id']     = $row['id'];
            $item['name']   = $row['tag'];
            $item['itemId'] = $row['itemId'];
            $item['item']   = "";
            array_push($tags, $item);
        }
        return $tags;
    }
    
    public function addTag($tag, $itemid) {
        $this->db->exec("INSERT INTO Tags(tag, itemId) VALUES('$tag', $itemid);");
    }
    
    public function tagNotUsedYet($name) {
        $count = $db->querySingle("SELECT COUNT(*) as count FROM Tags WHERE tag='" . $sanitizedWord . "'");
        return ($count == 0);
    }
    
    
    public function updateSavedBarcodeMatch($barcode, $productId) {
        checkIfNumeric($productId);
        $this->db->exec("UPDATE Barcodes SET possibleMatch='$productId' WHERE barcode='$barcode'");
    }
    
    
    //Gets an array of locally stored chore barcodes
    public function getStoredChoreBarcodes() {
        $res    = $this->db->query('SELECT * FROM ChoreBarcodes');
        $chores = array();
        while ($row = $res->fetchArray()) {
            $item            = array();
            $item['id']      = $row['id'];
            $item['choreId'] = $row['choreId'];
            $item['barcode'] = $row['barcode'];
            array_push($chores, $item);
        }
        return $chores;
    }
    
    public function updateChoreBarcode($choreId, $choreBarcode) {
        checkIfNumeric($choreId);
        $this->db->exec("REPLACE INTO ChoreBarcodes(choreId, barcode) VALUES(" . $choreId . ", '" . str_replace('&#39;', "", $choreBarcode) . "')");
    }
    
    public function addUpdateQuantitiy($barcode, $amount, $product = null) {
        checkIfNumeric($amount);
        if ($product == null) {
            $this->db->exec("REPLACE INTO Quantities(barcode, quantitiy) VALUES ('$barcode', $amount)");
        } else {
            $this->db->exec("REPLACE INTO Quantities(barcode, quantitiy, product) VALUES ('$barcode', $amount, '$product')");
        }
    }
    
    public function deleteChoreBarcode($id) {
        checkIfNumeric($id);
        $this->db->exec("DELETE FROM ChoreBarcodes WHERE choreId='$id'");
    }
    
    
    //Deletes Quantity. 
    public function deleteQuantitiy($id) {
        checkIfNumeric($id);
        $this->db->exec("DELETE FROM Quantities WHERE id='$id'");
    }
    
    public function isChoreBarcode($barcode) {
        return (getChoreBarcode($barcode) != null);
    }
    
    
    public function getChoreBarcode($barcode) {
        $res = $this->db->query("SELECT * FROM ChoreBarcodes WHERE barcode='$barcode'");
        if ($row = $res->fetchArray()) {
            return $row;
        } else {
            return null;
        }
    }
    
    
    public function isUnknownBarcodeAlreadyStored($barcode) {
        $count = $this->db->querySingle("SELECT COUNT(*) as count FROM Barcodes WHERE barcode='$barcode'");
        return ($count != 0);
    }
    
    public function addQuantitiyToUnknownBarcode($barcode, $amount) {
        $this->db->exec("UPDATE Barcodes SET amount = amount + $amount WHERE barcode = '$barcode'");
        
    }
    public function setQuantitiyToUnknownBarcode($barcode, $amount) {
        $this->db->exec("UPDATE Barcodes SET amount = $amount WHERE barcode = '$barcode'");
    }
    
    public function insertUnrecognizedBarcode($barcode, $amount = 1, $productname = "N/A", $match = 0) {
        $this->db->exec("INSERT INTO Barcodes(barcode, name, amount, possibleMatch) VALUES('$barcode', '$productname', $amount, $match)");
    }
    
    
    //Check if the given name includes any words that are associated with a product
    public function checkNameForTags($name) {
        $res = $this->db->query(self::generateQueryFromName($name));
        if ($row = $res->fetchArray()) {
            return $row["itemId"];
        } else {
            return 0;
        }
    }
    
    
    //Get all stored logs
    public function getLogs() {
        $res  = $this->db->query('SELECT * FROM BarcodeLogs ORDER BY id DESC');
        $logs = array();
        while ($row = $res->fetchArray()) {
            array_push($logs, $row['log']);
        }
        return $logs;
    }
    
    
    //Save a log
    public function saveLog($log, $isVerbose = false) {
        global $BBCONFIG;
        if ($isVerbose == false || $BBCONFIG["MORE_VERBOSE"] == true) {
            $date = date('Y-m-d H:i:s');
            $this->db->exec("INSERT INTO BarcodeLogs(log) VALUES('" . $date . ": " . sanitizeString($log) . "')");
        }
    }
    
    
    //Delete barcode from local db
    public function deleteBarcode($id) {
        $this->db->exec("DELETE FROM Barcodes WHERE id='$id'");
    }
    
    
    //Delete tag from local db 
    public function deleteTag($id) {
        $this->db->exec("DELETE FROM Tags WHERE id='$id'");
    }
    
    public function deleteAll($section) {
        switch ($section) {
            case SECTION_KNOWN_BARCODES:
                $this->db->exec("DELETE FROM Barcodes WHERE name IS NOT 'N/A'");
                break;
            case SECTION_UNKNOWN_BARCODES:
                $this->db->exec("DELETE FROM Barcodes WHERE name='N/A'");
                break;
            case SECTION_LOGS:
                $this->db->exec("DELETE FROM BarcodeLogs");
                break;
        }
    }
    
    
    //Generates the SQL for word search
    private function generateQueryFromName($name) {
        $words = explode(" ", $name);
        $i     = 0;
        $query = "SELECT itemId FROM Tags ";
        while ($i < sizeof($words)) {
            if ($i == 0) {
                $query = $query . "WHERE tag LIKE '" . $words[$i] . "'";
            } else {
                $query = $query . " OR tag LIKE '" . $words[$i] . "'";
            }
            $i++;
        }
        return $query;
    }
    
}


// Initiates the database variable
if (!isset($db)) {
    $db = new DatabaseConnection();
}
?>
