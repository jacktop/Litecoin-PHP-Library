<?php
/**
 * Litecoin classes
 *
 * By Mark Mikkelson - All rights reversed http://www.unlicense.org/ (public domain)
 * This is based largely on Mike Gogulski's Bitcoin library https://github.com/mikegogulski/bitcoin-php
 * If you use this library and it helps you and would like to show your appreciation/support
 * You can donate Litecoins to address LPfr9bqMZ8j4Gu9HfT6cHdiiVxbvuonPdf   
 * They would be greatly appreciated. Thanks!
 *
 * Available at https://github.com/MadCapsule
 *
 * @author Mark Mikkelson - mark@madcapsule.co.uk
 * https://github.com/MadCapsule http://www.madcapsule.com
 *
 *
 */

/**
 * Litecoin utility functions class
 *
 * @author theymos (functionality)
 * @author Mark Mikkelson
 * 	http://www.madcapsule.com/ 
 *
 */
class Litecoin {
  
  /**
 * Exception class for LitecoinClient
 *
 * @author Mark Mikkelson
 * Based on Mike Gogulski's Bitcoin Exception class
 * 	http://www.madcapsule.com/
 */
class LitecoinClientException extends ErrorException {
  // Exception optional
  public function __construct($message, $code = 0, $severity = E_USER_NOTICE, Exception $previous = null) {
    parent::__construct($message, $code, $severity, $previous);
  }

  public function __toString() {
    return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
  }
}

require_once(dirname(__FILE__) . "/includes/xmlrpc.inc");
require_once(dirname(__FILE__) . "/includes/jsonrpc.inc");

/**
 * Litecoin client class for JSON-RPC-HTTP[S] calls
 *
 * Implements the methods documented at https://www.bitcoin.org/wiki/doku.php?id=api
 *
 * @version 0.0.1
 * @author Mark Mikkelson
 * http://www.madcapsule.com 
 */
class LitecoinClient extends jsonrpc_client {

  /**
   * Create a jsonrpc_client object to talk to the bitcoin server and return it,
   * or false on failure.
   *
   * @param string $scheme
   * 	"http" or "https"
   * @param string $username
   * 	User name to use in connection the Litecoin server's JSON-RPC interface
   * @param string $password
   * 	Server password
   * @param string $address
   * 	Server hostname or IP address
   * @param mixed $port
   * 	Server port (string or integer)
   * @param string $certificate_path
   * 	Path on the local filesystem to server's PEM certificate (ignored if $scheme != "https")
   * @param integer $debug_level
   * 	0 (default) = no debugging;
   * 	1 = echo JSON-RPC messages received to stdout;
   * 	2 = log transmitted messages also
   * @return jsonrpc_client
   * @access public
   * @throws LitecoinClientException
   */
  public function __construct($scheme, $username, $password, $address = "localhost", $port = 8332, $certificate_path = '', $debug_level = 0) {
    $scheme = strtolower($scheme);
    if ($scheme != "http" && $scheme != "https")
      throw new LitecoinClientException("Scheme must be http or https");
    if (empty($username))
      throw new LitecoinClientException("Username must be non-blank");
    if (empty($password))
      throw new LitecoinClientException("Password must be non-blank");
    $port = (string) $port;
    if (!$port || empty($port) || !is_numeric($port) || $port < 1 || $port > 65535 || floatval($port) != intval($port))
      throw new LitecoinClientException("Port must be an integer and between 1 and 65535");
    if (!empty($certificate_path) && !is_readable($certificate_path))
      throw new LitecoinClientException("Certificate file " . $certificate_path . " is not readable");
    $uri = $scheme . "://" . $username . ":" . $password . "@" . $address . ":" . $port . "/";
    parent::__construct($uri);
    $this->setDebug($debug_level);
    $this->setSSLVerifyHost(0);
    if ($scheme == "https")
      if (!empty($certificate_path))
        $this->setCaCertificate($certificate_path);
      else
        $this->setSSLVerifyPeer(false);
  }

