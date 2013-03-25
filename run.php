<?php
	$host = '192.168.0.1';
	$login_user = 'root';
	$login_pw = 'password';
	$chain_name = 'TRAFFIC';
	$full_path = realpath('.').'/';
	$clients_path = $full_path.'data/clients';
	$stats_path = $full_path.'data/data';
	$clients = NULL;
	$stats = array();
	$time = NULL;
	
	ignore_user_abort(true); 
	set_time_limit(0);
	$file = fopen($stats_path, 'w');
	if(flock($file, LOCK_EX | LOCK_NB)) {
	
		$loginstart = microtime(true);
		$connection = ssh2_connect($host, 22);
		if (!$connection) die('Connection failed');
		$logintime = microtime(true) - $loginstart;
		
		if (ssh2_auth_password($connection, $login_user, $login_pw)) {
			while(1){
			
				$timestart = microtime(true);
				// get all active IPs in the LAN
				$data = run_get_output($connection, "cat /proc/net/ip_conntrack");
				preg_match('/\d+\.\d+\.\d+\./', $host, $match);
				$subnet = $match[0];
				$pattern = '/'.$subnet.'\d+/';
				preg_match_all($pattern, $data, $match);
				$clients = array_unique($match[0]);

				// log the ip_conntrack
				fwrite(fopen('data/ip_conntrack' , "w"), $data);
				
				// remove special IPs
				if(($key = array_search($host, $clients)) !== false) unset($clients[$key]);
				if(($key = array_search($subnet.'255', $clients)) !== false) unset($clients[$key]);
				
				$handle = fopen($clients_path , "w");
				fwrite($handle, json_encode($clients));
				
				// check for iptables chains
				$data = run_get_output($connection, "iptables -v -n -x -L ".$chain_name);
				if(strlen($data)<1){
					// chain doesn't exist. create the chain
					run_get_output($connection, "iptables -N ".$chain_name."\niptables -A FORWARD -j ".$chain_name);
					// add client rules to the chain
					foreach($clients as $client){
						run_get_output($connection, "iptables -A ".$chain_name." -s ".$client);
						run_get_output($connection, "iptables -A ".$chain_name." -d ".$client);
					}
					$data = run_get_output($connection, "iptables -v -n -x -L ".$chain_name);
				}
				
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
				// add rules for missing clients (why am i doing ul and dl separately?)
				foreach($missing_dl as $key=>$value){
					run_get_output($connection, "iptables -A ".$chain_name." -d ".$key);
				}
				foreach($missing_ul as $key=>$value){
					run_get_output($connection, "iptables -A ".$chain_name." -s ".$key);
				}				
				
				$stats['clients'] = $clients;
				$stats['downloads'] = $downloads;
				$stats['uploads'] = $uploads;
				$stats['execution_time'] = (microtime(true) - $timestart);
				$stats['ssh_login_time'] = $logintime;
				$stats['process_pid'] = getmypid();
				$stats_json = json_encode($stats);
				
				ftruncate($file, 0);
				fseek($file, 0);
				fwrite($file, $stats_json);
				sleep(1);
			}
		} else {
			die('Authentication Failed...');
		}
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