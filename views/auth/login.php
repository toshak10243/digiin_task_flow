<?php 
// Aapka backend logic yahan (e.g., $error checking)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digiink Task Flow | Premium Login</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary: #4f46e5;
            --primary-glow: rgba(79, 70, 229, 0.4);
            --bg-dark: #0f172a;
            --glass-white: rgba(255, 255, 255, 0.98);
        }

        * {
            box-sizing: border-box;
            margin: 0; padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            min-height: 100vh;
            background: var(--bg-dark);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow: hidden;
            position: relative;
        }

        /* ── Animated Background Blobs ── */
        .blob {
            position: absolute;
            width: 500px; height: 500px;
            background: var(--primary);
            filter: blur(120px);
            border-radius: 50%;
            z-index: 0;
            opacity: 0.2;
            animation: rotate 25s infinite linear;
        }
        .blob-2 { background: #ec4899; right: -10%; bottom: -10%; animation-delay: -5s; opacity: 0.15; }

        @keyframes rotate {
            from { transform: rotate(0deg) translate(50px) rotate(0deg); }
            to { transform: rotate(360deg) translate(50px) rotate(-360deg); }
        }

        /* ── Premium Container ── */
        .main-wrapper {
            display: flex;
            width: 100%;
            max-width: 1050px;
            min-height: 620px;
            border-radius: 35px;
            overflow: hidden;
            z-index: 10;
            background: var(--glass-white);
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.4);
            animation: containerReveal 1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes containerReveal {
            from { opacity: 0; transform: scale(0.9) translateY(30px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* ── Left Branding Side ── */
        .left-panel {
            flex: 1.1;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 50px;
            position: relative;
            border-right: 1px solid #f1f5f9;
        }

        /* Glow effect behind Lottie */
        .lottie-glow {
            position: absolute;
            width: 250px; height: 250px;
            background: var(--primary);
            filter: blur(80px);
            opacity: 0.1;
            z-index: 1;
        }

        /* ── Right Form Side ── */
        .right-panel {
            flex: 1;
            padding: 60px 55px;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .logo-box {
            width: 60px; height: 60px;
            background: var(--primary);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            box-shadow: 0 10px 20px var(--primary-glow);
        }

        h2 {
            font-size: 34px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -1px;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #64748b;
            font-size: 15px;
            margin-bottom: 40px;
            font-weight: 500;
        }

        /* ── Form Inputs ── */
        .input-group {
            margin-bottom: 24px;
            position: relative;
        }

        .input-group label {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 8px;
            display: block;
            margin-left: 2px;
        }

        .input-box {
            position: relative;
        }

        input {
            width: 100%;
            padding: 16px 20px;
            border-radius: 16px;
            border: 2px solid #f1f5f9;
            background: #f8fafc;
            transition: all 0.3s ease;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            outline: none;
        }

        input:focus {
            background: white;
            border-color: var(--primary);
            box-shadow: 0 0 0 5px rgba(79, 70, 229, 0.1);
        }

        .toggle-pass {
            position: absolute;
            right: 18px; top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 22px;
        }

        /* ── Button ── */
        button {
            width: 100%;
            padding: 18px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 18px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            box-shadow: 0 10px 25px var(--primary-glow);
        }

        button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px var(--primary-glow);
            filter: brightness(1.1);
        }

        .links-area {
            margin-top: 35px;
            text-align: center;
        }

        .links-area a {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: 0.3s;
        }

        .links-area a:hover { opacity: 0.7; }

        .error-bubble {
            background: #fff1f2;
            color: #e11d48;
            padding: 15px;
            border-radius: 15px;
            font-size: 14px;
            margin-bottom: 25px;
            border: 1px solid #ffe4e6;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        @media(max-width: 950px) {
            .main-wrapper { flex-direction: column; max-width: 500px; height: auto; border-radius: 30px; }
            .left-panel { display: none; }
            .right-panel { padding: 50px 35px; }
        }
    </style>
</head>
<body>

<div class="blob"></div>
<div class="blob blob-2"></div>

<div class="main-wrapper">
    <div class="left-panel">
        <div class="lottie-glow"></div>
        <lottie-player
            src="https://assets10.lottiefiles.com/packages/lf20_w51pcehl.json"
            background="transparent"
            speed="1"
            style="width: 380px; height: 380px; position: relative; z-index: 2;"
            loop autoplay>
        </lottie-player>
        <div style="text-align: center; position: relative; z-index: 2;">
            <h3 style="color: #0f172a; font-size: 26px; font-weight: 800; letter-spacing: -0.5px;">Elite Workspace</h3>
            <p style="color: #64748b; font-size: 15px; margin-top: 8px; font-weight: 500;">Simplify tasks with our next-gen flow.</p>
        </div>
    </div>

    <div class="right-panel">
        <div class="logo-box">
             <span class="material-icons-round" style="color: white; font-size: 32px;">bolt</span>
        </div>
        <h2>Sign In</h2>
        <p class="subtitle">Enter your credentials to access dashboard.</p>

        <?php if(!empty($error)): ?>
            <div class="error-bubble">
                <span class="material-icons-round">report_gmailerrorred</span>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>Username</label>
                <div class="input-box">
                    <input type="text" name="username" placeholder="Enter username" required autocomplete="off">
                </div>
            </div>

            <div class="input-group">
                <label>Password</label>
                <div class="input-box">
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                    <span class="material-icons-round toggle-pass" onclick="togglePassword()">visibility</span>
                </div>
            </div>

            <button type="submit">Get Started</button>
        </form>

        <div class="links-area">
            <a href="javascript:void(0)" onclick="forgot()">Forgot access? Contact Admin</a>
        </div>
    </div>
</div>

<script>
function togglePassword(){
    const pass = document.getElementById("password");
    const icon = document.querySelector(".toggle-pass");
    if(pass.type === "password"){
        pass.type = "text";
        icon.innerText = "visibility_off";
    } else {
        pass.type = "password";
        icon.innerText = "visibility";
    }
}

function forgot(){
    Swal.fire({
        title: 'Access Help',
        html: `
            <div style="margin-bottom:20px">
                <lottie-player src="https://assets4.lottiefiles.com/packages/lf20_jbrw3hcz.json"
                    background="transparent" speed="1"
                    style="width:130px;height:130px;margin:auto;"
                    loop autoplay>
                </lottie-player>
            </div>
            <p style="color:#64748b; font-size:15px; line-height:1.6; font-weight: 500;">
                For your security, password resets are managed by the <b>System Administrator</b>. Please contact IT support or your manager.
            </p>
        `,
        confirmButtonColor: '#4f46e5',
        confirmButtonText: 'Understood',
        customClass: {
            popup: 'rounded-[30px]'
        }
    });
}
</script>

</body>
</html>