  /**
   * Test if the connection to the Litecoin JSON-RPC server is working
   *
   * The check is done by calling the server's getinfo() method and checking
   * for a fault.
   *
   * @return mixed boolean TRUE if successful, or a fault string otherwise
   * @access public
   * @throws none
   */
  public function can_connect() {
    try {
      $r = $this->getinfo();
    } catch (LitecoinClientException $e) {
      return $e->getMessage();
    }
    return true;
  }

  /**
   * Convert a Litecoin server query argument to a jsonrpcval
   *
   * @param mixed $argument
   * @return jsonrpcval
   * @throws none
   * @todo Make this method private.
   */
  public function query_arg_to_parameter($argument) {
    $type = "";// "string" is encoded as this default type value in xmlrpc.inc
    if (is_numeric($argument)) {
      if (intval($argument) != floatval($argument)) {
        $argument = floatval($argument);
        $type = "double";
      } else {
        $argument = intval($argument);
        $type = "int";
      }
    }
    if (is_bool($argument))
      $type = "boolean";
    if (is_int($argument))
      $type = "int";
    if (is_float($argument))
      $type = "double";
    if (is_array($argument))
      $type = "array";
    return new jsonrpcval($argument, $type);
  }

  /**
   * Send a JSON-RPC message and optional parameter arguments to the server.
   *
   * Use the API functions if possible. This method remains public to support
   * changes being made to the API before this libarary can be updated.
   *
   * @param string $message
   * @param mixed $args, ...
   * @return mixed
   * @throws LitecoinClientException
   * @see xmlrpc.inc:php_xmlrpc_decode()
   */
  public function query($message) {
    if (!$message || empty($message))
      throw new LitecoinClientException("Litecoin client query requires a message");
    $msg = new jsonrpcmsg($message);
    if (func_num_args() > 1) {
      for ($i = 1; $i < func_num_args(); $i++) {
        $msg->addParam(self::query_arg_to_parameter(func_get_arg($i)));
      }
    }
    $response = $this->send($msg);
    if ($response->faultCode()) {
      throw new LitecoinClientException($response->faultString());
    }
    return php_xmlrpc_decode($response->value());
  }

  /*
   * The following functions implement the Litecoin RPC API as documented at https://www.bitcoin.org/wiki/doku.php?id=api
   */

  /**
   * Safely copies wallet.dat to destination, which can be a directory or
   * a path with filename.
   *
   * @param string $destination
   * @return mixed Nothing, or an error array
   * @throws LitecoinClientException
   */
  public function backupwallet($destination) {
    if (!$destination || empty($destination))
      throw new LitecoinClientException("backupwallet requires a destination");
    return $this->query("backupwallet", $destination);
  }

  /**
   * Returns the server's available balance, or the balance for $account with
   * at least $minconf confirmations.
   *
   * @param string $account Account to check. If not provided, the server's
   *  total available balance is returned.
   * @param integer $minconf If specified, only transactions with at least
   *  $minconf confirmations will be included in the returned total.
   * @return float Litecoin balance
   * @throws LitecoinClientException
   */
  public function getbalance($account = NULL, $minconf = 1) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('getbalance requires a numeric minconf >= 0');
    if ($account === NULL)
      return $this->query("getbalance");
    return $this->query("getbalance", $account, $minconf);
  }

  /**
   * Returns the number of blocks in the longest block chain.
   *
   * @return integer Current block count
   * @throws LitecoinClientException
   */
  public function getblockcount() {
    return $this->query("getblockcount");
  }

  /**
   * Returns the block number of the latest block in the longest block chain.
   *
   * @return integer Block number
   * @throws LitecoinClientException
   */
  public function getblocknumber() {
    return $this->query("getblocknumber");
  }

  /**
   * Returns the number of connections to other nodes.
   *
   * @return integer Connection count
   * @throws LitecoinClientException
   */
  public function getconnectioncount() {
    return $this->query("getconnectioncount");
  }

  /**
   * Returns the proof-of-work difficulty as a multiple of the minimum difficulty.
   *
   * @return float Difficulty
   * @throws LitecoinClientException
   */
  public function getdifficulty() {
    return $this->query("getdifficulty");
  }

  /**
   * Returns boolean true if server is trying to generate bitcoins, false otherwise.
   *
   * @return boolean Generation status
   * @throws LitecoinClientException
   */
  public function getgenerate() {
    return $this->query("getgenerate");
  }

