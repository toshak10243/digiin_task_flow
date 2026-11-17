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

$client->setAccessType('offline');
$client->setPrompt('consent');

$authUrl = $client->createAuthUrl();

header("Location: $authUrl");
exit;