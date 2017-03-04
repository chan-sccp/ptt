<?php
// Send headers
header("Content-Type: text/xml");
header("Expires: -1");

$baseUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_ADDR'] . ":" . $_SERVER['SERVER_PORT'] . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

class Device {
	var $ip;
	var $authName 			= 'cisco';
	var $authPassword 		= 'cisco';

	function __construct($deviceIp, $authName = 'cisco', $authPassword = 'cisco'){
		$this->ip 		= $deviceIp;
		$this->authName 	= $authName;
		$this->authPassword	= $authPassword;
	}

	function push($xml){
		$response = array();

		$auth = base64_encode($this->authName.':'.$this->authPassword);
		$postData = "XML=".urlencode($xml);

		$post = array();
		$post[] = "POST /CGI/Execute HTTP/1.0\r\n";
		$post[] = "Host: {$this->ip}\r\n";
		$post[] = "Authorization: Basic {$auth}\r\n";
		$post[] = "Connection: close\r\n";
		$post[] = "Content-Type: application/x-www-form-urlencoded\r\n";
		$post[] = "Content-Length: ".strlen($postData)."\r\n\r\n";
		$post[] = $postData;

		// TODO: take SSL/443 into account
		$fp = @fsockopen ($this->ip, 80, $errno, $errstr, 10);
		if(!$fp){
			syslog(LOG_ALERT, "ERROR: fsockopen failed. Error no:$errno - $errstr");
			return FALSE;
		}

		fputs($fp, implode($post));
		flush();
		while (!feof($fp)) {
			$response[] = fgets($fp, 4096);
			flush();
		}
		fclose($fp);

		syslog(LOG_DEBUG, "pushed xml:$xml");
		foreach ($response as $key => $r) {
			if (stripos($r, 'HTTP/1.1') === 0) {
				list(,$code, $status) = explode(' ', $r, 3);
				syslog(LOG_DEBUG, "Return Code: $code, Status: $status");
				break;
			}
		}
		syslog(LOG_DEBUG, "response:" . print_r($response, TRUE));

		// TODO: Should we return the status code or boolean ?
		return ($status == 200) ? TRUE : FALSE;
	}
}

class Push2Talk {
	const XML_EXECUTE 		= '<CiscoIPPhoneExecute>%s</CiscoIPPhoneExecute>';
	const XML_EXECUTE_ITEM	= '<ExecuteItem Priority="%d" URL="%s"/>';
	const URI_START			= 'RTPMRx:%s:%d';

	public $multicastAddress	= '225.3.15.13';
	public $multicastPort 		= 20480;

	var $baseUrl			= null;
	var $authName 			= 'cisco';
	var $authPassword 		= 'cisco';
	var $originator			= null;
	var $devices 			= array();
	var $session 			= null;

	function __construct() {
		global $baseUrl;
		$this->baseUrl = $baseUrl;
	 }

	function setAuthentication($authName, $authPassword){
		$this->authName	= $authName;
		$this->authPassword	= $authPassword;
	}

	function addDevice($deviceIp) {
		$this->devices[] = new Device($deviceIp, $this->authName, $this->authPassword);
	}

	function addDevices(array $ips) {
		foreach($ips as $ip){
			$this->addDevice($ip);
		}
	}

	function findDeviceByIP($ip) {
		syslog(LOG_DEBUG, "\n*** device leaving:" . $_SERVER['REMOTE_ADDR'] . "\n");
		foreach($this->devices as $device) {
			syslog(LOG_DEBUG, "\n*** found device:" . $_SERVER['REMOTE_ADDR'] . "\n");
			if ($ip == $device->ip) {
				return $device;
			}
		}
		return false;
	}

	function createSession() {
		session_start();
		$_SESSION['ptt'] = array(
			'originator' => $this->originator,
			'participants' => array()
		);
		$this->session = session_id();
	}

	// resume session by id
	function resumeSession() {
		if(!isset($_SESSION['ptt'])) {
			session_id($this->session);
			session_start();
		}
	}

