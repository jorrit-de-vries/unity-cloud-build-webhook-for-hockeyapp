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

/**
 * Adds a log message to the log if the given level is equal to or
 * higher than CLOUD_BUILD_WEBHOOK_LOG_LEVEL.
 */
function ucb_log($level, $type, $message) {
	if ($level < CLOUD_BUILD_WEBHOOK_LOG_LEVEL) {
		return;
	}
	
	file_put_contents(CLOUD_BUILD_WEBHOOK_LOG_DIR . '/ucb-log.log',
		date('d-m-Y G:i:s') . ': ' . $type . ' - ' . $message . PHP_EOL,
		FILE_APPEND);
}

/**
 * Convenience method to log debug messages.
 */
function ucb_log_debug($message) {
	ucb_log(0, '[Debug]', $message);
}

/**
 * Convenience method to log messages.
 */
function ucb_log_info($message) {
	ucb_log(1, '[Info] ', $message);
}

/**
 * Convenience method to log error messages.
 */
function ucb_log_error($message) {
	ucb_log(2, '[Error]', $message);
}
?>