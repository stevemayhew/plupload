<?php
/**
 * upload.php
 *
 * Copyright 2009, Moxiecode Systems AB
 * Released under GPL License.
 *
 * License: http://www.plupload.com/license
 * Contributing: http://www.plupload.com/contributing
 */

include_once('FirePHPCore/FirePHP.class.php');
try {
	$firephp = FirePHP::getInstance(true);
	$firephp->setEnabled(true);
	$firephp->log("Using firephp logging.");
} catch(Exception $e) {
	error_log("FirePhp Not found, no logging.");
}


// HTTP headers for no cache etc
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$targetFakeDataDir = '..' . DIRECTORY_SEPARATOR . 'jsonTestData';

// Settings
$targetDir = ini_get("upload_tmp_dir") . DIRECTORY_SEPARATOR . "plupload";
//$targetDir = 'uploads';
$formName = 'Filedata';
//$formName = 'file';

$cleanupTargetDir = true; // Remove old files
$maxFileAge = 5 * 3600; // Temp file age in seconds

// 5 minutes execution time
@set_time_limit(5 * 60);

function error_handler($errno, $errstr, $errfile, $errline) {
	   if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return;
    }

	die('{"jsonrpc" : "2.0", "error" : {"code": ' . $errno . ', "message": "' . $errstr . '"}, "retryFromStart":true}');
}
set_error_handler("error_handler", E_WARNING | E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE);

// Uncomment this one to fake upload time
// usleep(5000);

// Get parameters
$chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
$chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;
$chunk_size = isset($_REQUEST["curChunkSize"]) ? intval($_REQUEST["curChunkSize"]) : 0;
$fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : '';

// Clean the fileName for security reasons
if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $fileName)) {
	if (isset($firephp)) {
		$firephp->log($fileName, "Filename is has illegal characters.");
	}
	die('{"jsonrpc" : "2.0", "error" : {"code": 105, "message": "Illegal filename."}, "retryFromStart":true}');
}

$filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

// Create target dir
if (!file_exists($targetDir)) {
	if (isset($firephp)) {
		$firephp->log($targetDir, "Creating target directory.");
	}
	@mkdir($targetDir);
}

// Remove old temp files	
if ($cleanupTargetDir && is_dir($targetDir) && ($dir = opendir($targetDir))) {
	while (($file = readdir($dir)) !== false) {
		$tmpfilePath = $targetDir . DIRECTORY_SEPARATOR . $file;

		// Remove temp file if it is older than the max age and is not the current file
		if (preg_match('/\.part\.[0-9]+$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge) && (basename($tmpfilePath) != "{$fileName}.part")) {
			if (isset($firephp)) {
				$firephp->log("Cleaning up target directory, removing: " . $file);
			}
			@unlink($tmpfilePath);
		}
	}

	closedir($dir);
} else {
	if (isset($firephp)) {
		$firephp->log("failed to open temp directory: " . $targetDir);
	}
	die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "retryFromStart":true}');
}


// Look for the content type header
if (isset($_SERVER["HTTP_CONTENT_TYPE"]))
	$contentType = $_SERVER["HTTP_CONTENT_TYPE"];

if (isset($_SERVER["CONTENT_TYPE"]))
	$contentType = $_SERVER["CONTENT_TYPE"];

