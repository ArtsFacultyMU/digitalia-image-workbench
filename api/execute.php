<?php

// TODO: complex content types

function delete_recursive($path)
{
	if (is_file($path)) {
		return unlink($path);
	} elseif (is_dir($path)) {
		$scan = glob(rtrim($path, '/').'/*');

		foreach ($scan as $index => $subpath) {
			delete_recursive($subpath);
		}

		return rmdir($path);
	}
}

function update_media_sip($timestamp, $base_path, $media_id, $state, &$all_files)
{
	$parsed_config_sip = yaml_parse_file("/var/lib/nginx/workbench/workbench_media_base.yml");

	$parsed_config_sip['username'] = getenv("DRUPAL_USERNAME");
	$parsed_config_sip['password'] = getenv("DRUPAL_PASSWORD");
	$parsed_config_sip['input_dir'] = $base_path;
	//$input_dir_path = "{$base_path}/input_dir/{$timestamp}/{$sip_directory_name}";
	$update_header = [
		"media_id",
		"field_ingest_state"	
	];

	$update_data = [
		$media_id,
		$state
	];

	$parsed_config_sip['input_csv'] = "{$timestamp}_sip.csv";
	yaml_emit_file("{$base_path}/{$timestamp}_sip.yml", $parsed_config_sip);

	$sip_csv = fopen("{$base_path}/{$timestamp}_sip.csv", "w");

	fputcsv($sip_csv, $update_header);
	fputcsv($sip_csv, $update_data);
	fclose($sip_csv);

	$all_files["{$base_path}/{$timestamp}_sip.csv"] = 1;
	$all_files["{$base_path}/{$timestamp}_sip.yml"] = 1;

	$command = "/var/lib/nginx/islandora_workbench/workbench --config {$base_path}/{$timestamp}_sip.yml";
	$command .= " 2>&1";

	$ingest_output = array();
	$retval;
	$last_line = exec($command, $ingest_output, $retval);
}

function init_db()
{
	$parsed_config = yaml_parse_file("/var/lib/nginx/workbench/init_db_base.yml");
	//$parsed_config['username'] = getenv("DRUPAL_USERNAME");
	$parsed_config['username'] = "workbench";
	$parsed_config['password'] = getenv("DRUPAL_PASSWORD");

	yaml_emit_file("/var/lib/nginx/workbench/init_db.yml", $parsed_config);

	$command_create = "/var/lib/nginx/islandora_workbench/workbench --config /var/lib/nginx/workbench/init_db.yml";
	$command_delete = "/var/lib/nginx/islandora_workbench/workbench --config /var/lib/nginx/workbench/init_rollback.yml";

	$output = array();
	$retval;
	$last_line = exec($command_create, $output, $retval);

	$parsed_config = yaml_parse_file("/var/lib/nginx/workbench/init_rollback.yml");
	$parsed_config['username'] = getenv("DRUPAL_USERNAME");
	$parsed_config['password'] = getenv("DRUPAL_PASSWORD");

	yaml_emit_file("/var/lib/nginx/workbench/init_rollback.yml", $parsed_config);

	if ($retval == 0) {
		$last_line = exec($command_delete, $output, $retval);
	}
	//$db = new SQLite3("/tmp/csv_id_to_node_id_map.db", SQLITE3_OPEN_READONLY);

}

$parsed_config = yaml_parse_file("/var/lib/nginx/workbench/{$_POST['workbench_config']}");

$timestamp = $_POST['timestamp'];
$media_id = $_POST['media_id'];
$user_id = $_POST['user_id'];

$parsed_config['username'] = getenv("DRUPAL_USERNAME");
$parsed_config['password'] = getenv("DRUPAL_PASSWORD");
$parsed_config['log_file_path'] = "{$user_id}_{$media_id}.log";

$by_content_type = array();
$by_media_type = array();
$content_type_yml_paths = array();
$subcontent_type_yml_paths = array();
$media_type_yml_paths = array();
$id_map = array();
$sub_id_map = array();
$all_files = array();
$delimiter = "|";
$subdelimiter = ";";
$total_retval = 0;
$total_entity_count = 0;
$ingest_state = "in progress";



// extract SIP
$base_path = "/var/lib/nginx/workbench";
$zip_path = "{$base_path}/input_dir/{$timestamp}.zip";
$zip_extract_path = "{$base_path}/input_dir/{$timestamp}";
file_put_contents($zip_path, fopen("http://drupal" . $_POST['media_url'], "r"));

