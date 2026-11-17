<?php

require_once "bootstrap/app.php";
require_once "app/services/GoogleDriveService.php";

echo "<pre>";

$drive = new GoogleDriveService();

if (!$drive->isReady()) {
    echo "❌ Google Drive is NOT ready:\n";
    echo $drive->getInitError() . "\n\n";
    echo "👉 To connect Google Drive with 1 click, open this URL in your browser:\n\n";
    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&access_type=offline&client_id=" . urlencode($_ENV['GOOGLE_CLIENT_ID'] ?? '') . "&redirect_uri=" . urlencode("https://digitask.digiinksolutions.com/oauth_callback.php") . "&scope=" . urlencode("https://www.googleapis.com/auth/drive") . "&prompt=consent";
    echo "<a href='$authUrl' target='_blank'>$authUrl</a>\n";
    exit;
}

try {
    $conn = $drive->testConnection();
    if (!$conn['success']) {
        echo "❌ Connection test failed: " . $conn['message'] . "\n";
        exit;
    }

    echo "✅ Google Drive Connected! User: " . $conn['user'] . "\n\n";

    echo "1. Testing Folder Creation...\n";
    $testKey = "TEST_FLOW_" . date('Ymd_His');
    $folder = $drive->createTaskFolder($testKey);
    print_r($folder);

    if (!empty($folder['client_folder']['id'])) {
        echo "\n2. Testing File Upload...\n";
        $dir = __DIR__ . "/uploads";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $testFile = $dir . "/test_dummy.txt";
        file_put_contents($testFile, "Google Drive Test successful at " . date('Y-m-d H:i:s'));

        $upload = $drive->uploadFile($testFile, $folder['client_folder']['id'], $testKey . "_test.txt");
        print_r($upload);

        if (!empty($upload['success'])) {
            echo "\n🎉✅ ALL SYSTEMS OPERATIONAL! File Link: " . $upload['link'] . "\n";
        }
    }
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";