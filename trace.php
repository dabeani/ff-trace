<?php

/*
1) traceroute from Funkfeuer housing to given destination IP (within Funkfeuer network)
2) add node information from Funkfeuer Node-Database
3) try to retrieve and display status and connection information from 
   - EdgeRouter - possible if status-php is installed
   - Custom-Firmware Ubiquiti Devices

*/


error_reporting(1);

function parse_field($in, $tag_names) {
  $out = array();
  if (strlen($in) < 2) { return $out; }
  $dev_string = split("\|", $in);
  for ($i = 0; $i < count($dev_string) ; $i++) {
    $dev_info = split("\,", $dev_string[$i]);
    for ($y = 0; $y < count($dev_info) ; $y++) {
      $out[$i][$tag_names[$y]] = $dev_info[$y];
    }
  }
  return $out;
} 

function parse_line($in) {
  $tag_list = array('name','type','nodeid','nodetype','lat','lon','realname','email','address','devices','links','note','tech_c');
  $out = array();
  foreach ($tag_list as $tag) {
    $pos1=strpos($in, ' '.$tag.'="') +3+strlen($tag);
    $pos2=strpos($in, '"',$pos1);  
    $out[$tag] = trim(substr($in, $pos1, $pos2-$pos1));
  }
  if ($out['tech_c']=='me=') { $out['tech_c']=''; }
  return $out;
} 

$handle = fopen('https://map.funkfeuer.at/wien/data.php', "r");
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (strpos ($line, '<node ')) {
           $node = parse_line($line);
           if ($node['type']!=='active') { continue; }
		   $node['devices_list'] = parse_field($node['devices'], array('name','ip','note'));
		   $node['links_list']   = parse_field($node['links']  , array('name','remote','remote_node','ncq'));
		   $node_db[strtolower($node['name'])] = $node;
        }
    }
    fclose($handle);
} else {
    // error opening the file.
    echo "error loading data.php";
} 	
unset($handle);


function format_uptime($in) {
   if      ($in         < 100) { return $in.'sec'; }
   elseif  ($in/60      < 100) { return round($in/60 ,0) .'min'; }
   elseif  ($in/60/60   < 50) { return round($in/60/60 ,0) .'hrs'; }
   elseif  ($in/60/60/24< 100) { return round($in/60/60/24 ,0) .'dys'; }
   else { return round($in/60/60/24/30 ,0) .'months'; }
}

