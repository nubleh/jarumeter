<?php
	$host = '192.168.0.1';
	$login_user = 'root';
	$login_pw = 'password';
	$chain_name = 'TRAFFIC';
	$full_path = realpath('.').'/';
	$master_path = $full_path.'data/master';
	$master_expiry = 10; // in seconds
	$clients_path = $full_path.'data/clients';
	$clients_expiry = 20; // in seconds
	$stats_path = $full_path.'data/stats';
	$clients = NULL;
	$stats = array();
	$time = NULL;
	
	if((time()-filemtime($master_path))>$master_expiry){
		//echo '<p>Master inactive. You are now master.<br>';
		//echo $_SERVER['REMOTE_ADDR'];
		$handle = fopen($master_path , "w");
		fwrite($handle, $_SERVER['REMOTE_ADDR']);
	} else {
		if(file_get_contents($master_path) != $_SERVER['REMOTE_ADDR']){
			//echo '<p>You are not master.';
			echo file_get_contents($stats_path);
			//error_log('Giving cache to '.$_SERVER['REMOTE_ADDR']);
			return;
		}
	}
	
	// renew this master's hold
	$handle = fopen($master_path , "w");
	fwrite($handle, $_SERVER['REMOTE_ADDR']);
	
	$loginstart = microtime(true);
	$connection = ssh2_connect($host, 22);
	if (!$connection) die('Connection failed');
	//error_log("Logged in for ".$_SERVER['REMOTE_ADDR']." took ".(microtime(true) - $loginstart));

	
    if (ssh2_auth_password($connection, $login_user, $login_pw)) {
		$timestart = microtime(true);
		//echo "Authentication Successful!\n";

		if((time()-filemtime($clients_path))>$clients_expiry){
			//echo '<p>Clients list not found or out of date.';

			// get all active IPs in the LAN
			$data = run_get_output($connection, "cat /proc/net/ip_conntrack");
			preg_match('/\d+\.\d+\.\d+\./', $host, $match);
			$subnet = $match[0];
			$pattern = '/'.$subnet.'\d+/';
			preg_match_all($pattern, $data, $match);
			$clients = array_unique($match[0]);
			
			// remove special IPs
			if(($key = array_search($host, $clients)) !== false) unset($clients[$key]);
			if(($key = array_search($subnet.'255', $clients)) !== false) unset($clients[$key]);
			
			$handle = fopen($clients_path , "w");
			fwrite($handle, json_encode($clients));
		} else {
			$clients = (array) json_decode(file_get_contents($clients_path));
			//echo '<p>Clients list up to date.';
		}		
		//echo '<pre>'.print_r($clients, true).'</pre>';
		
		// check for iptables chains
		$data = run_get_output($connection, "iptables -v -n -x -L ".$chain_name);
		if(strlen($data)<1){
			//echo 'Chain \''.$chain_name.'\' not found! Creating now...';
			// create the chain
			run_get_output($connection, "iptables -N ".$chain_name."\niptables -A FORWARD -j ".$chain_name);
			// add client rules to the chain
			foreach($clients as $client){
				run_get_output($connection, "iptables -A ".$chain_name." -s ".$client);
				run_get_output($connection, "iptables -A ".$chain_name." -d ".$client);
			}
			$data = run_get_output($connection, "iptables -v -n -x -L ".$chain_name);
		}
		$time = microtime(true);
		//echo '<pre>'.$data.'</pre>';
		
		$data = preg_split('/\n/', $data);
		array_shift($data); // remove the headers
		array_shift($data);
		array_pop($data); // remove extra line
		
		
		$missing_dl = array();
		foreach($clients as $client){
			$missing_dl[$client] = 1;
		}
		$missing_ul = $missing_dl;
		//echo '<pre>'.print_r($missing_dl, true)."</pre>";
		// example of line:
		//        0        0            all  --  *      *       192.168.0.103        0.0.0.0/0
		//
		$pattern = '/\s+\S+\s+(\S+)\s+\S+\s+\S+\s+\S+\s+\S+\s+(\S+)\s+(\S+)/';
		$downloads = array();
		$uploads = array();
		foreach($data as $datum){
			preg_match($pattern, $datum, $match);
			if($match[2] == '0.0.0.0/0'){
				$downloads[$match[3]] = $match[1];
				//echo "<br>Download by ".$match[3]." is ".$match[1];
				unset($missing_dl[$match[3]]);
			} else if($match[3] == '0.0.0.0/0'){
				$uploads[$match[2]] = $match[1];
				//echo "<br>Upload by ".$match[2]." is ".$match[1];
				unset($missing_ul[$match[2]]);
			}
		}
		
		$stats['clients'] = $clients;
		$stats['downloads'] = $downloads;
		$stats['uploads'] = $uploads;
		$stats['time'] = $time;
		$stats['execution_time'] = (microtime(true) - $timestart);
		$stats_json = json_encode($stats);
		echo $stats_json;
		$handle = fopen($stats_path , "w");
		fwrite($handle, $stats_json);
		
		// add rules for missing clients
		foreach($missing_dl as $key=>$value){
			run_get_output($connection, "iptables -A ".$chain_name." -d ".$key);
		}
		foreach($missing_ul as $key=>$value){
			run_get_output($connection, "iptables -A ".$chain_name." -s ".$key);
		}
		
		//echo '<p>All of that took '.(microtime(true) - $timestart);
	} else {
		die('Authentication Failed...');
    }
	
	function run_get_output($ssh, $command){
		$stream = ssh2_exec($ssh, $command, NULL);
		stream_set_blocking($stream, true);
		$data = '';
		while($buffer = fread($stream, 4096)) {
			$data .= $buffer;
		}
		fclose($stream);
		return $data;
	}
?>
