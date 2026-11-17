<?php
require_once "../../app/core/Session.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

$username = $_SESSION['username'];
$role = strtolower(str_replace(" ", "_", $_SESSION['role']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="3.5;url=../<?= $role ?>/dashboard.php">
    <title>Launching Digiink...</title>
    <link rel="icon" type="image/png" href="../../assets/images/favicon.png">
    <link rel="shortcut icon" href="../../favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --bg: #fcfcfd;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            overflow: hidden;
        }

        .splash-wrapper {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* --- Circular Modern Loader --- */
        .loader-ring {
            width: 100px;
            height: 100px;
            position: relative;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .loader-ring::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 3px solid #e2e8f0;
            border-radius: 50%;
        }

        .loader-ring::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 3px solid transparent;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s cubic-bezier(0.7, 0, 0.3, 1) infinite;
        }

        .logo-inner {
            width: 60px;
            height: 60px;
            z-index: 10;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(0.92); opacity: 0.8; }
        }

        /* --- Text Content --- */
        .branding {
            font-size: 12px;
            font-weight: 800;
            color: var(--primary-light);
            text-transform: uppercase;
            letter-spacing: 4px;
            margin-bottom: 12px;
            animation: fadeInDown 0.8s ease-out;
        }

        .title {
            font-size: 36px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -1px;
            margin-bottom: 8px;
            animation: fadeInUp 0.8s ease-out;
        }

        .user-welcome {
            font-size: 16px;
            font-weight: 500;
            color: #64748b;
            opacity: 0;
            animation: fadeIn 1s ease-out forwards;
            animation-delay: 0.5s;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

    </style>
</head>
<body>

<div class="splash-wrapper">
    <div class="loader-ring">
        <img src="../../assets/images/logo.png" class="logo-inner" alt="Logo" 
             onerror="this.src='https://cdn-icons-png.flaticon.com/512/3064/3064155.png'">
    </div>
    
    <p class="branding">Digiink Task Flow</p>
    <h1 class="title">Dashboard</h1>
    
    <p class="user-welcome">Welcome, <?= htmlspecialchars($username) ?></p>
</div>

</body>
</html>