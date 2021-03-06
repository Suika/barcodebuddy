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
 * Helper file for Grocy API and barcode lookup
 *
 * @author     Marc Ole Bulling
 * @copyright  2019 Marc Ole Bulling
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      File available since Release 1.0
 *
 */


require_once __DIR__ . "/config.php";

const API_PRODUCTS       = 'objects/products';
const API_SHOPPINGLIST   = 'stock/shoppinglist/';
const API_CHORES         = 'objects/chores';
const API_STOCK          = 'stock/products';
const API_CHORE_EXECUTE  = 'chores/';
const API_SYTEM_INFO     = 'system/info';

const MIN_GROCY_VERSION  = "2.5.1";


const METHOD_GET         = "GET";
const METHOD_PUT         = "PUT";
const METHOD_POST        = "POST";

const LOGIN_URL         = "loginurl";
const LOGIN_API_KEY     = "loginkey";

class InvalidServerResponseException extends Exception { }
class InvalidJsonResponseException   extends Exception { }

class CurlGenerator {
    private $ch = null;
    private $method = METHOD_GET;
    
    function __construct($url, $method = METHOD_GET, $jasonData = null, $loginOverride = null, $noApiCall = false) {
        
        $this->method = $method;
        $this->ch     = curl_init();

        if ($loginOverride == null) {
            global $BBCONFIG;
            $apiKey = $BBCONFIG["GROCY_API_KEY"];
            $apiUrl = $BBCONFIG["GROCY_API_URL"];
        } else {
            $apiKey = $loginOverride[LOGIN_API_KEY];
            $apiUrl = $loginOverride[LOGIN_URL];
        }

        $headerArray = array(
            'GROCY-API-KEY: ' . $apiKey
        );
        if ($jasonData != null) {
            array_push($headerArray, 'Content-Type: application/json');
            array_push($headerArray, 'Content-Length: ' . strlen($jasonData));
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $jasonData);
        }
        
        if ($noApiCall)
            curl_setopt($this->ch, CURLOPT_URL, $url);
        else
            curl_setopt($this->ch, CURLOPT_URL, $apiUrl . $url);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $this->method);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, CURL_TIMEOUT_S);
    }
    
    function execute($decode = false) {
        $curlResult = curl_exec($this->ch);
        curl_close($this->ch);
        if ($curlResult === false)
            throw new InvalidServerResponseException();
        if ($decode) {
            $jsonDecoded = json_decode($curlResult, true);
            if (isset($jsonDecoded->response->status) && $jsonDecoded->response->status == 'ERROR') {
                throw new InvalidJsonResponseException($jsonDecoded->response->errormessage);
            }
            return $jsonDecoded;
        } else
            return $curlResult;
    }
}

class API {
    
