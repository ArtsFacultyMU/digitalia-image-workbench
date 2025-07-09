<?php
try {
	$current_count_handle = fopen("/var/www/html/api/{$_GET['user_id']}_{$_GET['media_id']}.log.tally", "r");
	$total_count_handle = fopen("/var/www/html/api/{$_GET['user_id']}_{$_GET['media_id']}.log.total", "r");
} catch (Exception $e) {
	print_r(0);
	return;
}

// not ready, we are definetly at 0 %
if (!$current_count_handle || !$total_count_handle) {
	print_r(0);
	return;
}

$current = fgets($current_count_handle);
$total = fgets($total_count_handle);

fclose($current_count_handle);
fclose($total_count_handle);

$percentage = floor(100 * ($current / $total));
print_r($percentage);
?>