  /**
   * Tell Litecoin server to generate Litecoins or not, and how many processors
   * to use.
   *
   * @param boolean $generate
   * @param integer $maxproc
   * 	Limit generation to $maxproc processors, unlimited if -1
   * @return mixed Nothing if successful, error array if not
   * @throws LitecoinClientException
   */
  public function setgenerate($generate = TRUE, $maxproc = -1) {
    if (!is_numeric($maxproc) || $maxproc < -1)
      throw new LitecoinClientException('setgenerate: $maxproc must be numeric and >= -1');
    return $this->query("setgenerate", $generate, $maxproc);
  }

  /**
   * Returns an array containing server information.
   *
   * @return array Server information
   * @throws LitecoinClientException
   */
  public function getinfo() {
    return $this->query("getinfo");
  }

  /**
   * Returns the account associated with the given address.
   *
   * @param string $address
   * @return string Account
   * @throws LitecoinClientException
   * @since 0.3.17
   */
  public function getaccount($address) {
    if (!$address || empty($address))
      throw new LitecoinClientException("getaccount requires an address");
    return $this->query("getaccount", $address);
  }

  /**
   * Returns the label associated with the given address.
   *
   * @param string $address
   * @return string Label
   * @throws LitecoinClientException
   * @deprecated Since 0.3.17
   */
  public function getlabel($address) {
    if (!$address || empty($address))
      throw new LitecoinClientException("getlabel requires an address");
    return $this->query("getlabel", $address);
  }

  /**
   * Sets the account associated with the given address.
   * $account may be omitted to remove an account from an address.
   *
   * @param string $address
   * @param string $account
   * @return NULL
   * @throws LitecoinClientException
   * @since 0.3.17
   */
  public function setaccount($address, $account = "") {
    if (!$address || empty($address))
      throw new LitecoinClientException("setaccount requires an address");
    return $this->query("setaccount", $address, $account);
  }

  /**
   * Sets the label associated with the given address.
   * $label may be omitted to remove a label from an address.
   *
   * @param string $address
   * @param string $label
   * @return NULL
   * @throws LitecoinClientException
   * @deprecated Since 0.3.17
   */
  public function setlabel($address, $label = "") {
    if (!$address || empty($address))
      throw new LitecoinClientException("setlabel requires an address");
    return $this->query("setlabel", $address, $label);
  }

  /**
   * Returns a new bitcoin address for receiving payments.
   *
   * If $account is specified (recommended), it is added to the address book so
   * payments received with the address will be credited to $account.
   *
   * @param string $account Label to apply to the new address
   * @return string Litecoin address
   * @throws LitecoinClientException
   */
  public function getnewaddress($account = NULL) {
    if (!$account || empty($account))
      return $this->query("getnewaddress");
    return $this->query("getnewaddress", $account);
  }

