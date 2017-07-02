<?php
/*
This file is part of Unity Cloud Build Webhook for HockeyApp
Copyright (c) 2016 Jorrit de Vries

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

require_once('ucb-settings.php');
require_once('ucb-log.php');
require_once('ucb-downloader.php');

/**
 * For environments where getallheaders not is defined.
 *
 * Code is taken from http://php.net/manual/en/function.getallheaders.php
 */
if (!function_exists('getallheaders')) {
	function getallheaders() {
		if (!is_array($_SERVER)) {
			return array();
		}
		
		$headers = array();
		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) == 'HTTP_') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
		return $headers;
	}
}

/**
 * For environments where curl_file_create not is defined.
 *
 * Code is taken from http://php.net/manual/en/curlfile.construct.php#114539
 */
if (!function_exists('curl_file_create')) {
	function curl_file_create($filename, $mimetype = '', $postname = '') {
		return "@$filename;filename="
			. ($postname ?: basename($filename))
            . ($mimetype ? ";type=$mimetype" : '');
    }
}

/**
 * Starts the execution of the Unity Cloud Build webhook. If no content is found,
 * or the retrieval of the post headers fails, nothing happens.
 */
function ucb_execute_webhook() {
	// Obtain post data
	$content = file_get_contents('php://input');
	// Obtain headers
	$headers = getallheaders();

	if ($headers === FALSE || strlen($content) < 1) {
		return;
	}
	
	ucb_log_debug('Headers: ' . PHP_EOL . print_r($headers, true));
	ucb_log_debug('Content: ' . PHP_EOL . $content);
	
	// Check if headers contain X-UnityCloudBuild-Event or X-Unitycloudbuild-Event
	$cloud_build_event = '';
	
	if (isset($headers['X-UnityCloudBuild-Event'])) {
		$cloud_build_event = $headers['X-UnityCloudBuild-Event'];
	}
	else if (isset($headers['X-Unitycloudbuild-Event'])) {
		$cloud_build_event = $headers['X-Unitycloudbuild-Event'];
	}
	
	if (strcmp($cloud_build_event, 'ProjectBuildSuccess') !== 0) {
		ucb_log_info("Unsupported cloud build event '" . $cloud_build_event . "'.");
		return;
	}
	
	$json = json_decode($content, true);
	if (json_last_error() != JSON_ERROR_NONE) {
		ucb_log_error('Decode of content failed: ' . json_last_error_msg());
		return;
	}
	
	// Check project guid
	if (strcmp($json['projectGuid'], CLOUD_BUILD_PROJECT_GUID) !== 0) {
		ucb_log_error('An attempt has been made to use the webhook for another project identified by ' . $json['projectGuid']);
		return;
	}
	
	// Note we only want to upload adhoc release builds
	// TODO make this configurable
	$build_targets = unserialize(CLOUD_BUILD_WEBHOOK_BUILD_TARGETS);
	$build_target_name = $json['buildTargetName'];
	if (!array_key_exists($json['buildTargetName'], $build_targets)) {
		ucb_log_info('Build is not configured for uploading.');
		return;
	}
	
	try {
		// Validate content
		if (defined('CLOUD_BUILD_WEBHOOK_SECRET')) {
			ucb_validate_content($headers, $content, CLOUD_BUILD_WEBHOOK_SECRET);
		}
		
		// Retrieve build status
		$result = ucb_get_build_status(CLOUD_BUILD_API_KEY, $json);
		
		// Decod build status json
		$build_status_json = json_decode($result, true);
		if (json_last_error() != JSON_ERROR_NONE) {
			throw new Exception('Decode of build status failed: ' . json_last_error_msg());
		}
		
		// Download artifacts
		$platform = $json['platform'];
		$upload_message = sprintf(HOCKEYAPP_UPLOAD_MESSAGE, $json['projectName'], $platform, $json['buildNumber']);
		
		$downloader = null;
		
		switch ($platform) {
			case 'ios':
				$downloader = new UCBIOSDownloader();
				break;
			case 'android':
				$downloader = new UCBAndroidDownloader();
				break;
			default:
				throw new Exception("Unsupported platform '" . $platform . "' for downloading");
		}
		
		if (isset($downloader)) {
			// Download artifacts
			if (!file_exists(CLOUD_BUILD_WEBHOOK_TMP_DIR)) {
				mkdir(CLOUD_BUILD_WEBHOOK_TMP_DIR, 0775, true);
			}
			$downloader->downloadArtifacts(CLOUD_BUILD_WEBHOOK_TMP_DIR, $build_status_json);
			$artifact_paths = $downloader->getArtifactPaths();
			
			// Upload
			$app_id = $build_targets[$build_target_name];
			ucb_upload_build($platform, $app_id, $artifact_paths, $upload_message);
			
			// Cleanup
			$downloader->cleanupArtifacts();
		}
	}
	catch (Exception $e) {
		ucb_log_error($e->getMessage());
	}
}

