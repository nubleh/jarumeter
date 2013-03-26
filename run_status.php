<?php
	$full_path = realpath('.').'/';
	$data_path = $full_path.'data/data';
	$output = false;
	if(is_file($data_path)){
		$file = fopen($data_path, 'r+');
		if(flock($file, LOCK_EX | LOCK_NB)) {
			flock($file, LOCK_UN);
		} else {
			// failed to get exclusive lock? that means script is running!
			$output = true;
		}
	}
	echo $output;
?>