  /**
   * Returns the total amount received by $address in transactions with at least
   * $minconf confirmations.
   *
   * @param string $address
   * 	Litecoin address
   * @param integer $minconf
   * 	Minimum number of confirmations for transactions to be counted
   * @return float Litecoin total
   * @throws LitecoinClientException
   */
  public function getreceivedbyaddress($address, $minconf = 1) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('getreceivedbyaddress requires a numeric minconf >= 0');
    if (!$address || empty($address))
      throw new LitecoinClientException("getreceivedbyaddress requires an address");
    return $this->query("getreceivedbyaddress", $address, $minconf);
  }

  /**
   * Returns the total amount received by addresses associated with $account
   * in transactions with at least $minconf confirmations.
   *
   * @param string $account
   * @param integer $minconf
   * 	Minimum number of confirmations for transactions to be counted
   * @return float Litecoin total
   * @throws LitecoinClientException
   * @since 0.3.17
   */
  public function getreceivedbyaccount($account, $minconf = 1) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('getreceivedbyaccount requires a numeric minconf >= 0');
    if (!$account || empty($account))
      throw new LitecoinClientException("getreceivedbyaccount requires an account");
    return $this->query("getreceivedbyaccount", $account, $minconf);
  }

  /**
   * Returns the total amount received by addresses with $label in
   * transactions with at least $minconf confirmations.
   *
   * @param string $label
   * @param integer $minconf
   * 	Minimum number of confirmations for transactions to be counted
   * @return float Litecoin total
   * @throws LitecoinClientException
   * @deprecated Since 0.3.17
   */
  public function getreceivedbylabel($label, $minconf = 1) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('getreceivedbylabel requires a numeric minconf >= 0');
    if (!$label || empty($label))
      throw new LitecoinClientException("getreceivedbylabel requires a label");
    return $this->query("getreceivedbylabel", $label, $minconf);
  }

  /**
   * Return a list of server RPC commands or help for $command, if specified.
   *
   * @param string $command
   * @return string Help text
   * @throws LitecoinClientException
   */
  public function help($command = NULL) {
    if (!$command || empty($command))
      return $this->query("help");
    return $this->query("help", $command);
  }

  /**
   * Return an array of arrays showing how many Litecoins have been received by
   * each address in the server's wallet.
   *
   * @param integer $minconf Minimum number of confirmations before payments are included.
   * @param boolean $includeempty Whether to include addresses that haven't received any payments.
   * @return array An array of arrays. The elements are:
   * 	"address" => receiving address
   * 	"account" => the account of the receiving address
   * 	"amount" => total amount received by the address
   * 	"confirmations" => number of confirmations of the most recent transaction included
   * @throws LitecoinClientException
   */
  public function listreceivedbyaddress($minconf = 1, $includeempty = FALSE) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('listreceivedbyaddress requires a numeric minconf >= 0');
    return $this->query("listreceivedbyaddress", $minconf, $includeempty);
  }

  /**
   * Return an array of arrays showing how many Litecoins have been received by
   * each account in the server's wallet.
   *
   * @param integer $minconf
   * 	Minimum number of confirmations before payments are included.
   * @param boolean $includeempty
   * 	Whether to include addresses that haven't received any payments.
   * @return array An array of arrays. The elements are:
   * 	"account" => the label of the receiving address
   * 	"amount" => total amount received by the address
   * 	"confirmations" => number of confirmations of the most recent transaction included
   * @throws LitecoinClientException
   * @since 0.3.17
   */
  public function listreceivedbyaccount($minconf = 1, $includeempty = FALSE) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('listreceivedbyaccount requires a numeric minconf >= 0');
    return $this->query("listreceivedbyaccount", $minconf, $includeempty);
  }

  /**
   * Return an array of arrays showing how many Litecoins have been received by
   * each label in the server's wallet.
   *
   * @param integer $minconf Minimum number of confirmations before payments are included.
   * @param boolean $includeempty Whether to include addresses that haven't received any payments.
   * @return array An array of arrays. The elements are:
   * 	"label" => the label of the receiving address
   * 	"amount" => total amount received by the address
   * 	"confirmations" => number of confirmations of the most recent transaction included
   * @throws LitecoinClientException
   * @deprecated Since 0.3.17
   */
  public function listreceivedbylabel($minconf = 1, $includeempty = FALSE) {
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('listreceivedbylabel requires a numeric minconf >= 0');
    return $this->query("listreceivedbylabel", $minconf, $includeempty);
  }

  /**
   * Send amount from the server's available balance.
   *
   * $amount is a real and is rounded to the nearest 0.01. Returns string "sent" on success.
   *
   * @param string $address Destination Litecoin address or IP address
   * @param float $amount Amount to send. Will be rounded to the nearest 0.01.
   * @param string $comment
   * @param string $comment_to
   * @return string Hexadecimal transaction ID on success.
   * @throws LitecoinClientException
   * @todo Document the comment arguments better.
   */
  public function sendtoaddress($address, $amount, $comment = NULL, $comment_to = NULL) {
    if (!$address || empty($address))
      throw new LitecoinClientException("sendtoaddress requires a destination address");
    if (!$amount || empty($amount))
      throw new LitecoinClientException("sendtoaddress requires an amount to send");
    if (!is_numeric($amount) || $amount <= 0)
      throw new LitecoinClientException("sendtoaddress requires the amount sent to be a number > 0");
    $amount = floatval($amount);
    if (!$comment && !$comment_to)
      return $this->query("sendtoaddress", $address, $amount);
    if (!$comment_to)
      return $this->query("sendtoaddress", $address, $amount, $comment);
    return $this->query("sendtoaddress", $address, $amount, $comment, $comment_to);
  }

  /**
   * Stop the Litecoin server.
   *
   * @throws LitecoinClientException
   */
  public function stop() {
    return $this->query("stop");
  }

  /**
   * Check that $address looks like a proper Litecoin address.
   *
   * @param string $address String to test for validity as a Litecoin address
   * @return array An array containing:
   * 	"isvalid" => true or false
   * 	"ismine" => true if the address is in the server's wallet
   * 	"address" => bitcoinaddress
   *  Note: ismine and address are only returned if the address is valid.
   * @throws LitecoinClientException
   */
  public function validateaddress($address) {
    if (!$address || empty($address))
      throw new LitecoinClientException("validateaddress requires a Litecoin address");
    return $this->query("validateaddress", $address);
  }

  /**
   * Return information about a specific transaction.
   *
   * @param string $txid 64-digit hexadecimal transaction ID
   * @return array An error array, or an array containing:
   *    "amount" => float Transaction amount
   *    "fee" => float Transaction fee
   *    "confirmations" => integer Network confirmations of this transaction
   *    "txid" => string The transaction ID
   *    "message" => string Transaction "comment" message
   *    "to" => string Transaction "to" message
   * @throws LitecoinClientException
   * @since 0.3.18
   */
  public function gettransaction($txid) {
    if (!$txid || empty($txid) || strlen($txid) != 64 || !preg_match('/^[0-9a-fA-F]+$/', $txid))
      throw new LitecoinClientException("gettransaction requires a valid hexadecimal transaction ID");
    return $this->query("gettransaction", $txid);
  }

  /**
   * Move bitcoins between accounts.
   *
   * @param string $fromaccount
   *    Account to move from. If given as an empty string ("") or NULL, bitcoins will
   *    be moved from the wallet balance to the target account.
   * @param string $toaccount
   *     Account to move to
   * @param float $amount
   *     Amount to move
   * @param integer $minconf
   *     Minimum number of confirmations on bitcoins being moved
   * @param string $comment
   *     Transaction comment
   * @throws LitecoinClientException
   * @since 0.3.18
   */
  public function move($fromaccount = "", $toaccount, $amount, $minconf = 1, $comment = NULL) {
    if (!$fromaccount) $fromaccount = "";
    if (!$toaccount) $toaccount = "";

    if (!$amount || !is_numeric($amount) || $amount <= 0)
      throw new LitecoinClientException("move requires a from account, to account and numeric amount > 0");
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('move requires a numeric $minconf >= 0');
    if (!$comment || empty($comment))
      return $this->query("move", $fromaccount, $toaccount, $amount, $minconf);
    return $this->query("move", $fromaccount, $toaccount, $amount, $minconf, $comment);
  }

  /**
   * Send $amount from $account's balance to $toaddress. This method will fail
   * if there is less than $amount bitcoins with $minconf confirmations in the
   * account's balance (unless $account is the empty-string-named default
   * account; it behaves like the sendtoaddress method). Returns transaction
   * ID on success.
   *
   * @param string $account Account to send from
   * @param string $toaddress Litecoin address to send to
   * @param float $amount Amount to send
   * @param integer $minconf Minimum number of confirmations on bitcoins being sent
   * @param string $comment
   * @param string $comment_to
   * @return string Hexadecimal transaction ID
   * @throws LitecoinClientException
   * @since 0.3.18
   */
  public function sendfrom($account, $toaddress, $amount, $minconf = 1, $comment = NULL, $comment_to = NULL) {
    if (!$account || !$toaddress || empty($toaddress) || !$amount || !is_numeric($amount) || $amount <= 0)
      throw new LitecoinClientException("sendfrom requires a from account, to account and numeric amount > 0");
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('sendfrom requires a numeric $minconf >= 0');
    if (!$comment && !$comment_to)
      return $this->query("sendfrom", $account, $toaddress, $amount, $minconf);
    if (!$comment_to)
      return $this->query("sendfrom", $account, $toaddress, $amount, $minconf, $comment);
    $this->query("sendfrom", $account, $toaddress, $amount, $minconf, $comment, $comment_to);
  }

  /**
   * Return formatted hash data to work on, or try to solve specified block.
   *
   * If $data is provided, tries to solve the block and returns true if successful.
   * If $data is not provided, returns formatted hash data to work on.
   *
   * @param string $data Block data
   * @return mixed
   *    boolean TRUE if $data provided and block solving successful
   *    array otherwise, containing:
   *      "midstate" => string, precomputed hash state after hashing the first half of the data
   *      "data" => string, block data
   *      "hash1" => string, formatted hash buffer for second hash
   *      "target" => string, little endian hash target
   * @throws LitecoinClientException
   * @since 0.3.18
   */
  public function getwork($data = NULL) {
    if (!$data)
      return $this->query("getwork");
    return $this->query("getwork", $data);
  }

  /**
   * Return the current bitcoin address for receiving payments to $account.
   * The account and address will be created if $account doesn't exist.
   *
   * @param string $account Account name
   * @return string Litecoin address for $account
   * @throws LitecoinClientException
   * @since 0.3.18
   */
  public function getaccountaddress($account) {
    if (!$account || empty($account))
      throw new LitecoinClientException("getaccountaddress requires an account");
    return $this->query("getaccountaddress", $account);
  }

  /**
   * Return a recent hashes per second performance measurement.
   *
   * @return integer Hashes per second
   * @throws LitecoinClientException
   */
  public function gethashespersec() {
    return $this->query("gethashespersec");
  }

  /**
   * Returns the list of addresses associated with the given account.
   *
   * @param string $account
   * @return array
   *    A simple array of Litecoin addresses associated with $account, empty
   *    if the account doesn't exist.
   * @throws LitecoinClientException
   */
  public function getaddressesbyaccount($account) {
    if (!$account || empty($account))
      throw new LitecoinClientException("getaddressesbyaccount requires an account");
    return $this->query("getaddressesbyaccount", $account);
  }

  /**
   * Returns the list of transactions associated with the given account.
   *
   * @param string $account The account to get transactions from. Accepts empty string "" and wildcard "*" values
   * @param integer $count The number of transactions to return.
   * @param integer $from The start number of transactions.
   * @return array
   *    "account" => account of transaction
   *    "address" => address of transaction
   *    "category" => 'send' or 'recieve'
   *    "amount" => Amount sent/recieved
   *    "fee" => Only on sent transactions, transaction fee taken
   *    "confirmations" => Confirmations
   *    "txid" => Transaction ID
   *    "time" => Time of transaction
   *    * @throws LitecoinClientException
   */
  public function listtransactions($account, $count = 10, $from = 0) {
	if (!$account) $account = "";

    if (!is_numeric($count) || $count < 0)
      throw new LitecoinClientException('listtransactions requires a numeric count >= 0');
    if (!is_numeric($from) || $from < 0)
      throw new LitecoinClientException('listtransactions requires a numeric from >= 0');
    return $this->query("listtransactions", $account, $count, $from);
  }

  /**
   * Returns the list of accounts.
   *
   */
  public function listaccounts($minconf = 1) {
    return $this->query("listaccounts", $minconf);
  }

  /**
   * Returns Transaction id (txid)
   *
   * @param string $fromAccount Account to send from
   * @param array $sendTo Key=address Value=amount
   * @param integer $minconf
   * @param string $comment
   * @return string Hexadecimal transaction ID on success.
   * @throws LitecoinClientException
   * @since 0.3.21
      */
  public function sendmany($fromAccount, $sendTo, $minconf = 1, $comment=NULL) {
    if (!$fromAccount || empty($fromAccount))
      throw new LitecoinClientException("sendmany requires an account");
    if (!is_numeric($minconf) || $minconf < 0)
      throw new LitecoinClientException('sendmany requires a numeric minconf >= 0');

    if (!$comment)
      return $this->query("sendmany", $fromAccount, $sendTo, $minconf);
    return $this->query("sendmany", $fromAccount, $sendTo, $minconf, $comment);
  }

}