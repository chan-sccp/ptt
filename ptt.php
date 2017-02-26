<?php

// Location of php files for phones (change the webserver/path to resemble your webserver)
$URLBase = "http://webserver/path/";

// Multicast address and port
$MCAddress = "225.3.15.13";
$MCPort = "16384";

// Phone user/pass to be checked by the script defined in the authenticationURL 
// the authentication script should return AUTHORIZED when login is successfull
// see: https://github.com/chan-sccp/chan-sccp/wiki/Setup-phone-authorization
// If you are using a flat file returning 'AUTHORIZED', without checking then the 
// user/pwd setting does not matter, but you still need to set it to something.
$user = "cisco";
$pwd = "cisco";

// Used to PUSH commands to participating phones
$phones = array('10.0.2.225', '10.0.2.227');

// Function thanks to Diederik, modified to allow for
// multiple commands in one PUSH (pass $uri with commands
// seperated by a comma.)  Also removed echo's and set
// timeout to 10 seconds (a phone should respond by then right?)

function push2phone($ip, $uri, $uid, $pwd, $priority = 0)
{
    $response = "";
    $executeItems = "";
    $auth = base64_encode($uid.":".$pwd);

    // See if there are any comma's in $uri
    if (strpos($uri, ',') >= 0) {
        // Yup, explode it to an array
        $uris = explode(',', $uri);
        
        // Build a <ExecuteItem> for each supplied URI
        foreach($uris as $item) {
            $executeItems .= "<ExecuteItem Priority=\"{$priority}\" URL=\"{$item}\"/>";
        }
    }
    // Nope, take it as it is
    else $executeItems = "<ExecuteIem Priority=\"{$priority}\" URL=\"{$item}\"/>";

    $xml = "<CiscoIPPhoneExecute>$executeItems</CiscoIPPhoneExecute>";
    $xml = "XML=".urlencode($xml);

    $post = "POST /CGI/Execute HTTP/1.0\r\n";
    $post .= "Host: $ip\r\n";
    $post .= "Authorization: Basic $auth\r\n";
    $post .= "Connection: close\r\n";
    $post .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $post .= "Content-Length: ".strlen($xml)."\r\n\r\n";

    //
    // Have to make sure we @ the fsockopen or the headers won't get sent
    // and the phone browser will freak if we toss a error (like host
    // not found.)
    //
    $fp = @fsockopen ( $ip, 80, $errno, $errstr, 10);
    if(!$fp){ return "$errstr ($errno)"; }
    else
    {
        fputs($fp, $post.$xml);
        flush();
        while (!feof($fp))
        {
            $response .= fgets($fp, 128);
            flush();
        }
    }

    return $response;
}

// A real phone will always pass a name=SEP<MAC>
if (isset($_GET['name'])) {
	$MAC = $_GET['name'];
}
else {
	echo "<CiscoIPPhoneText><Title>Error!</Title><Text>No MAC provided by phone!</Text></CiscoIPPhoneText>";
	die();
}

// Check if we're closing the app, if so tell the other phones to do the same
if (isset($_GET['close'])) {

	// Tell each phone to stop multicast Rx/Tx and send the Exit softkey
	foreach($phones as $phone) {
		push2phone($phone, "RTPRx:Stop,RTPTx:Stop,SoftKey:Exit", $user, $pwd);
	}
	die();
}

//
// Check for istalking, if it doesn't exist this must be
// the first time the phone has called this page 
//
if (isset($_GET['istalking'])) {
	$istalking = $_GET['istalking'];
}
else {
	//
	// Must be our initial request for the page, lets tell everyone to listen
	//
	$istalking = "false";
        foreach($phones as $phone) {
		push2phone($phone, "RTPMRx:{$MCAddress}:{$MCPort}", $user, $pwd);
	}
}

// Send headers
header("Content-Type: text/xml");
header("Expires: -1");

// Cisco headers
echo "<CiscoIPPhoneText><Title>PTT</Title><Prompt>Use soft keys to talk</Prompt>";

// PTT operation
if ($istalking !="true") {
	echo "<Text>Press and hold the [Talk] soft key to transmit. If you want to speak continuously without holding a button down, you can press and release the [Lock] soft key.</Text>";

        echo "<SoftKeyItem>";
	echo "<Name>Talk</Name>";
	echo "<URL>RTPTx:Stop</URL>";
	echo "<Position>1</Position>";
	echo "<URLDown>RTPMTx:{$MCAddress}:{$MCPort}</URLDown>";
	echo "</SoftKeyItem>";

	echo "<SoftKeyItem>";
	echo "<Name>Exit</Name>";
	echo "<URL>{$URLBase}ptt.php?name={$MAC}&amp;close</URL>";
	echo "<Position>3</Position>";
	echo "<URLDown>RTPMRx:Stop</URLDown>";
	echo "</SoftKeyItem>";

	echo "<SoftKeyItem>";
	echo "<Name>Lock</Name>";
	echo "<URL>{$URLBase}ptt.php?name={$MAC}&amp;istalking=true</URL>";
	echo "<Position>4</Position>";
	echo "<URLDown>RTPMTx:{$MCAddress}:{$MCPort}</URLDown>";
	echo "</SoftKeyItem>";
} 
// Full-duplex, always on
else {
	echo "<Text>Press and release the [Unlock] soft key to return.</Text>";
	echo "<SoftKeyItem>";
	echo "<Name>Unlock</Name>";
	echo "<URL>$URLBase/ptt.php?name=$MAC&amp;istalking=false</URL>";
	echo "<Position>4</Position>";
	echo "<URLDown>RTPTx:Stop</URLDown>";
	echo "</SoftKeyItem>";
}

echo "</CiscoIPPhoneText>";