	function getOriginator() {
		$this->resumeSession();
		return $_SESSION['ptt']['originator'];
	}

	function addParticipant($ip) {
		$this->resumeSession();
		array_push($_SESSION['ptt']['participants'], $ip);
	}

	function delParticipant($ip) {
		$this->resumeSession();
		$_SESSION['ptt']['participants'] = array_diff($_SESSION['ptt']['participants'], array($ip));
	}

	function getParticipants() {
		$this->resumeSession();
		return $_SESSION['ptt']['participants'];
	}

	function listParticipants() {
		$this->resumeSession();
		return implode("\n", ($_SESSION['ptt']['participants']));
	}

	function start($originator){
		// TODO: handle result from device->push -> adding participant to listeners
		// set the owner of the session
		$this->originator = $originator;
		// create a session that will hold participants currently connected
		$this->createSession();
		foreach($this->devices as $device) {
			if ($device->ip != $this->originator) {
				$this->execute(
					array(
						sprintf(Push2Talk::URI_START, $this->multicastAddress, $this->multicastPort),
						htmlentities("{$this->baseUrl}?name=#DEVICENAME#&action=join&session={$this->session}"),
						'Display:On:1'
					),
					0, $device
				);
			} else {
				$this->execute(
					array(
						sprintf(Push2Talk::URI_START, $this->multicastAddress, $this->multicastPort)
					),
					0, $device
				);
			}
		}
		syslog(LOG_DEBUG, "start");
	}

	function stop() {
		// TODO: handle result from device->push -> adding participant to listeners
		$this->execute(array('RTPRx:Stop','RTPTx:Stop','Init:Services'));
		syslog(LOG_DEBUG, "stop");
	}

	function leave($ip) {			// single participant leaving
		// TODO: handle result from device->push -> adding participant to listeners
		$device = $this->findDeviceByIP($ip);
		if ($device) {
			syslog(LOG_DEBUG, "*** device set: $ip");
			$this->execute(array('RTPRx:Stop','RTPTx:Stop','Init:Services'), 0, $device);
			unset($device);
		}
		syslog(LOG_DEBUG, "leave: $ip");
		$this->delParticipant($ip);
	}

	function execute(array $uris, $priority = 0, $device = false) {
		$xmlExecute = array_map(function ($value) use ($priority) {
			return sprintf(Push2Talk::XML_EXECUTE_ITEM, $priority, $value);
		}, $uris); 									// build ExecuteItem xml data-array
		$xml = sprintf(Push2Talk::XML_EXECUTE, implode('',$xmlExecute));		// build xml data including ExecuteItem

		if ($device) {
			$device->push($xml);
		} else {
			foreach($this->devices as $device) {
				syslog(LOG_DEBUG, "*** stopping");
				//yield $device->push($xml);					// return push result back to caller
				$device->push($xml);
			}
		}
	}
}


$push2Talk = new Push2Talk;
$push2Talk->setAuthentication('cisco', 'cisco');
$devices = array('192.168.0.137', '192.168.0.142', '192.168.0.138');	// Testing zip
//$devices = array('10.0.2.225', '10.0.2.227');							// Testing marcello
//$devices = array('10.15.15.205', '10.15.15.217');						// Testing diederik

// optional settings
//$push2Talk->multicastAddress = '225.3.15.13';
//$push2Talk->multicastPort = 16384

