<?php

require_once __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\WriteStream;
use Google\CloudFunctions\FunctionsFramework;
use CloudEvents\V1\CloudEventInterface;

FunctionsFramework::cloudEvent('CreateThumbnail', 'CreateThumbnail');

function CreateThumbnail(CloudEventInterface $cloudEvent) {
    $data = $cloudEvent->getData();
    $filenameSansExtension = getBaseFilename($data);
    if ($filenameSansExtension === FALSE) {
        return;
    }

    // download the object that triggered this event
    $storage = new StorageClient([
        'projectId' => 'libops-abcdef02',
    ]);
    $bucket = $storage->bucket($data['bucket']);
    $object = $bucket->object($data['name']);
    $filename = basename($data['name']);
    $destination = "/tmp/$filename";
    $object->downloadToFile($destination);

    // create a write stream to the thumbnail GCS object
    $thumbnailPath = $filenameSansExtension . "-thumbnail.png";
    $outputBlob = $bucket->object($thumbnailPath);
    $writeStream = new WriteStream(null, [
        'chunkSize' => 1024 * 256, // 256KB
    ]);
    $uploader = $bucket->getStreamableUploader($writeStream, [
        'name' => $thumbnailPath,
    ]);
    $writeStream->setUploader($uploader);

    // run the convert command, streaming the output to the thumbnail GCS object
    $cmd = "convert $destination -thumbnail 100x100 -";
    $pipes = [];
    $process = proc_open($cmd, [
        1 => ['pipe', 'w'],
    ], $pipes);
    $return_value = "-1 never ran";
    if (is_resource($process)) {
        while ($s = fgets($pipes[1])) {
            $writeStream->write($s);
        }
        fclose($pipes[1]);
        $writeStream->close();
        $return_value = proc_close($process);
    }

    // make sure the convert command completed OK
    if ($return_value !== 0) {
        error_log("cmd.Run failed with return value " . $return_value);
        return;
    }

    // GREAT SUCCESS
    error_log("Thumbnail image created at gs://" . $outputBlob->info()['bucket'] . "/" . $outputBlob->info()['name']);
}

/**
 * Check the GCSEvent that was emitted is for an image we need to create a thumbnail for.
 */
function getBaseFilename($data) {
    $directories = explode("/", $data['name']);
    if (count($directories) > 1) {
        if ($directories[1] == "styles") {
            error_log("Not processing styles directory " . $data['name']);
            return FALSE;
        }
    }

    $filenameComponents = explode(".", $data['name']);
    $fileExtension = $filenameComponents[count($filenameComponents) - 1];
    $filenameComponentsSansExtentsion = array_slice($filenameComponents, 0, count($filenameComponents) - 1);

    // make sure it's an image
    if (!IsValidExtension($fileExtension)) {
        error_log("Not processing invalid extension " . $fileExtension);
        return FALSE;
    }

    // check for directory
    $filenameSansExtension = implode(".", $filenameComponentsSansExtentsion);
    $lastChar = substr($filenameSansExtension, -1);
    if ($lastChar == "/") {
        error_log("Not processing directory " . $data['name']);
        return FALSE;
    }

    // avoid infinite thumbnails
    if (strlen($filenameSansExtension) > 10) {
        $last10 = substr($filenameSansExtension, -10);
        if ($last10 == "-thumbnail") {
            error_log("Not processing file ending in '-thumbnail': " . $data['name']);
            return FALSE;
        }
    }

    return $filenameSansExtension;
}

function IsValidExtension($cloudEventxtension) {
    switch ($cloudEventxtension) {
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'gif':
        case 'jp2':
        case 'tif':
        case 'tiff':
            return TRUE;

    }
    return FALSE;
}
