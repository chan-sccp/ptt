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
the <authenticationURL> in SEP<MAC>.cnf.xml has to be set and should return AUTHORIZED for the user/pwd defined in the script below (see: [Setup phone authorization](https://github.com/chan-sccp/chan-sccp/wiki/Setup-phone-authorization). 

You can also use push2talk on other Cisco Device that don't have a Push-to-Talk button. You just add the ptt.php script to your services page, to make it accessible
to the user.