// Handle non multipart uploads older WebKit versions didn't support multipart in HTML5
if (strpos($contentType, "multipart") !== false) {
	if (isset($firephp)) {
		$firephp->log($_FILES, "Parsed multipart");
		$firephp->log($_REQUEST, "Request Parameters");
	}
	if (isset($_FILES[$formName]['tmp_name']) && is_uploaded_file($_FILES[$formName]['tmp_name'])) {
		// Open temp file
		$out = fopen("{$filePath}.part.{$chunk}", "wb");
		if ($out) {
			// Read binary input stream and append it to temp file
			$in = fopen($_FILES[$formName]['tmp_name'], "rb");

			if ($in) {
				$loops = 0;
				while ($buff = fread($in, 4096)) {
					fwrite($out, $buff);
					$loops++;
					$xfp = @fopen('/tmp/upload_fail_chunk', 'r');
					if ($xfp) {
						$errorOnPath = trim(fgets($xfp));
						@fclose($xfp);
						if ($loops > 1 && strcmp($errorOnPath, $fileName) == 0 && $chunk > 0) {
							if (isset($firephp)) {
								$firephp->log("Forcing short chunk fail for $filePath");
							}
							break;
						}
					}
				}
			} else
				die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "retryFromStart":false }');
			fclose($in);
			fclose($out);

			$xfp = @fopen('/tmp/upload_fail', 'r');
			if ($xfp) {
				$errorOnPath = trim(fgets($xfp));
				if (isset($firephp)) {
					$firephp->log(array(strcmp($errorOnPath, $fileName), $errorOnPath, $fileName), "Checking $fileName for match with $errorOnPath");
				}
				@fclose($xfp);
				if (strcmp($errorOnPath, $fileName) == 0) {
					if (isset($firephp)) {
						$firephp->log("Forcing fail for $filePath");
					}
					die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Forced failure."}, "retryFromStart":true}');
				}
			}
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "retryFromStart":false}}');
	} else {
		if (isset($firephp)) {
			$firephp->log("Failed to find uploaded multipart temp " . $_FILES[$formName]['tmp_name']);
		}
		die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to find uploaded multipart temp."}, "retryFromStart":true}');
	}
} else {

	if (isset($firephp)) {
		$firephp->log("Non-multipart request, filepath:" . $filePath);
	}

	// Open temp file
	$out = fopen("{$filePath}.part.{$chunk}", "wb");
	if ($out) {
		// Read binary input stream and append it to temp file
		$in = fopen("php://input", "rb");

		if ($in) {
			while ($buff = fread($in, 4096))
				fwrite($out, $buff);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "retryFromStart":true}}');

		fclose($in);
		fclose($out);
	} else
		die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "retryFromStart":true}}');
}

// Validate the chunk if requested, return error if it's bad.
if (isset($_REQUEST["curChunkSize"])) {
	$fp = fopen("{$filePath}.part." . $chunk, "rb");
	$fstat = fstat($fp);
	fclose($fp);
	if ($fstat['size'] != $chunk_size) {
		die('{"jsonrpc" : "2.0", "error" : {"code": 104, "message": "Chunk failed to fully upload.", "details": {"chunk" : ' . $chunk . ' }}, "retryFromStart":false}');
	}
	$firephp->log("Validated chunk file size as:" . $chunk_size);

}

// Check if file has been uploaded
if (isset($_REQUEST["chunks"]) && $chunk == $chunks - 1) {
	if (isset($firephp)) {
		$firephp->log("Chunked request and got last chunk");
	}

	$targetFilePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
	if (isset($_POST['destinationFolderPath'])) {
		$directory = $targetFakeDataDir . $_POST['destinationFolderPath'];
		if (!file_exists($directory)) {
			if (isset($firephp)) {
				$firephp->log($directory, "Creating destination directory");
			}
			error_log("Create directory: " . $directory);
			@mkdir($directory, 0777, true);
		}
		$targetFilePath = $directory . DIRECTORY_SEPARATOR . $fileName;
	}
	if (isset($firephp)) {
		$firephp->log(array('chunks' => $chunks, 'targetFilePath' => $targetFilePath), "Concatenating chunks to destination file");
	}

	$out = fopen($targetFilePath, "wb");

	for ($i = 0; $i < $chunks; $i++) {
		$in = fopen("{$filePath}.part.{$i}", "rb");
		if ($in) {
			while ($buff = fread($in, 4096))
				fwrite($out, $buff);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 105, "message": "Failed to open chunk.", "details": {"chunk" : ' . $chunk . ' }}, "retryFromStart":true}');
		fclose($in);
		@unlink("{$filePath}.part.{$i}");
	}
	fclose($out);

	die('{"jsonrpc" : "2.0", "result" : {"totalChunks" : ' . $chunks . '}, "status":"SUCCESS"}');

} else if (isset($_REQUEST["chunks"])) {
	if (isset($firephp)) {
		$firephp->log("Response for chunked request, chunk {$chunk} of {$chunks}.");
	}

	die('{"jsonrpc" : "2.0", "result" : {"chunk":' . $chunk . '}, "status":"SUCCESS"}');
} else {
	$targetFilePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
	if (isset($_POST['destinationFolderPath'])) {
		$directory = $targetFakeDataDir . $_POST['destinationFolderPath'];
		if (!file_exists($directory)) {
			if (isset($firephp)) {
				$firephp->log($directory, "Creating destination directory");
			}
			error_log("Create directory: " . $directory);
			@mkdir($directory, 0777, true);
		}
		$targetFilePath = $directory . DIRECTORY_SEPARATOR . $fileName;
	}
	if (isset($firephp)) {
		$firephp->log(array('src' => "{$filePath}.part.{$chunk}", 'dest' => $targetFilePath), "move single chunk file to destination file");
	}

	rename("{$filePath}.part.{$chunk}", $targetFilePath);

	die('{"jsonrpc" : "2.0", "result" : null, "status":"SUCCESS"}');

}

?>