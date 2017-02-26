<?php
$baseUrl = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];


class Device {
	var $ip;
	var $authName 			= 'cisco';
	var $authPassword 		= 'cisco';

	function __construct($deviceIp, $authName = 'cisco', $authPassword = 'cisco'){
		$this->ip 			= $deviceIp;
		$this->authName 	= $authName;
		$this->authPassword = $authPassword;
	}


	function push($xml){
		$response = '';

		$auth = base64_encode($this->authName.':'.$this->authPassword);
		$postData = "XML=".urlencode($xml);

		$post = "POST /CGI/Execute HTTP/1.0\r\n";
		$post .= "Host: {$this->ip}\r\n";
		$post .= "Authorization: Basic {$auth}\r\n";
		$post .= "Connection: close\r\n";
		$post .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$post .= "Content-Length: ".strlen($postData)."\r\n\r\n";
		$post .= $postData;

		$fp = @fsockopen ($this->ip, 80, $errno, $response, 10);
		if($fp){
			fputs($fp, $post);
			flush();
			while (!feof($fp)) {
				$response .= fgets($fp, 1024);
				flush();
			}

		}

		return $response;
	}
}

class Push2Talk {
	const XML_EXECUTE 		= '<CiscoIPPhoneExecute>%s</CiscoIPPhoneExecute>';
	const XML_EXECUTE_ITEM 	= '<ExecuteItem Priority="%d" URL="%s"/>';
	const URI_START			= 'RTPMRx:%s:%d';

	public $multicastAddress= '225.3.15.13';
	public $multicastPort 	= 20480;

	var $authName 			= 'cisco';
	var $authPassword 		= 'cisco';
	var $deviceName 		= null;

	var $devices 			= array();

	function __construct(){ }

	function setAuthentication($authName, $authPassword){
		$this->authName 	= $authName;
		$this->authPassword = $authPassword;
	}

	function addDevice($deviceIp){
		$this->devices[] 	= new Device($deviceIp, $this->authName, $this->authPassword);
	}

	function addDevices(array $ips){
		foreach($ips as $ip){
			$this->addDevice($ip);
		}
	}

	function start(){
		$this->execute(array(
			sprintf(Push2Talk::URI_START, $this->multicastAddress, $this->multicastPort)
		));
	}

	function stop(){
		$this->execute(array('RTPRx:Stop','RTPTx:Stop','Init:Services'));
	}

	function execute(array $uris, $priority = 0){
		$xmlExecute = array_map(function ($value) {
			return sprintf(Push2Talk::XML_EXECUTE_ITEM, $priority, $value);
		}, $uris); 															// build ExecuteItem xml data-array
		$xml = sprintf(Push2Talk::XML_EXECUTE, implode('',$xmlExecute));	// build xml data including ExecuteItem

		foreach($this->devices as $device) {
			$device->push($xml);
		}
	}
}


$push2Talk = new Push2Talk;
$push2Talk->setAuthentication('cisco', 'cisco');
$push2Talk->addDevices(array('10.0.2.225', '10.0.2.227'));

// optional settings
//$push2Talk->multicastAddress = '225.3.15.13';
//$push2Talk->multicastPort = 16384


$response = '';
do {  // while loop for error handling

	$deviceName =  null;
	$isTalking = false;
	$action = isset($_GET['action']) ? $_GET['action'] : 'start';

	if (!isset($_GET['name'])){
		$response = '<CiscoIPPhoneText><Title>Error!</Title><Text>No MAC provided by phone!</Text></CiscoIPPhoneText>';
		break;
	}

	$deviceName = $_GET['name'];

	switch($action){
		default:
			$push2Talk->start();
			break;

		case 'close':
			$push2Talk->stop();
			break;
	}

	// Cisco headers
	$response = '<CiscoIPPhoneText><Title>PTT</Title><Prompt>Use soft keys to talk</Prompt>';

	// PTT operation
	if (!$isTalking) {
		$response .= "<Text>Press and hold the [Talk] soft key to transmit. If you want to speak continuously without holding a button down, you can press and release the [Lock] soft key.</Text>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Talk</Name>";
		$response .=  "<URL>RTPTx:Stop</URL>";
		$response .=  "<Position>1</Position>";
		$response .=  "<URLDown>RTPMTx:{$push2Talk->multicastAddress}:{$push2Talk->multicastPort}</URLDown>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Exit</Name>";
		$response .=  "<URL>{$baseUrl}?name=#DEVICENAME#&amp;action=close</URL>";
		$response .=  "<Position>3</Position>";
		$response .=  "<URLDown>RTPMRx:Stop</URLDown>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Lock</Name>";
		$response .=  "<URL>{$baseUrl}?name=#DEVICENAME#&amp;action=lock</URL>";
		$response .=  "<Position>4</Position>";
		$response .=  "<URLDown>RTPMTx:{$push2Talk->multicastAddress}:{$push2Talk->multicastPort}</URLDown>";
		$response .=  "</SoftKeyItem>";
	} else {
		$response .=  "<Text>Press and release the [Unlock] soft key to return.</Text>";
		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Unlock</Name>";
		$response .=  "<URL>{$URLBase}?name=#DEVICENAME#&amp;action=unlock</URL>";
		$response .=  "<Position>4</Position>";
		$response .=  "<URLDown>RTPTx:Stop</URLDown>";
		$response .=  "</SoftKeyItem>";
	}

	$response .= '</CiscoIPPhoneText>';

} while(false);


// Send headers
header("Content-Type: text/xml");
header("Expires: -1");

echo $response;
