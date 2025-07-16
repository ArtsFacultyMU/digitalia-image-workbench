<?php
$current_count_path = "/var/www/html/api/{$_GET['user_id']}_{$_GET['media_id']}.log.tally";
$total_count_path = "/var/www/html/api/{$_GET['user_id']}_{$_GET['media_id']}.log.total";

// not ready, we are definetly at 0 %
if (!file_exists($current_count_path) || !file_exists($total_count_path)) {
	print_r(0);
	return;
}

$current_count_handle = fopen($current_count_path, "r");
$total_count_handle = fopen($total_count_path, "r");

$current = fgets($current_count_handle);
$total = fgets($total_count_handle);

fclose($current_count_handle);
fclose($total_count_handle);

$percentage = floor(100 * ($current / $total));
print_r($percentage);
?>
