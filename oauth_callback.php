<?php
require_once __DIR__ . '/vendor/autoload.php';

$config = json_decode(
    file_get_contents(__DIR__ . '/config/credentials/oauth.json'),
    true
);

$client = new Google\Client();
$client->setClientId($config['web']['client_id']);
$client->setClientSecret($config['web']['client_secret']);
$client->setRedirectUri($config['web']['redirect_uris'][0]);
$client->addScope(Google\Service\Drive::DRIVE);

if (!isset($_GET['code'])) {
    exit("Authorization failed. No code provided by Google.");
}

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

$savedToEnv = false;
if (!empty($token['refresh_token'])) {
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        if (strpos($envContent, 'GOOGLE_REFRESH_TOKEN=') !== false) {
            $envContent = preg_replace('/GOOGLE_REFRESH_TOKEN=.*/', 'GOOGLE_REFRESH_TOKEN=' . $token['refresh_token'], $envContent);
        } else {
            $envContent .= "\nGOOGLE_REFRESH_TOKEN=" . $token['refresh_token'];
        }
        file_put_contents($envPath, $envContent);
        $savedToEnv = true;
    }
    // Clear temp token cache
    $files = glob(sys_get_temp_dir() . '/digiink_gtoken_*.json');
    if ($files) {
        foreach ($files as $f) @unlink($f);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Google Drive Connected | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6" style="font-family: 'Plus Jakarta Sans', sans-serif;">
    <div class="max-w-xl w-full bg-white rounded-3xl p-8 shadow-xl border border-slate-200 text-center">
        <?php if (!empty($token['refresh_token'])): ?>
            <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-3xl mx-auto flex items-center justify-center text-3xl font-black mb-4">✓</div>
            <h1 class="text-2xl font-black text-slate-800 mb-2">Google Drive Connected!</h1>
            <p class="text-sm text-slate-500 mb-6">A new permanent refresh token has been generated <?= $savedToEnv ? 'and automatically saved to <code class="bg-slate-100 px-2 py-1 rounded text-indigo-600 font-bold">.env</code>.' : '.' ?></p>
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-left text-xs font-mono break-all text-slate-700 mb-6">
                <span class="font-bold text-slate-400 block mb-1 uppercase tracking-wider text-[10px]">Active Refresh Token:</span>
                <?= htmlspecialchars($token['refresh_token']) ?>
            </div>
            <a href="test_google.php" class="inline-block px-8 py-3.5 bg-indigo-600 hover:bg-slate-900 text-white rounded-2xl font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-indigo-100">Test Connection Now</a>
        <?php else: ?>
            <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-3xl mx-auto flex items-center justify-center text-3xl font-black mb-4">!</div>
            <h1 class="text-2xl font-black text-slate-800 mb-2">Access Token Received</h1>
            <p class="text-sm text-slate-500 mb-4">Google returned an access token, but not a new refresh token. To force Google to issue a new refresh token, visit <a href="login.php" class="text-indigo-600 font-bold underline">login.php</a> with prompt=consent.</p>
            <pre class="p-4 bg-slate-50 rounded-2xl text-left text-xs font-mono overflow-auto mb-6"><?= htmlspecialchars(print_r($token, true)) ?></pre>
            <a href="login.php" class="inline-block px-8 py-3.5 bg-amber-600 hover:bg-slate-900 text-white rounded-2xl font-bold text-xs uppercase tracking-wider transition">Re-prompt Consent</a>
        <?php endif; ?>
    </div>
</body>
</html>