/**
 *
 */
function ucb_validate_content($headers, $content, $webhook_secret) {
	$server_signature = '';
	
	if (isset($headers['X-UnityCloudBuild-Signature'])) {
		$server_signature = $headers['X-UnityCloudBuild-Signature'];
	}
	else if (isset($headers['X-Unitycloudbuild-Signature'])) {
		$server_signature = $headers['X-Unitycloudbuild-Signature'];
	}
	
	if (strlen($server_signature) > 0) {
		// Generate client signature
		$client_signature = hash_hmac('sha256', $content, $webhook_secret);
		if (strcmp($server_signature, $client_signature) !== 0) {
			throw new Exception('Content validation failed, server=' . $server_signature .
				', client=' . $client_signature);
		}
		
		ucb_log_debug('Content validation succeeded');
	}
	else {
		ucb_log_info('Content validation skipped');
	}
}

/**
 *
 */
function ucb_get_build_status($api_key, $json) {
	// Obtain api request URL so we can retrieve more details regarding the build
	$url = CLOUD_BUILD_API_URL . $json['links']['api_self']['href'];
	ucb_log_info('Get build status from ' . $url);
	
	// Retrieve build details
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_HTTPHEADER => array(
			'Content-Type: application/json',
			'Authorization: Basic ' . $api_key
		)
	));
	
	$result = curl_exec($ch);
	
	$error_message = null;
	if ($result === FALSE) {
		$error_message = 'cURL failed: ' . curl_error($ch);
	}
				
	curl_close($ch);
				
	ucb_log_debug('Build status retrieval result: ' . $result);
	
	if (isset($error_message)) {
		throw new Exception($error_message);
	}
	
	return $result;
}

/**
 * Uploads the artifacts retrieve from Unity Cloud build to HockeyApp. 
 * 
 * @param artifact_paths
 * @param platform
 */
function ucb_upload_build($platform, $app_id, $artifact_paths, $message) {
	// Upload to hockey app
	if (!isset($artifact_paths) || count($artifact_paths) < 1) {
		return;
	}
	
	$ipa = '';
	$dSYM = null;
	
	switch ($platform) {
		case 'ios':
			$ipa = $artifact_paths['ipa'];
			$dSYM = $artifact_paths['dSYM'];
			break;
		case 'android':
			$ipa = $artifact_paths['apk'];
			break;
		default:
			throw new Exception("Unsupported platform '" . $platform . "' for uploading");
	}
	
	$url = 'https://rink.hockeyapp.net/api/2/apps/' . $app_id . '/app_versions/upload';
	
	ucb_log_info('Upload artifacts to ' . $url . ': ' . $ipa . (isset($dSYM) ? ' with dSYM' : ''));
	
	$postFields = array(
		'status' => '2',
		'notify' => '1',
		'strategy' => HOCKEYAPP_UPLOAD_STRATEGY,
		'mandatory' => HOCKEYAPP_UPDATE_MANDATORY,
		'notes' => $message,
		'ipa' => curl_file_create($ipa)
	);
	if (isset($dSYM)) {
		$postFields['dsym'] = curl_file_create($dSYM);
	}
	
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_HTTPHEADER => array(
			'X-HockeyAppToken: ' . HOCKEYAPP_API_TOKEN
		),
		CURLOPT_POSTFIELDS => $postFields,
		CURLOPT_SSL_VERIFYPEER => FALSE // When not set to FALSE, uploads might fail
	));
	$result = curl_exec($ch);
	
	$error_message = null;
	if ($result === FALSE) {
		$error_message = 'cURL failed: ' . curl_error($ch);
	}
	
	curl_close($ch);
	
	ucb_log_debug('Upload result: ' . $result);
	
	if (isset($error_message)) {
		throw new Exception($error_message);
	}
	
	ucb_log_info('Upload of artifacts completed');
}

ucb_execute_webhook();
?>
