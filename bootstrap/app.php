<?php

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

require_once __DIR__ . '/../app/helpers/drive_helper.php';