$exploded_url = explode("/", $_POST['media_url']);
$zip_filename = end($exploded_url);

$zip = new ZipArchive;

$zip_res = $zip->open($zip_path);
if ($zip_res) {
	$zip->open($zip_path);
	$zip->extractTo($zip_extract_path);
	$zip->close();
} else {
	print_r("Archive extraction failed");
	return;
}

// the archive should contain exactly one directory
// scandir outputs . and .. directories
$dir_ls = scandir($zip_extract_path, SCANDIR_SORT_DESCENDING);
if (count($dir_ls) != 3) {
	print_r("invalid SIP format, root path doesn't contain exactly one directory");
	return;
}

$sip_directory_name = $dir_ls[0];
$input_dir_path = "{$base_path}/input_dir/{$timestamp}/{$sip_directory_name}";
$output_dir_path = "{$base_path}/output_dir";



// parse input.csv into per-content type csvs
$original_csv = fopen("{$input_dir_path}/input.csv", "r");

if (!$original_csv) {
	print_r("failed to open inpupt csv file");
	print_r("{$sip_directory_name}");
	return 500;
}

$header = fgetcsv($original_csv);


if (!$header) {
	print_r("failed to parse csv file");
}




update_media_sip($timestamp, $base_path, $_POST['media_id'], $ingest_state, $all_files);

$content_type_index;
$media_type_index = array();
$forward_reference_index = array();

for ($i = 0; $i < count($header); $i += 1) {
	print_r($header[array_keys($header)[$i]]);
	print_r("\n");
	if ($header[$i] == "type") {
		$content_type_index = $i;
	}

	// find forward referencing fields
	if (str_contains($header[$i], ":")) {
		$forward_reference_index[$header[$i]] = $i;
	}

	// file fields
	if (str_starts_with($header[array_keys($header)[$i]], "file_")) {

		$media_type_index[$header[$i]] = $i;
	}

	$header_index[$header[$i]] = $i;
}


foreach ($media_type_index as $media_type => $_media_index) {
	$by_media_type[$media_type] = array();
}

foreach ($forward_reference_index as $subcontent_type => $_value) {
	$by_subcontent_type[$subcontent_type] = array();
}

print_r($forward_reference_index);

while ($line = fgetcsv($original_csv)) {
	if (!isset($by_content_type[$line[array_keys($line)[$content_type_index]]])) {
		$by_content_type[$line[array_keys($line)[$content_type_index]]] = array();
	}

	array_push($by_content_type[$line[array_keys($line)[$content_type_index]]], $line);

	foreach ($forward_reference_index as $ref_type => $ref_id) {
		if (!empty($line[$ref_id])) {
			array_push($by_subcontent_type[$ref_type], $line);
			$total_entity_count += count(explode($delimiter, $line[$ref_id]));
		}
	}


	foreach ($media_type_index as $media_type => $media_index) {
		if (!empty($line[$media_index])) {
			array_push($by_media_type[$media_type], $line);
			$total_entity_count += count(explode($delimiter, $line[$media_index]));
		}
	}

	$total_entity_count += 1;
}

fclose($original_csv);

print_r($by_media_type);
print_r($by_subcontent_type);

$forward_ref_types = array();
$subentity_header_base = ["id", "title"];

