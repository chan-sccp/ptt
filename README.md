PHP Helper script to Setup Push2Talk for 792X
=====================

Here's a basic PTT script, please feel free to modify and expand.  I know it has flaws :)

To use the script you'll need PHP on a webserver accessible by the phones.  You'll need to add this to the <VendorConfig> section of your cnf.xml files as well

```xml
<device>
...
   <vendorConfig>
   ...
   <webAccess>0</webAccess>
   <PushToTalkURL>http://webserver/path/ptt.php</PushToTalkURL>
   ...
   </vendorConfig>
...
</device>
<authenticationURL>http://webserver/path/authorize.php</authenticationURL>
```

Note:
the <authenticationURL> in SEP<MAC>.cnf.xml has to be set and should return AUTHORIZED for the user/pwd defined in the script below (see: [[Setup phone authorization]]). 

ptt.php see [push2talk](https://github.com/chan-sccp/ptt/)
```php
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
