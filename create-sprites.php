<?php

/*
 * Generate tooltip thumbnail images & corresponding WebVTT file for a video (e.g MP4).
 * Final product is one *_sprite.jpg file and one *_thumbs.vtt file.
 *
 * DEPENDENCIES: required: ffmpeg & imagemagick
 * download ImageMagick: http://www.imagemagick.org/script/index.php OR http://www.imagemagick.org/script/binary-releases.php (on MacOSX: "sudo port install ImageMagick")
 * download ffmpeg: http://www.ffmpeg.org/download.html
 * jwplayer reference: http://www.longtailvideo.com/support/jw-player/31778/adding-tooltip-thumbnails/
 *
 * Based on Videoscripts from fbonzon (https://github.com/vlanard/videoscripts)
 */

// Parameters
define('THUMB_RATE_SECONDS', 45);
define('THUMB_WIDTH', 100);
define('TIMESYNC_ADJUST', -.5);
define('SKIP_FIRST', true);

// Errors management
if (count($argv) < 2) {
    echo 'Usage : ' . __FILE__ . ' /full/path/video.mp4' . "\n";
    exit(1);
}

if (!file_exists($argv[1])) {
    echo 'File ' . $argv[1] . ' not found' . "\n";
    exit(2);
}

// Create temporary directory to work in
$sTmpDir = time();
if (!mkdir($sTmpDir, 0777, true)) {
    echo 'Echec lors de la création des répertoires...' . "\n";
    exit(3);
}

// Process video file
processVideo($sTmpDir, $argv[1]);

// Remove temporary directory
exec("rm -rf ./$sTmpDir");

echo 'End.' . "\n";

// Functions
function processVideo($sTmpDir, $sVideoFile) {
    $aPathParts = pathinfo($sVideoFile);
    $sVttFile = str_replace('.' . $aPathParts['extension'], '.vtt', $sVideoFile);
    $sSpriteFile = str_replace('.' . $aPathParts['extension'], '.jpg', $sVideoFile);

    echo "Processing video ($sVideoFile)..\n";
    echo "Répertoire temporaire : $sTmpDir\n";

    // Overwrite (debug)
    $sTmpDir = 'tmp';

    // Create snapshots and resize them to be mini
    $aFiles = takeSnaps($sTmpDir, $sVideoFile, THUMB_RATE_SECONDS, THUMB_WIDTH);
    $iNbFiles = count($aFiles);

    if ($iNbFiles > 0) {
        // Get coordinates from a resized file to use in spritemapping
        $iGridSize = (int) ceil(sqrt(count($aFiles)));
        $sCoords = getGeometry($aFiles[0]); // use the first file (since they are all same size) to get geometry settings
        // Convert small files into a single sprite grid
        makeSprite($sTmpDir, $sCoords, $iGridSize, $sSpriteFile);

        // Generate a vtt with coordinates to each image in sprite
        makeVttFile($sSpriteFile, $iNbFiles, $sCoords, $iGridSize, $sVttFile, THUMB_RATE_SECONDS);
    } else {
        echo "Error while generating snapshots\n";
    }
}

/**
 * Take snapshot image of video every Nth second and output to sequence file names and custom directory and change image output size
 * Reference: https://trac.ffmpeg.org/wiki/Create%20a%20thumbnail%20image%20every%20X%20seconds%20of%20the%20video
 * @param string $sTmpDir
 * @param string $sVideoFile
 * @param int $iRateSeconds
 * @param int $iWidth
 * @return array
 */
function takeSnaps($sTmpDir, $sVideoFile, $iRateSeconds, $iWidth = null) {
    $iRate = "1/$iRateSeconds"; // 1/60=1 per minute, 1/120=1 every 2 minutes
    $sCmd = "ffmpeg -i $sVideoFile -f image2 -bt 20M -vf fps=$iRate -aspect 16:9 $sTmpDir/frame%03d.jpg";
    exec($sCmd);

    if (SKIP_FIRST) {
        // remove the first image
        if (file_exists("$sTmpDir/frame001.jpg")) {
            unlink("$sTmpDir/frame001.jpg");
        }
    }

    $aFiles = glob($sTmpDir . '/*');
    if ($iWidth != null) {
        // resize images
        exec("mogrify -geometry " . $iWidth . "x " . implode(' ', $aFiles));
    }

    return $aFiles;
}

/**
 * Execute command to give geometry HxW+X+Y of each file matching command
 * Using the command "identify" (imagemagick)
 * @param string $sFile
 * @return string
 */
