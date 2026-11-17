<?php

if(!isset($_POST['zip']) || empty($_POST['zip'])){
    die("Invalid ZIP request");
}

$zipPath = $_POST['zip'];
$files = $_POST['files'] ?? [];

if(!file_exists($zipPath)){
    die("ZIP file not found");
}

$zip = new ZipArchive;

if ($zip->open($zipPath) === TRUE) {

    // SINGLE FILE
    if(count($files) == 1){
        $content = $zip->getFromName($files[0]);

        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"".$files[0]."\"");

        echo $content;
        exit;
    }

    // MULTIPLE FILES → NEW ZIP
    if(count($files) > 1){

        $newZip = new ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'zip');

        $newZip->open($tmp, ZipArchive::CREATE);

        foreach($files as $file){
            $content = $zip->getFromName($file);
            $newZip->addFromString($file, $content);
        }

        $newZip->close();

        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=selected_files.zip");

        readfile($tmp);
        unlink($tmp);
        exit;
    }

    // NO FILE SELECTED
    die("No file selected");

} else {
    die("Error opening ZIP");
}
?>