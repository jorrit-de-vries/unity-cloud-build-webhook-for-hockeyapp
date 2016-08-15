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

require_once('ucb-log.php');

/**
 * Base class for downloading files from unity cloud build.
 * Concrete subclass have to implement the downloadArtifactsImpl
 * method in order to retrieve specific files for their
 * respective platform.
 */
abstract class UCBDownloader {
	
	/**
	 * Associative array holding paths to downloaded files for
	 * the respective types.
	 */
	protected $artifact_paths = array();
	
	/**
	 *
	 */
	public function getArtifactPaths() {
		return $this->artifact_paths;
	}
	
	/**
	 * Downloads artifacts as specified in the given json into
	 * the given target directory.
	 */
	public function downloadArtifacts($target_dir, $json) {
		$this->prepareDownload($target_dir);
		$this->downloadArtifactsImpl($target_dir, $json);
	}
	
	/**
	 * Removes the downloaded files.
	 */
	public function cleanupArtifacts() {
		foreach ($this->artifact_paths as $key => $value) {
			unlink($value);
		}
	}
	
	/**
	 * Creates the target directory if it doesn't exist yet.
	 */
	protected function prepareDownload($target_dir) {
		if (!file_exists($target_dir)) {
			mkdir($target_dir, 0775, true);
		}
	}
	
	/**
	 * The platform specific implementation for downloading artifacts.
	 */
	abstract protected function downloadArtifactsImpl($target_dir, $json);
	
	/**
	 * Downloads a single artifact from the given url to the given path.
	 */
	protected function downloadArtifact($url, $path) {
		ucb_log_info('Download artifact from ' . $url . ' to file ' . $path);
		
		$fh = fopen($path, 'w+');
		
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_FILE => $fh
		));
		$result = curl_exec($ch);
		
		$error_message = null;
		if ($result === FALSE) {
			$error_message = 'cURL failed: ' . curl_error($ch);
		}
		
		curl_close($ch);
		fclose($fh);
		
		if (isset($error_message)) {
			throw new Exception($error_message);
		}
	}
}

/**
 * IOS implementation of UCBDownloader to download ipa and dSYM.zip
 * files.
 */
class UCBIOSDownloader extends UCBDownloader {
	
	/**
	 *
	 */
	protected function downloadArtifactsImpl($target_dir, $json) {
		$this->prepareDownload($target_dir);
		
		ucb_log_info('Download iOS artifacts');
		
		$filename = basename($json['projectVersion']['filename'], '.ipa');
		
		$ipa_filename = $filename . '-' . date('dmy-Gis') . '.ipa';
		$ipa_path = $target_dir . '/' .$ipa_filename;
		
		$dsym_filename = $filename . '-' . date('dmy-Gis') . '.dSYM.zip';
		$dsym_path = $target_dir . '/' .$dsym_filename;
		
		// Download ipa
		$url = $json['links']['download_primary']['href'];
		$this->downloadArtifact($url, $ipa_path);
		$this->artifact_paths['ipa'] = $ipa_path;
		
		// Download dSym
		$url = $json['links']['download_dsym']['href'];
		$this->downloadArtifact($url, $dsym_path);
		$this->artifact_paths['dSYM'] = $dsym_path;
	}
}

/**
 * IOS implementation of UCBDownloader to download apk files.
 */
class UCBAndroidDownloader extends UCBDownloader {
	
	/**
	 *
	 */
	protected function downloadArtifactsImpl($target_dir, $json) {
		$this->prepareDownload($target_dir);
		
		ucb_log_info('Download Android artifacts');
		
		$filename = basename($json['projectVersion']['filename'], '.apk');
		$filename .= '-' . date('dmy-Gis') . '.apk';
		$path = $target_dir . '/' . $filename;
		
		// Download apk
		$url = $json['links']['download_primary']['href'];
		$this->downloadArtifact($url, $path);
		
		$this->artifact_paths['apk'] = $path;
	}
}
?>