try {
	$isLocked = false;
	$partList = false;
	$originator = isset($_GET['originator']) ? $_GET['originator'] : false;
	$action = isset($_GET['action']) ? $_GET['action'] : 'start';
	// store session id if stored in query string
	$push2Talk->session = isset($_GET['session']) ? $_GET['session'] : $push2Talk->session;

	switch($action){
		case 'start':
			// asking to join devices defined in $devices array
			$push2Talk->addDevices($devices);
			$push2Talk->start($_SERVER['REMOTE_ADDR']);
			break;

		case 'close':
			// get devices that really joined
			$devices = array_merge(array($push2Talk->getOriginator()), $push2Talk->getParticipants());
			$push2Talk->addDevices($devices);
			// push close
			$push2Talk->stop();
			break;

		case 'leave':
			// get devices that really joined
			$devices = array_merge(array($push2Talk->getOriginator()), $push2Talk->getParticipants());
			$push2Talk->addDevices($devices);
			// push leave to device leaving
			$push2Talk->leave($_SERVER['REMOTE_ADDR']);
			break;

		case 'lock':
			$isLocked = true;
			break;

		case 'unlock':
			$isLocked = false;
			break;

		case 'join':
			// add device joining to the device list stored in session
			$push2Talk->addParticipant($_SERVER['REMOTE_ADDR']);
			break;

		case 'list':
			$partList = true;
			break;

		default:
			$partList = false;
			break;
	}

	// Cisco headers
	$response = '<CiscoIPPhoneText><Title>PTT</Title><Prompt>Use soft keys to talk</Prompt>';
	if (!$isLocked && !$partList) {
		$response .= "<Text>Press and hold the [Talk] soft key to transmit. If you want to speak continuously without holding a button down, you can press and release the [Lock] soft key.</Text>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Talk</Name>";
		$response .=  "<URL>RTPTx:Stop</URL>";
		$response .=  "<Position>1</Position>";
		$response .=  "<URLDown>RTPMTx:{$push2Talk->multicastAddress}:{$push2Talk->multicastPort}</URLDown>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Exit</Name>";
		if ($push2Talk->getOriginator() == $_SERVER['REMOTE_ADDR']) {
			$response .=  "<URL>" . htmlentities("{$baseUrl}?action=close&session={$push2Talk->session}") . "</URL>";
		} else {
			$response .=  "<URL>" . htmlentities("{$baseUrl}?action=leave&session={$push2Talk->session}") . "</URL>";
		}
		$response .=  "<Position>3</Position>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>PartList</Name>";
		$response .=  "<URL>" . htmlentities("{$baseUrl}?action=list&session={$push2Talk->session}") . "</URL>";
		$response .=  "<Position>2</Position>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Lock</Name>";
		$response .=  "<URL>" . htmlentities("{$baseUrl}?action=lock&session={$push2Talk->session}") . "</URL>";
		$response .=  "<Position>4</Position>";
		$response .=  "<URLDown>RTPMTx:{$push2Talk->multicastAddress}:{$push2Talk->multicastPort}</URLDown>";
		$response .=  "</SoftKeyItem>";
	} elseif ($isLocked && !$partList) {
		$response .=  "<Text>Press and release the [Unlock] soft key to return.</Text>";
		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Unlock</Name>";
		$response .=  "<URL>" . htmlentities("{$baseUrl}?action=unlock&session={$push2Talk->session}") . "</URL>";
		$response .=  "<Position>4</Position>";
		$response .=  "<URLDown>RTPTx:Stop</URLDown>";
		$response .=  "</SoftKeyItem>";
	} elseif ($partList) {
		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Talk</Name>";
		$response .=  "<URL>RTPTx:Stop</URL>";
		$response .=  "<Position>1</Position>";
		$response .=  "<URLDown>RTPMTx:{$push2Talk->multicastAddress}:{$push2Talk->multicastPort}</URLDown>";
		$response .=  "</SoftKeyItem>";

		$response .=  "<Text>Participant list\n".$push2Talk->listParticipants()."</Text>";
		$response .=  "<SoftKeyItem>";
		$response .=  "<Name>Back</Name>";
		$response .=  "<URL>" . htmlentities("{$baseUrl}?action=default&session={$push2Talk->session}") . "</URL>";
		$response .=  "<Position>2</Position>";
		$response .=  "</SoftKeyItem>";
	}
	$response .= '</CiscoIPPhoneText>';
	syslog(LOG_DEBUG, "response sent:" . $response);

	echo $response;

}
catch(Exception $e) {
	syslog(LOG_DEBUG, "Something went wrong");
}

?>