// create separate csv for reference fields
// id,title,<list of fields>
// must be imported first
foreach ($by_subcontent_type as $subfield_definition => $lines) {
	$parsed_config_content = $parsed_config;

	$subfield_header = explode(":", $subfield_definition);
	if (count($subfield_header) < 3) {
		print_r("invalid subfield definition");
		return;
	}

	
	$subfield_name = array_shift($subfield_header);
	$content_type = array_shift($subfield_header);

	$local_header = array_merge($subentity_header_base, $subfield_header);

	print_r($local_header);

	$parsed_config_content['content_type'] = $content_type;

	if (!isset($parsed_config_content['csv_field_templates'])) {
		$parsed_config_content['csv_field_templates'] = array();
	}

	$parsed_config_content['input_dir'] = "{$base_path}/output_dir";
	$parsed_config_content['nodes_only'] = "true";
	$parsed_config_content['input_csv'] = "{$timestamp}_{$subfield_name}_{$content_type}.csv";
	array_push($parsed_config_content['ignore_csv_columns'], "type");
	array_push($parsed_config_content['ignore_csv_columns'], "source_id");
	
	array_push($parsed_config_content['csv_field_templates'], array("uid" => $_POST['user_id']));

	$content_yml_path = "{$base_path}/output_dir/{$timestamp}_{$subfield_name}_{$content_type}.yml";
	$content_csv_path = "{$base_path}/output_dir/{$timestamp}_{$subfield_name}_{$content_type}.csv";
	yaml_emit_file($content_yml_path, $parsed_config_content);

	$all_files[$content_yml_path] = 1;

	print_r($parsed_config_content);


	//$all_files[$content_csv_path] = 1;

	$content_csv = fopen($content_csv_path, "w");




	fputcsv($content_csv, $local_header);
	$id = 1;
	foreach ($lines as $line) {
		$exploded = explode($delimiter, $line[$forward_reference_index[$subfield_definition]]);
		foreach ($exploded as $repeated_subfield) {
			// original id is in first column
			$data = [
				$line[0] . "_" . $id,
				$timestamp . "_" . rand(),
			];

			$id += 1;

			$subfield_data = explode($subdelimiter, $repeated_subfield);

			print_r($subfield_data);

			$data = array_merge($data, $subfield_data);

			print_r($data);
			fputcsv($content_csv, $data);
		}
	}
	fclose($content_csv);

	$subcontent_type_yml_paths[$subfield_name] = $content_yml_path;
}

if (!file_exists("/tmp/csv_id_to_node_id_map.db")) {
	init_db();
}


print_r($subcontent_type_yml_paths);

// prepare progress files
$entity_tally = fopen("/var/www/html/api/{$user_id}_{$media_id}.log.tally", "w");
$entity_total = fopen("/var/www/html/api/{$user_id}_{$media_id}.log.total", "w");

fwrite($entity_tally, "0");
fwrite($entity_total, $total_entity_count);

fclose($entity_tally);
fclose($entity_total);

// import subfield nodes
foreach ($subcontent_type_yml_paths as $subfield_name => $path) {
	$command = "/var/lib/nginx/islandora_workbench/workbench --config {$path}";

	$check = (bool) $_POST['check'];
	if ($check) {
		$command .= " --check";
	}
	
	$command .= " 2>&1";
	
	
	$node_ingest_output = array();
	$retval;
	$last_line = exec($command, $node_ingest_output, $retval);
	$total_retval += $retval;

	print_r($last_line);
	print_r($retval);


	// get id -> nid mapping from workbench sqlite database
	$db = new SQLite3("/tmp/csv_id_to_node_id_map.db", SQLITE3_OPEN_READONLY);
	$db_result = $db->query("SELECT csv_id,node_id FROM csv_id_to_node_id_map WHERE config_file='{$path}'");
	while ($line = $db_result->fetchArray(SQLITE3_ASSOC)) {
		print_r($line);
		if (!isset($sub_id_map[$subfield_name][explode("_", $line["csv_id"])[0]])) {
			$sub_id_map[$subfield_name][explode("_", $line["csv_id"])[0]] = array();
		}
		array_push($sub_id_map[$subfield_name][explode("_", $line["csv_id"])[0]], $line["node_id"]);
	}
	$db->close();
}

print_r($sub_id_map);
print_r(array_keys($sub_id_map));

$sub_id_map_final = array();

// prepare final forward reference ids
foreach ($sub_id_map as $subfield => $data) {
	$sub_id_map_final[$subfield] = array();
	foreach ($data as $id => $list) {
		$sub_id_map_final[$subfield][$id] = implode($delimiter, $list);
	}
}


print_r("-------------------------");
print_r($sub_id_map_final);