    /**
     * Getting info about one or all Grocy products.
     * 
     * @param  string ProductId or none, to get a list of all products
     * @return array Product info or array of products
     */
    public function getProductInfo($productId = "") {

        if ($productId == "") {
            $apiurl = API_PRODUCTS;
        } else {
            $apiurl = API_PRODUCTS . "/" . $productId;
        }

        $curl = new CurlGenerator($apiurl);
        try {
            $result = $curl->execute(true);
        } catch (InvalidServerResponseException $e) {
            die("Error getting product info");
        } catch (InvalidJsonResponseException $e) {
            die("Error parsing product info");
        }
        return $result;
    }
    
    
    /**
     * Open product with $id
     * 
     * @param  String productId
     * @return none
     */
    public function openProduct($id) {

        $data      = json_encode(array(
            'amount' => "1"
        ));
        $apiurl = API_STOCK . "/" . $id . "/open";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error opening product");
        }
    }
    
    

    /**
     *   Check if API details are correct
     * 
     * @param  String URL to Grocy API
     * @param  String API key
     * @return Returns String with error or true if connection could be established
     */
    public function checkApiConnection($givenurl, $apikey) {
        $loginInfo = array(LOGIN_URL => $givenurl, LOGIN_API_KEY => $apikey);

        $curl = new CurlGenerator(API_SYTEM_INFO, METHOD_GET, null, $loginInfo);
        try {
            $result = $curl->execute(true);
        } catch (InvalidServerResponseException $e) {
            return "Could not connect to server.";
        } catch (InvalidJsonResponseException $e) {
            return $e->getMessage();
        }
        if (isset($result["grocy_version"]["Version"])) {
            $version = $result["grocy_version"]["Version"];
            
            if (!API::isSupportedGrocyVersion($version)) {
                return "Grocy " . MIN_GROCY_VERSION . " or newer required. You are running " . $version . ", please upgrade your Grocy instance.";
            } else {
                return true;
            }
        }
        return "Invalid response. Maybe you are using an incorrect API key?";
    }
    
    /**
     *
     * Check if the installed Grocy version is equal or newer to the required version
     * 
     * @param  String reported Grocy version
     * @return boolean true if version supported
     */
    public function isSupportedGrocyVersion($version) {
        if (!preg_match("/\d+.\d+.\d+/", $version)) {
            return false;
        }
        
        $version_ex    = explode(".", $version);
        $minVersion_ex = explode(".", MIN_GROCY_VERSION);
        
        if ($version_ex[0] < $minVersion_ex[0]) {
            return false;
        } else if ($version_ex[0] == $minVersion_ex[0] && $version_ex[1] < $minVersion_ex[1]) {
            return false;
        } else if ($version_ex[0] == $minVersion_ex[0] && $version_ex[1] == $minVersion_ex[1] && $version_ex[2] < $minVersion_ex[2]) {
            return false;
        } else {
            return true;
        }
    }
    
    
    /**
     *
     * Requests the version of the Grocy instance
     * 
     * @return String Reported Grocy version
     */
    public function getGrocyVersion() {

        $curl = new CurlGenerator(API_SYTEM_INFO);
        try {
            $result = $curl->execute(true);
        } catch (InvalidServerResponseException $e) {
            die ("Could not connect to server.");
        } catch (InvalidJsonResponseException $e) {
            die ($e->getMessage());
        }

        if (isset($result["grocy_version"]["Version"])) {
            return $result["grocy_version"]["Version"];
        }
        die("Grocy did not provide version number");
    }
    
    
    /**
     *
     *  Adds a Grocy product.
     * 
     * @param  String id of product
     * @param  int amount of product
     * @param  String Date of best before Default: null (requests default BestBefore date from grocy)
     * @param  String price of product Default: null
     * @return false if default best before date not set
     */
    public function purchaseProduct($id, $amount, $bestbefore = null, $price = null) {
        global $BBCONFIG;
        
        $daysBestBefore = 0;
        $data = array(
            'amount' => $amount,
            'transaction_type' => 'purchase'
        );

        if ($price != null) {
            $data['price'] = $price;
        }
        if ($bestbefore != null) {
            $daysBestBefore           = $bestbefore;
            $data['best_before_date'] = $bestbefore;
        } else {
            $daysBestBefore           = self::getDefaultBestBeforeDays($id);
            $data['best_before_date'] = self::formatBestBeforeDays($daysBestBefore);
        }
        $data_json = json_encode($data);

        $apiurl = API_STOCK . "/" . $id . "/add";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data_json);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error purchasing product");
        }

        if ($BBCONFIG["SHOPPINGLIST_REMOVE"]) {
            self::removeFromShoppinglist($id, $amount);
        }
        return ($daysBestBefore != 0);
    }
    
    
    
    /**
     *
     * Removes an item from the default shoppinglist
     * 
     * @param  String product id
     * @param  Int amount
     * @return none
     */
    public function removeFromShoppinglist($productid, $amount) {
        $data      = json_encode(array(
            'product_id' => $productid,
            'product_amount' => $amount
        ));
        $apiurl = API_SHOPPINGLIST . "remove-product";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error removing from shoppinglist");
        }
    }
    
 
    /**
     *
     * Adds an item to the default shoppinglist
     * 
     * @param  String product id
     * @param  Int amount
     * @return none
     */
    public function addToShoppinglist($productid, $amount) {
        $data      = json_encode(array(
            'product_id' => $productid,
            'product_amount' => $amount
        ));
        $apiurl = API_SHOPPINGLIST . "add-product";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error adding to shoppinglist");
        }
    }
    
    
    
    
   /**
    * Consumes a product
    * 
    * @param  int id
    * @param  int amount
    * @param  boolean set true if product was spoiled. Default: false 
    * @return none
    */
    public function consumeProduct($id, $amount, $spoiled = false) {

        $data      = json_encode(array(
            'amount' => $amount,
            'transaction_type' => 'consume',
            'spoiled' => $spoiled
        ));
        
        $apiurl = $BBCONFIG["GROCY_API_URL"] . API_STOCK . "/" . $id . "/consume";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error consuming product");
        }
    }
    
    /**
     * Sets barcode to a Grocy product (replaces all old ones,
     *  so make sure to request them first)
     * @param int product id
     * @param String barcode(s) to set
     */
    public function setBarcode($id, $barcode) {

        $data      = json_encode(array(
            'barcode' => $barcode
        ));

        $apiurl    = API_PRODUCTS . "/" . $id;

        $curl = new CurlGenerator($apiurl, METHOD_PUT, $data);
        try {
            $curl->execute();
        } catch (InvalidServerResponseException $e) {
            die("Error setting barcode");
        }
    }
    
    
    
    // Formats the amount of days into future date
    private function formatBestBeforeDays($days) {
        if ($days == "-1") {
            return "2999-12-31";
        } else {
            $date = date("Y-m-d");
            return date('Y-m-d', strtotime($date . " + $days days"));
        }
    }
    
    // Get default best before in days from a Grocy product
    private function getDefaultBestBeforeDays($id) {
        $info = self::getProductInfo($id);
        $days = $info["default_best_before_days"];
        checkIfNumeric($days);
        return $days;
    }
    
    
    // Look up a barcode using openfoodfacts
    public function lookupNameByBarcode($barcode) {
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://world.openfoodfacts.org/api/v0/product/" . $barcode . ".json");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if ($response === false) {
            die("Error looking up barcode from OFF.");
        }
        curl_close($ch);
        
        if (strpos($response, 'product not found') !== false) {
            return "N/A";
        } else {
            $decoded1 = json_decode($response, true);
            if (isset($decoded1->response->status) && $decoded1->response->status == 'ERROR') {
                die('Error occured: ' . $decoded1->response->errormessage);
            }
            if (isset($decoded1["product"]["generic_name"]) && $decoded1["product"]["generic_name"] != "") {
                return sanitizeString($decoded1["product"]["generic_name"]);
            }
            if (isset($decoded1["product"]["product_name"]) && $decoded1["product"]["product_name"] != "") {
                return sanitizeString($decoded1["product"]["product_name"]);
            }
            return "N/A";
        }
    }
    
    
    // Get a Grocy product by barcode
    public function getProductByBardcode($barcode) {
        global $BBCONFIG;
        
        $apiurl = $BBCONFIG["GROCY_API_URL"] . API_STOCK . "/by-barcode/" . $barcode;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiurl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'GROCY-API-KEY: ' . $BBCONFIG["GROCY_API_KEY"]
        ));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) {
            die("Error looking up product by barcode");
        }
        
        $decoded1 = json_decode($response, true);
        if (isset($decoded1->response->status) && $decoded1->response->status == 'ERROR') {
            die('Error occured: ' . $decoded1->response->errormessage);
        }
        if (isset($decoded1["product"]["id"])) {
            checkIfNumeric($decoded1["product"]["id"]);
            $resultArray                = array();
            $resultArray["id"]          = $decoded1["product"]["id"];
            $resultArray["name"]        = sanitizeString($decoded1["product"]["name"]);
            $resultArray["unit"]        = sanitizeString($decoded1["quantity_unit_stock"]["name"]);
            $resultArray["stockAmount"] = sanitizeString($decoded1["stock_amount"]);
            if ($resultArray["stockAmount"] == null) {
                $resultArray["stockAmount"] = "0";
            }
            return $resultArray;
        } else {
            return null;
        }
    }
    
    
    
    // Getting info of a Grocy chore. If no argument is passed, all products are requested
    public function getChoresInfo($choreId = "") {
        global $BBCONFIG;
        
        if ($choreId == "") {
            $apiurl = $BBCONFIG["GROCY_API_URL"] . API_CHORES;
        } else {
            $apiurl = $BBCONFIG["GROCY_API_URL"] . API_CHORES . "/" . $choreId;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiurl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'GROCY-API-KEY: ' . $BBCONFIG["GROCY_API_KEY"]
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $curl_response = curl_exec($ch);
        curl_close($ch);
        if ($curl_response === false) {
            die("Error getting chore info");
        }
        
        $decoded1 = json_decode($curl_response, true);
        if (isset($decoded1->response->status) && $decoded1->response->status == 'ERROR') {
            die('Error occured: ' . $decoded1->response->errormessage);
        }
        return $decoded1;
    }
    
    
    // Getting info of a Grocy chore. If no argument is passed, all products are requested
    public function executeChore($choreId) {
        global $BBCONFIG;
        
        $apiurl    = $BBCONFIG["GROCY_API_URL"] . API_CHORE_EXECUTE . $choreId . "/execute";
        $data      = array(
            'tracked_time' => "",
            'done_by' => ""
        );
        $data_json = json_encode($data);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiurl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'GROCY-API-KEY: ' . $BBCONFIG["GROCY_API_KEY"],
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_json)
        ));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $curl_response = curl_exec($ch);
        curl_close($ch);
        if ($curl_response === false) {
            die("Error getting chore info");
        }
        
        $decoded1 = json_decode($curl_response, true);
        if (isset($decoded1->response->status) && $decoded1->response->status == 'ERROR') {
            die('Error occured: ' . $decoded1->response->errormessage);
        }
        return $decoded1;
    }
    
}