function getGeometry($sFile) {
    exec('identify -format "%g - %f" ' . $sFile, $aOutput);
    $aOutputParts = explode('-', $aOutput[0]);

    // return just the geometry prefix of the line without extra whitespace
    // Sample output : 100x2772+0+0 - sprite2.jpg
    return trim($aOutputParts[0]);
}

/**
 * Convert small files into a single sprite grid
 * Using the command "montage" (imagemagick)
 * @param string $sTmpDir
 * @param string $sCoords
 * @param int $iGridSize
 * @param string $sSpriteFile
 */
function makeSprite($sTmpDir, $sCoords, $iGridSize, $sSpriteFile) {
    $sGrid = $iGridSize . 'x' . $iGridSize;
    exec("montage $sTmpDir/* -tile $sGrid -geometry $sCoords $sSpriteFile");
}

/**
 * Generate & write vtt file mapping video time to each image's coordinates in our spritemap
 * ======= SAMPLE WEBVTT FILE =====
 * WEBVTT
 *
 * 00:00.000 --> 00:05.000
 * preview1.jpg#xywh=0,0,160,90
 *
 * 00:05.000 --> 00:10.000
 * preview2.jpg#xywh=160,0,160,90
 *
 * 00:10.000 --> 00:15.000
 * preview3.jpg#xywh=0,90,160,90
 *
 * 00:15.000 --> 00:20.000
 * preview4.jpg#xywh=160,90,160,90
 * ==== END SAMPLE ========
 */
function makeVttFile($sSpriteFile, $iNbFiles, $sCoords, $iGridSize, $sVttFile, $iRateSeconds) {

    /**
     * Given an image number in our sprite, map the coordinates to it in X,Y,W,H format
     * @param int $iIdx
     * @param int $iGridSize
     * @param int $iW
     * @param int $iH
     * @return string
     */
    function getGridCoordinates($iIdx, $iGridSize, $iW, $iH) {
        $iY = (int) ($iIdx / $iGridSize);
        $iX = (int) ($iIdx - ($iY * $iGridSize));
        $iImgX = $iX * $iW;
        $iImgY = $iY * $iH;
        return implode(',', array($iImgX, $iImgY, $iW, $iH));
    }

    /**
     * Convert time in seconds to VTT format time (HH:)MM:SS.ddd
     * @param int $iTime
     * @param int $iAdjust
     * @return string
     */
    function getTimeStr($iTime, $iAdjust) {
        // offset the time by the adjust amount, if applicable
        $iSeconds = $iAdjust ? max(array($iTime + $iAdjust, 0)) : $iTime;

        $h = 0;
        $m = 0;
        $s = (int) $iSeconds;

        if ($s > 60) {
            // Check seconds value
            $m = (int) ($s / 60);
            $s -= ($m * 60);

            if ($m > 60) {
                // Check minutes value
                $h = (int) ($m / 60);
                $m -= ($h * 60);
            }
        }

        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT) . '.000';
    }

    // Split geometry string into individual parts
    // 4200x66+0+0     ===  WxH+X+Y
    $aParts = explode('+', $sCoords);
    $wh = $aParts[0];
    $aWHParts = explode('x', $wh);
    $w = (int) $aWHParts[0];
    $h = (int) $aWHParts[1];

    $aPathParts = pathinfo($sSpriteFile);
    $sSpriteBasename = $aPathParts['basename'];

    $iClipStart = SKIP_FIRST ? $iRateSeconds : 0;
    $iClipEnd = $iClipStart + $iRateSeconds;
    $iAdjust = $iRateSeconds * TIMESYNC_ADJUST;

    // Write VTT file
    $oHandle = fopen($sVttFile, 'w');
    fwrite($oHandle, 'WEBVTT' . "\n\n");

    for ($i = 0; $i < $iNbFiles; $i++) {
        $sXYWH = getGridCoordinates($i, $iGridSize, $w, $h);
        $sStart = getTimeStr($iClipStart, $iAdjust);
        $sEnd = getTimeStr($iClipEnd, $iAdjust);

        fwrite($oHandle, "$sStart --> $sEnd\n"); // #00:00.000 --> 00:05.000
        fwrite($oHandle, $sSpriteBasename . '#xywh=' . $sXYWH . "\n");
        fwrite($oHandle, "\n");

        $iClipStart = $iClipEnd;
        $iClipEnd += $iRateSeconds;
    }

    fclose($oHandle);
}