// write out node csvs and ymls
foreach ($by_content_type as $content_type => $lines) {
	$parsed_config_content = $parsed_config;
	$parsed_config_content['content_type'] = $content_type;
	
	if (!isset($parsed_config_content['csv_field_templates'])) {
		$parsed_config_content['csv_field_templates'] = array();
	}

	$parsed_config_content['input_dir'] = "{$base_path}/output_dir";
	$parsed_config_content['nodes_only'] = "true";
	$parsed_config_content['input_csv'] = "{$timestamp}_{$content_type}.csv";
	array_push($parsed_config_content['ignore_csv_columns'], "type");
	foreach ($forward_reference_index as $field_id => $_value) {
		array_push($parsed_config_content['ignore_csv_columns'], $field_id);
	}
	
	array_push($parsed_config_content['csv_field_templates'], array("uid" => $_POST['user_id']));

	$content_yml_path = "{$base_path}/output_dir/{$timestamp}_{$content_type}.yml";
	$content_csv_path = "{$base_path}/output_dir/{$timestamp}_{$content_type}.csv";
	yaml_emit_file($content_yml_path, $parsed_config_content);

	$all_files[$content_yml_path] = 1;
	$all_files[$content_csv_path] = 1;

	$content_csv = fopen($content_csv_path, "w");

	$local_header = array_merge($header, array_keys($sub_id_map));


	fputcsv($content_csv, $local_header);
	foreach ($lines as $line) {
		foreach ($sub_id_map_final as $subfield) {
			array_push($line, $subfield[$line[0]]);
		}
		print_r($line);
		fputcsv($content_csv, $line);
	}
	fclose($content_csv);

	array_push($content_type_yml_paths, $content_yml_path);

}


// import nodes
foreach ($content_type_yml_paths as $path) {
	$command = "/var/lib/nginx/islandora_workbench/workbench --config {$path}";

	$check = (bool) $_POST['check'];
	if ($check) {
		$command .= " --check";
	}
	
	$command .= " 2>&1";
	
	
	$node_ingest_output = array();
	$retval;
	$last_line = exec($command, $node_ingest_output, $retval);
	$total_retval += $retval;

	// get id -> nid mapping from workbench sqlite database
	$db = new SQLite3("/tmp/csv_id_to_node_id_map.db", SQLITE3_OPEN_READONLY);
	$db_result = $db->query("SELECT csv_id,node_id FROM csv_id_to_node_id_map WHERE config_file='{$path}'");
	while ($line = $db_result->fetchArray(SQLITE3_ASSOC)) {
		print_r($line);
		$id_map[$line["csv_id"]] = $line["node_id"];
	}
	$db->close();
}


// write out media csvs and ymls
foreach ($by_media_type as $media_type => $lines) {
	$parsed_config_content = $parsed_config;
	$parsed_config_content['task'] = "add_media";
	
	if (!isset($parsed_config_content['csv_field_templates'])) {
		$parsed_config_content['csv_field_templates'] = array();
	}

	$parsed_config_content['media_type'] = substr($media_type, strlen("file_")); 
	$parsed_config_content['input_dir'] = $input_dir_path;
	$parsed_config_content['input_csv'] = "{$output_dir_path}/{$timestamp}_{$media_type}.csv";
	
	array_push($parsed_config_content['csv_field_templates'], array("uid" => $_POST['user_id']));

	$media_yml_path = "{$base_path}/output_dir/{$timestamp}_{$media_type}.yml";
	$media_csv_path = "{$base_path}/output_dir/{$timestamp}_{$media_type}.csv";

	yaml_emit_file($media_yml_path, $parsed_config_content);

	$content_csv = fopen($media_csv_path, "w");

	array_push($media_type_yml_paths, $media_yml_path);

	$all_files[$media_yml_path] = 1;
	$all_files[$media_csv_path] = 1;

	$local_header = [
		"node_id",
		"file"	
	];

	fputcsv($content_csv, $local_header);
	foreach ($lines as $line) {
		foreach (explode($delimiter, $line[$media_type_index[$media_type]]) as $file) { 
			$new_line = [
				$id_map[$line[$header_index["id"]]],
				$file
			];
			fputcsv($content_csv, $new_line);
		}
	}
	fclose($content_csv);
}

// import media
foreach ($media_type_yml_paths as $path) {
	$command = "/var/lib/nginx/islandora_workbench/workbench --config {$path}";
	
	if ((bool) $_POST['check']) {
		$command .= " --check";
	}

	$command .= " 2>&1";
	
	$node_ingest_output = array();
	$retval;
	$last_line = exec($command, $node_ingest_output, $retval);
	$total_retval += $retval;
}

// return ingest status (success/failure)
$ingest_state = "fail";
if ($total_retval == 0) {
	$ingest_state = "success";
}

update_media_sip($timestamp, $base_path, $_POST['media_id'], $ingest_state, $all_files);

// file cleanup
unlink($zip_path);
delete_recursive($zip_extract_path);

foreach ($all_files as $file => $_value) {
	unlink($file);
}

?>