/* Legacy code for Grocy <2.5.0
function removeFromShoppinglist($productid, $amount) {
$items = getShoppingList();
 foreach ($items as $item) {
	$deleteItem = false;
	if (isset($item["product_id"]) && $item["product_id"]==$productid) {
		$modified = true;
		$remaining = ($item["amount"] - $amount);
		if ($remaining <1) {
			deleteShoppingListItem($item["id"]);
		} else {
			setShoppingListItemAmount($item["id"],$remaining);
		}
	}
}
}

function setShoppingListItemAmount($itemid, $remaining) {
 global $BBCONFIG;
     $data      = array(
        'amount' => $remaining
    );
    $data_json = json_encode($data);
    $apiurl = $BBCONFIG["GROCY_API_URL"].API_SHOPPINGLIST."/".$itemid;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('GROCY-API-KEY: '. $BBCONFIG["GROCY_API_KEY"],'Content-Type: application/json','Content-Length: '.strlen($data_json)));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false) {
       die("Error setting barcode");
    }
}

function deleteShoppingListItem($itemid) {
  global $BBCONFIG;

        $apiurl = $BBCONFIG["GROCY_API_URL"].API_SHOPPINGLIST."/".$itemid;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('GROCY-API-KEY: '. $BBCONFIG["GROCY_API_KEY"]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    $curl_response = curl_exec($ch);
    curl_close($ch);
    if ($curl_response === false) {
        die("Error deleting shopping list item");
    }
}

function getShoppingList() {
    global $BBCONFIG;

        $apiurl = $BBCONFIG["GROCY_API_URL"].API_SHOPPINGLIST;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('GROCY-API-KEY: '. $BBCONFIG["GROCY_API_KEY"]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $curl_response = curl_exec($ch);
    curl_close($ch);
    if ($curl_response === false) {
        die("Error getting shoppinglist");
    }
    
    $decoded1 = json_decode($curl_response, true);
    if (isset($decoded1->response->status) && $decoded1->response->status == 'ERROR') {
        die('Error occured: ' . $decoded1->response->errormessage);
    }
    return $decoded1;
} */

?>