echo "<html><head><title>Funkfeuer Trace to ".$_GET["targetip"]."</title><meta name='viewport' content='width=800'>";
echo "<style>* { font-family: Verdana, Helvetica, Optima, Helvetica Neue, Monaco, Andale Mono, Georgia;\n font-size: 9pt; }\n</style>\n";
?>
<script type="text/javascript" src="js/jquery-1.11.3.min.js"></script>
<script type="text/javascript">			
function getTrace() {
	var TargetIP = document.getElementById("targetip").value;
	if (!window.XMLHttpRequest){
        console.log("Your browser does not support the native XMLHttpRequest object.");
        return;
    }
	try {
            var xhr = new XMLHttpRequest();  
            xhr.onerror = function() { log_message("[XHR] Fatal Error."); };
            xhr.onreadystatechange = function() {
                try {
                    if (xhr.readyState > 2) {
                        document.getElementById("result").innerHTML = xhr.responseText;
                    }   
                }
                catch (e) {
                    console.log("<b>[XHR] Exception: " + e + "</b>");
                }
            };
            xhr.open("GET", "trace.php?targetip="+TargetIP+"&inputbox=0", true);
            xhr.send("Making request...");      
        }
        catch (e) {
            console.log("<b>[XHR] Exception: " + e + "</b>");
        }
}				
</script>
<?
echo "</head><body>\n";
if(!isset($_GET["inputbox"])) {
?>
<input type="text" value="" name="targetip" id="targetip">&nbsp;<input onclick="getTrace();"type="button" value="Fetch"/>
<?
}
//if(!isset($_GET["targetip"])) {
//	echo "parameter(targetip) fehlt...<br>";
//	exit;
//}
echo "<div id=\"result\">\n";
if(isset($_GET["targetip"])) {
	
	// IP-Check... funkfeuer ip-adresses are useable...
	$APP["78er_range_low"] = ip2long("78.41.112.1");
	$APP["78er_range_high"] = ip2long("78.41.119.254");
	$APP["193er_range_low"] = ip2long("193.238.156.1");
	$APP["193er_range_high"] = ip2long("193.238.159.254");
	
	$APP["ip"] = ip2long($_GET["targetip"]);
	if ($APP["ip"] >= $APP["78er_range_low"] && $APP["ip"] <= $APP["78er_range_high"] or $APP["ip"] >= $APP["193er_range_low"] && $APP["ip"] <= $APP["193er_range_high"]) {
		// do nothing :)
	} else {
		// what to do if in bad IP range
		echo "nicht Erlaubt...";
		echo "</div>\n";
		exit;
	}

	echo "<table width=800>";
	echo "<tr><th width=30>hop</th><th>hostname, ip</th><th width=80>ping</th><th width=80>ping_rel</th><th width=80>AS</th></tr>\n";
	
	$traceroute = array();
	$pfad = array();
	$pings = array();
	
	$start = 0;
	$basis_ping = 0;
	
	$cmd = "traceroute -A ".$_GET["targetip"];
	$descriptorspec = array(
	   0 => array("pipe", "r"),   // stdin is a pipe that the child will read from
	   1 => array("pipe", "w"),   // stdout is a pipe that the child will write to
	   2 => array("pipe", "w")    // stderr is a pipe that the child will write to
	);
	flush();
	$process = proc_open($cmd, $descriptorspec, $pipes, realpath('./'), array());
	if (is_resource($process)) {
	    while ($line = fgets($pipes[1])) {
	
		if (strpos($line, 'raceroute to')>=1) { continue; } // erste Zeile vom output ignorieren
	    // HIER SIND DIE TRACEROUTE-ROHDATEN: hop:2  hostname (ip) [ASxx]  ping
	    $line=str_replace('     ',' ',$line); 
	    $line=str_replace('    ',' ',$line); 
	    $line=str_replace('   ',' ',$line); 
	    $line=str_replace('  ',' ',$line); 
	    $hop = split(" ", trim($line));
		$ip = str_replace('(','',str_replace(')','',$hop[2])); 
	    if (strstr($hop[1], 'tunnelserver.funkfeuer.at')==TRUE) { $basis_ping = floatval($hop[4]); }
	
	    $nodepoint=array();
	
		// hop nummer - zeile 1
	    echo '<tr valign=top>';
		echo '<td rowspan=2>'.$hop[0].'</td>';
	
	    // hostname
		echo '<td colspan=4>';
	    $airos=0;
		$json_a=array();	
		$json_b=array();
		$json_c=array();
		$json_d=array();
		$json_e=array();
		$handle="";
	    if (strstr($hop[1], 'wien.funkfeuer.at')==TRUE) { 
	        // AirOS Status-Zustand abfragen
	        // status.cgi
			try {
				$headers = get_headers("https://".$ip."/status.cgi"); 
			} catch (Exception $e) {
				// handle error here
			}
			if (substr($headers[0], 9, 3) == "200"){
				try {
					$handle = file_get_contents("https://".$ip."/status.cgi"); 
				} catch (Exception $e) {
					// handle error here
				}		
			} else {
				unset($headers);
				unset($handle);
				try {
					$headers = get_headers("https://".$ip."/cgi-bin-status.php?get=status"); 
				} catch (Exception $e) {
					// handle error here
				}
				if (substr($headers[0], 9, 3) == "200"){
					try {
						$handle = file_get_contents("https://".$ip."/cgi-bin-status.php?get=status"); 
					} catch (Exception $e) {
						// handle error here
					}		
				}
			}
	
			if ($handle) {
	           $json_a=json_decode($handle, true);
			   
			   foreach ($json_a['devices'] as $device) {
	             if (strpos($device['fwversion'], 'dgeRouter') >=1) {
	                $airos=2;
					break;
				 }
	           }
	
			   if ($airos!=2) {
	                $airos=1;
					// sta.cgi
					try {
						$headers = get_headers("https://".$ip."/sta.cgi"); 
					} catch (Exception $e) {
						// handle error here
					}		
					if (substr($headers[0], 9, 3) == "200"){
						try {
							$handle2 = file_get_contents("https://".$ip."/sta.cgi"); 
						} catch (Exception $e) {
							// handle error here
						}		
	
						if ($handle2) {
						   $json_b=json_decode($handle2, true);
						}
					}
				}
			} else {
				$airos=0;
			}
			unset($headers);
			unset($handle);
			unset($handle2);

			
			$nodepoint = split("\.", $hop[1]); 
			
			// check auf korrekten dns-eintrag aus node_db
			// check gegen node_db hinsichtlich MID/HNA IP-Adresse
			// ANNAHME: nodename laut DNS stimmt mit nodename laut marvin überein (verschobene Nodes werden so nicht gefunden)
			// zuerst den device-eintrag raussuchen
			$mid_hna_string='';
            $dns;
			$dns_real='<font style="color:#dd3333">dns changed node?</font>';
			foreach ($node_db[strtolower($nodepoint[1])]['devices_list'] as $device) {
			  if ($device['ip'] == $ip) {
			    $dns = $device['name'];
				if (strtolower($dns) == strtolower($nodepoint[0].'.'.$nodepoint[1])) {
					// names match completely
					$dns_real='';
				} else {
					$dns_real='<b>'.substr($dns,0,strpos($dns, '.')).'</b>';
				}
				// match auf IP, jetzt prüfen ob diese IP eine secondär-addresse (MID) oder ein HNA ist
				if ((strpos($device['note'], 'MID:') !== FALSE) || (strpos($device['note'], 'HNA:') !== FALSE)) {
					$mid_hna_string=' ('.$device['note'].'';
					// IP vom MID/HNA suchen und andrucken "MID: <NAME>"
					foreach ($node_db[strtolower($nodepoint[1])]['devices_list'] as $mid) {
						if (($mid['name']) == substr($device['note'],5) ) {
						   $mid_hna_string.='='.$mid['ip'];
						   break; // gefunden
						}
					}
					$mid_hna_string.=') ';  
				  }
				break; // gefunden
			  }
			}
			
	        echo '<b><font style="color:#337733">'.$nodepoint[0].'</font>.<font style="color:#ff7777">'.$nodepoint[1].'</font></b>.wien.funkfeuer.at';
	        echo ' (<a href="mailto:'.$node_db[strtolower($nodepoint[1])]['email'].'">'.$node_db[strtolower($nodepoint[1])]['realname'].'</a>)';
			echo ' '.$dns_real;
	        if (array_search(strtolower($nodepoint[1]), $pfad, true) > -1) {
	          // checke ping-wert
	            if ($pings[strtolower($nodepoint[1])] < (floatval($hop[4])-$basis_ping)) { $pings[strtolower($nodepoint[1])] = (floatval($hop[4])-$basis_ping); }
	        } else { 
	            array_push($pfad, strtolower($nodepoint[1])); 
	            $pings[strtolower($nodepoint[1])] = (floatval($hop[4])-$basis_ping);
	        }
			$remote_mac='';
			$own_mac = '';
			$eth_speed='';
			if ($airos==1) {
			   // take own mac
	
			   for ($i = 0; $i < count($json_a['interfaces']) ; $i++) {
				 if ($json_a['interfaces'][$i]['ifname'] == 'wifi0') { 
				   $own_mac = $json_a['interfaces'][$i]['hwaddr'];
				 } elseif ($json_a['interfaces'][$i]['ifname'] == 'eth0') { 
				   $eth_speed = $json_a['interfaces'][$i]['status']['speed'];
	             }
	           }
	           // try to find remote mac for stations
	
			   if ($json_a['wireless']['mode'] == 'sta') { 
			      $remote_mac = $json_a['wireless']['apmac'];
			   } elseif (($json_a['wireless']['mode'] == 'ap') && ($json_a['wireless']['count']==1)) {
				  $remote_mac = $json_b[0]['mac'];
			   } elseif ($json_a['wireless']['mode'] == 'ap') {
			      // search for correct station, take 0 as default
				  // improve search method!!
				  $remote_mac = '??';
			   }
	
	           echo '<br>';
			   echo '<table style="color:#33aadd;">';
			   echo '<tr><td>';
			   echo '<b>'.$json_a['host']['devmodel'].'</b> ';
			   echo ''.$json_a['host']['hostname'].' ';
			   echo '('.$json_a['host']['fwversion'].') ';
			   echo ' '.$own_mac.'';
			   echo ' '. format_uptime($json_a['host']['uptime']).' ';		   
			   echo '<br>';
	
			   echo ' '.strtoupper($json_a['wireless']['mode']);
			   if ($json_a['wireless']['mode'] == 'ap') { 
			      echo '(<b>'.$json_a['wireless']['count'].' Link';
			      if ($json_a['wireless']['count'] > 1 ) { echo 's'; }
				  echo '</b>)';
			   }
			   echo ':'.$json_a['wireless']['essid'].' ';
			   echo ' '.$json_a['wireless']['channel'].'/';
			   echo ''.str_replace(' ','',$json_a['wireless']['frequency']).' ';
			   echo ' '.strtolower($json_a['wireless']['opmode']).' ';
	           echo ' '.$eth_speed.' ';
	           echo '</td></tr>';
	
			   if ($json_b) {
	             echo '<tr><td>';
	             for ($w=0; $w < count($json_b); $w++) {
				   echo '-> '.$json_b[$w]['name'].' ('.$json_b[$w]['mac'].'|'.$json_b[$w]['lastip'].') - ';
				   echo 'tx/rx:<b>'.$json_b[$w]['tx'].'/'.$json_b[$w]['rx'].'</b> - ';
				   echo 's/n:'.$json_b[$w]['signal'].'/'.$json_b[$w]['noisefloor'].' ';
				   echo '<b>'.$json_b[$w]['ccq'].'%</b> - ';
				   echo 'pwr:'.$json_b[$w]['txpower']/2 .' ';
	               echo 'up:'. format_uptime($json_b[$w]['uptime']).'';
	               echo ', '.round($json_b[$w]['distance']/1000 ,1).'km ';
	             }
	             echo '</td></tr>';
	           }
			   echo '</table>';
	
			   /*
			   echo $json_a['wireless']['signal'].'/'.$json_a['wireless']['noisef'];
			   echo ', <b>t'.round($json_a['wireless']['txrate']).'/r'.round($json_a['wireless']['rxrate']).'</b>';
			   echo ', <b>'.($json_a['wireless']['ccq'] / 10).'%</b>';
			   if ($json_a['wireless']['mode'] == 'ap') { 
			      echo ', AP: <b>'.$json_a['wireless']['count'].' Link';
			      if ($json_a['wireless']['count'] > 1 ) { echo 's'; }
				  echo '</b>';
			   }
			   if ($json_b) {
			     echo ', next(?): '.$json_b[0]['mac'].' '.$json_b[0]['name'];
			   } elseif (($json_a['wireless']['mode'] == 'ap') && ($json_a['wireless']['count'] == 1 )) {
			     echo ', next: '.$json_a['interfaces'][1]['hwaddr'];
			   } else {
			     echo ', next: '.$json_a['wireless']['apmac'];
			   }
			  // echo ' Name: <b>'.$json_a['host']['hostname'].'</b>';
			   if ($json_a['host']['devmodel']) {echo ', Dev: <b>'.$json_a['host']['devmodel'].'</b>';}
			  //echo ', TX: <b>'.$json_b[0]['txpower'].'</b>';
			  */
			   
			   
			} elseif ($airos==2) {
	           echo '<br>';
			   echo '<table style="color:#33bb88;">';
	           for ($i = 0; $i < count($json_a['devices']); $i++) {
				 $fw=array();
		         $fwstring='';
				 $json_c=array();
				 $json_d=array();

			     if ( strpos(' '.$json_a['devices'][$i]['fwversion'] ,'EdgeRouter')>0 )	 { continue;  }
				 //crop firmware version
				 $fw = split("\.", $json_a['devices'][$i]['fwversion']); 
			     $fwstring = $fw[2];
				 for ($f = 3; $f < count($fw); $f++) {
				   if (strlen($fw[$f]) >= 5 ) {break;}
				   $fwstring.='.';
				   $fwstring.=$fw[$f];
				 }
				 // port berechnen 1000 + letztes octet aus 10er-ip
			     $port=1000+(int)substr($json_a['devices'][$i]['ipv4'], 1+strrpos($json_a['devices'][$i]['ipv4'], '.'));

			     if ( strpos( $fwstring ,'FF')>0 )	 {
				     // appears to be custom Funkfeuer firmware
					 // check for status.cgi
					 
					try {
						$headers = get_headers("https://".$ip.":".$port."/status.cgi"); 
					} catch (Exception $e) {
						// handle error here
					}
					if (substr($headers[0], 9, 3) == "200"){
						try {
							$handle = file_get_contents("https://".$ip.":".$port."/status.cgi"); 
						} catch (Exception $e) {
							// handle error here
						}		
					}
					if ($handle) {
						$json_c=json_decode($handle, true);
						
						// sta.cgi
						try {
							$headers = get_headers("https://".$ip.":".$port."/sta.cgi"); 
						} catch (Exception $e) {
							// handle error here
						}		
						if (substr($headers[0], 9, 3) == "200"){
							try {
								$handle2 = file_get_contents("https://".$ip.":".$port."/sta.cgi"); 
							} catch (Exception $e) {
								// handle error here
							}		
		
							if ($handle2) {
							   $json_d=json_decode($handle2, true);
							}
						}
					}
					 
				 }
				 
				 $json_e[$i]['status']=$json_c;
				 $json_e[$i]['sta']   =$json_d;
				 
			     echo '<tr><td>';
		         echo $i.'</td><td> '.$json_a['devices'][$i]['hostname'].' ';
	             echo '<br>';			 
		         echo ' ('.$json_a['devices'][$i]['wmode'];
				 if ($json_a['devices'][$i]['wmode']=='3') {
				   echo '-AP';
				 } elseif ($json_a['devices'][$i]['wmode']=='2') {
				   echo '-STA';
				 }
				 echo ') ';
		         echo '<b>'.$json_a['devices'][$i]['essid'].'</b> ';
				 echo '</td><td>';
		         echo ' '.$json_a['devices'][$i]['ipv4'];
				 
                 echo ' - <a href="https://'.$ip.':'.$port.'" target="_blank">'.$port.'</a> ';
				 
				 echo '<br>';
		         echo ' '.$json_a['devices'][$i]['hwaddr'].' ';
				 echo '</td><td>';
		         echo '<b>'.$json_a['devices'][$i]['product'].'</b><br>';
				 
				 echo $fwstring;
				 echo '</td><td>';
	             echo ' up: '. format_uptime($json_a['devices'][$i]['uptime']).'<br>';

				 // channel number from status.cgi
				 if ($json_c) {
				     echo '<font style="color:#33aadd;">';
					 echo ' '.$json_c['wireless']['channel'].'/';
					 echo ''.str_replace(' ','',$json_c['wireless']['frequency']).' ';
					 echo '</font>';
				 }
				 echo '</td>';
				 if ($json_c) {
					 echo '<td>';	
				     echo '<font style="color:#33aadd;">';
				   echo 'tx/rx:<b>'.round($json_c['wireless']['txrate']).'/'.round($json_c['wireless']['rxrate']).'</b>';
				   if ($json_c['wireless']['mode'] == 'ap') { 
					  echo ', AP: '.$json_c['wireless']['count'].' Link';
					  if ($json_c['wireless']['count'] > 1 ) { echo 's'; }
				   }
				   echo '<br>';	 
				   echo 's/n:'.$json_c['wireless']['signal'].'/'.$json_c['wireless']['noisef'];
				   echo ', <b>'.($json_c['wireless']['ccq'] / 10).'%</b>';
					 echo '</font>';
					 echo '</td>';	
				 }
				 echo '</tr>';	
			   
			   // ----------------------
			   if ($json_c) {
					$remote_mac='';
					$own_mac_w = '';
					$eth_speed_w='';
				   // take own mac and lan speed (loop interfaxes
				   for ($g = 0; $g < count($json_c['interfaces']) ; $g++) {
					 if ($json_c['interfaces'][$g]['ifname'] == 'wifi0') { 
					   $own_mac_w = $json_c['interfaces'][$g]['hwaddr'];
					 } elseif ($json_a['interfaces'][$g]['ifname'] == 'eth0') { 
					   $eth_speed_w = $json_c['interfaces'][$g]['status']['speed'];
					 }
				   }

				   // try to find remote mac for stations
				   if ($json_c['wireless']['mode'] == 'sta') { 
					  $remote_mac_w = $json_a['wireless']['apmac'];
				   } elseif (($json_c['wireless']['mode'] == 'ap') && ($json_c['wireless']['count']==1)) {
					  $remote_mac_w = $json_d[0]['mac'];
				   } elseif ($json_c['wireless']['mode'] == 'ap') {
					  // search for correct station?
					  $remote_mac_w = '?multible?';
				   }
		
				   echo '<tr><td colspan=6>';  // modified
				   echo '<table style="color:#33aadd;">';
				   echo '<tr><td>';
				   echo '<b>'.$json_c['host']['devmodel'].'</b> ';
				   echo ''.$json_c['host']['hostname'].' ';
				   echo '('.$json_c['host']['fwversion'].') ';
				   echo ' '.$own_mac_w.'';
				   echo ' '. format_uptime($json_c['host']['uptime']).' ';		   
				   echo '<br>';
		
				   echo ' '.strtoupper($json_c['wireless']['mode']);
				   if ($json_c['wireless']['mode'] == 'ap') { 
					  echo '(<b>'.$json_c['wireless']['count'].' Link';
					  if ($json_c['wireless']['count'] > 1 ) { echo 's'; }
					  echo '</b>)';
				   }
				   echo ':'.$json_c['wireless']['essid'].' ';
				   echo ' '.$json_c['wireless']['channel'].'/';
				   echo ''.str_replace(' ','',$json_c['wireless']['frequency']).' ';
				   echo ' '.strtolower($json_c['wireless']['opmode']).' ';
				   echo ' '.$eth_speed_w.' ';
				   echo '</td></tr>';
		
				   if ($json_d) {
					 echo '<tr><td>';
					 for ($w=0; $w < count($json_d); $w++) {
					   echo '-> '.$json_d[$w]['name'].' ('.$json_d[$w]['mac'].'|'.$json_d[$w]['lastip'].') - ';
					   echo 'tx/rx:<b>'.$json_d[$w]['tx'].'/'.$json_d[$w]['rx'].'</b> - ';
					   echo 's/n:'.$json_d[$w]['signal'].'/'.$json_d[$w]['noisefloor'].' ';
					   echo '<b>'.$json_d[$w]['ccq'].'%</b> - ';
					   echo 'pwr:'.$json_d[$w]['txpower']/2 .' ';
					   echo 'up:'. format_uptime($json_d[$w]['uptime']).'';
					   echo ', '.round($json_d[$w]['distance']/1000 ,1).'km ';
					 }
					 echo '</td></tr>';
				   }
				   echo '</table>';
				   
				   echo '</td></tr>';
			   			   
			   } // ------------------------

			   }
			   echo '</table>';
		   }
			  
		} else {
			echo  $hop[1];
	    }	
		echo '</td>';
		echo '</tr>';
		
		
	    // IP-Adresse - zeile 2
		echo '<tr>';
		echo '<td style="color:#aaaaaa"><a href="http://'.$ip.'" target="_blank">'.$ip.'</a>';
		echo $mid_hna_string;
		
		// wenn edgerouter: links für antennen mit portnummern eintragen
		if ($airos==2) {
		   for ($i = 0; $i < count($json_a['devices']); $i++) {
			 if ( strpos(' '.$json_a['devices'][$i]['fwversion'] ,'EdgeRouter')>0 )	 { continue;  }
			 // port berechnen 100 + letztes octet aus 10er-ip
			 $port=1000+(int)substr($json_a['devices'][$i]['ipv4'], 1+strrpos($json_a['devices'][$i]['ipv4'], '.'));
             echo ' - <a href="https://'.$ip.':'.$port.'" target="_blank">'.$port.'</a> ';

		   }
		}
		
		echo '</td>';
	
	    // PING absolut
		echo '<td align=right style="color:#aaaaaa">'.$hop[4].'</td>';
	
	    // PING relativ
		echo '<td align=right style="color:#6666ff">';
	    if ($basis_ping > 0) {	
		   $pingrel=round((floatval($hop[4])-$basis_ping) ,3);
		   echo $pingrel;
		} else {
		  $pingrel=0;
		}  
	    echo '</td>';
	
	    // AS-Nummer
	    echo '<td>'.$hop[3].'</td>';
	    
		
		// Abschluss
		echo '</tr>';
	    echo "\n";
	
		if ($json_a) { 
		   $host_uptime = $json_a['host']['uptime'] ;
		   $host_device = $json_a['host']['devmodel'] ;
		   $mode = $json_a['wireless']['mode'];
		   
		} else {
		   $host_uptime = '';
		   $host_device = '';
		   $mode = '';
		}
		
		// push traceroute data to own array!
	    array_push($traceroute, array('hop'=>$hop[0],
	                                  'ip'=>$ip,
	                                  'hostname'=>$hop[1],
	                                  'ping'=>$hop[4],
	                                  'pingrel'=>$pingrel,
	                                  'asnr'=>$hop[3],
	                                  'node'=>$nodepoint[1],
	                                  'device'=>$nodepoint[0],
									  'devmodel'=>$host_device,
									  'up'=>'',
									  'down'=>'',
									  'mac'=>$own_mac,
									  'remote_mac'=>$remote_mac,
									  'mode'=>$mode,
									  'host_uptime'=>$host_uptime,
									  'rx'=>'',
									  'tx'=>'',
									  'eth_speed'=>$eth_speed,
									  'airos_status'=>$json_a,
									  'airos_sta'=>$json_b,
									  'edge_dev'=>$json_e
	    )); 
	    // $traceroute[X][edge_dev][D][status] shows airos_status from $traceroute[X][airos_status][devices][D] fro edgerouter nodes
	
	
		// loop through all hops and extract:
		// device model, antenna, wifi-mode, channel
		// access point, station?
		// eth-speed
		// next hop is on same node? --> wifi goes towards web
	    // next hop is on different node 
	    // --> prev. node is same node --> wifi goes towards client	
		// --> prev. node is also different node --> multi-wifi-device /router, rb, grids, bridges...?
		// mac address from peer
		// update of device and update of wifi-connection
		// speed and quality of wifi connection
		
		
		ob_flush();
	    flush();
	  //}
	 }
	}
	ob_end_flush();
	echo "</table>\n<p>";
	
	
	
	// table 2 - pings for nodes
	echo '<table cellspacing=10><tr>';
	echo '<td>vpn<br>';
	echo '<div style="color:#aaaaff">0<br>0</div></td>';
	$last = 0;
	
	foreach ($pfad as $pfadpunkt) {
	   echo '<td><b>'.$pfadpunkt.'</b><br>';
	   echo '<div style="color:#6666ff">'.round($pings[$pfadpunkt],3).'</div><div style="color:#ff7777">' . round(floatval($pings[$pfadpunkt]-$last),3) . '</div></td>';
	   $last = $pings[$pfadpunkt]; 
	}
	echo "</tr></table>\n";
	
	
	// table 3 - device path
	echo '<table cellspacing=10>';	
	for ($i = 0; $i < count($traceroute) ; $i++) {
	
	  $node_next = strtolower($traceroute[$i+1]['node']);
	  $node_this = strtolower($traceroute[$i  ]['node']);
	  $node_prev = strtolower($traceroute[$i-1]['node']);
	  if (($node_prev != $node_this)) { $traceroute[$i]['up']   = 1; }
	  if (($node_this != $node_next) && ($node_next)) { $traceroute[$i]['down'] = 1; }
	
	  if ($traceroute[$i]['mode'] == 'sta') { 
	    $traceroute[$i]['rx'] = $traceroute[$i]['airos_sta'][0]['rx'];     
	    $traceroute[$i]['tx'] = $traceroute[$i]['airos_sta'][0]['tx'];     
	  } elseif ($traceroute[$i]['mode'] == 'ap') {
	    for ($c = 0; $c < count($traceroute[$i]['airos_sta'][$c]) ; $c++) {
	       // is this station the prev/next mac?
		   if (($traceroute[$i]['down']==1) && ($traceroute[$i]['airos_sta'][$c]['mac']==$traceroute[$i+1]['mac'])) {
		     $traceroute[$i]['remote_mac']=$traceroute[$i]['airos_sta'][$c]['mac'];
			 $traceroute[$i]['rx']= $traceroute[$i]['airos_sta'][$c]['rx'];
			 $traceroute[$i]['tx']= $traceroute[$i]['airos_sta'][$c]['tx'];
	  	   } 
		 }
	  }
	  
	   if  ($i==0) {
		  // print header
		  echo '<tr>';
	      foreach ($traceroute[$i] as $spalte=>$value) {
	        echo '<th>'.$spalte.'</th>';
	      }
		  echo '</tr>';
	   }
	
	
	  echo '<tr>';
	  foreach ($traceroute[$i] as $spalte=>$value) {
	    if ($spalte=='airos_status') {break;} 
		echo '<td>'.$value.'</td>';
	  }
	  
	  
	  // --> mac-partner vorher/nachher?
	  // --> node gleich/anders vorher/nachher?
	   
	  echo "</tr>";
	}
	echo "</table>\n";
	
	
	echo "<pre>\n";
	print_r($traceroute);
	echo "</pre>\n";
}
echo "</div>\n";
echo "</body></html>\n";

?>
