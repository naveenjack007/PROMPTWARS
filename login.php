<?php
// cooking-todo-app/login.php
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PrepMaster Intelligent Kitchen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: radial-gradient(circle at 50% 50%, #1a1528 0%, #0a0813 100%);
            margin: 0;
            overflow: hidden;
        }
        
        /* Floating background blobs for futuristic feel */
        .blob {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.3) 0%, rgba(236, 72, 153, 0.15) 100%);
            filter: blur(80px);
            z-index: 0;
            animation: float 20s infinite alternate ease-in-out;
        }
        .blob-1 { top: -50px; left: -50px; }
        .blob-2 { bottom: -50px; right: -50px; animation-delay: -10s; }
        
        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, 50px) scale(1.2); }
        }
    </style>
</head>
<body>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <div class="auth-container">
        <div class="auth-header">
            <div class="logo-glow">🍳</div>
            <h1>PrepMaster</h1>
            <p>Your AI-Powered Intelligent Kitchen Companion</p>
        </div>
        
        <div class="auth-card">
            <h2>Welcome Back</h2>
            <p class="auth-subtitle">Sign in to plan your daily meals & manage pantry</p>
            
            <div id="error-alert" class="alert alert-danger" style="display: none;"></div>
            
            <form id="login-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" placeholder="Enter your username" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <span>Sign In</span>
                    <span class="btn-glow"></span>
                </button>
            </form>
            
            <div class="auth-footer">
                Don't have an account? <a href="signup.php">Sign Up</a>
            </div>
            
            <!-- Quick Demo Login Credentials Box for UI check -->
            <div class="demo-box">
                <h4>Demo Account Available:</h4>
                <p>Username: <strong>demo</strong> / Password: <strong>demo123</strong></p>
                <button id="quick-demo-btn" class="btn btn-secondary btn-sm" style="margin-top: 8px; width: 100%;">Auto-fill Demo</button>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const errorAlert = document.getElementById('error-alert');
            
            errorAlert.style.display = 'none';
            
            try {
                const response = await fetch('api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: usernameInput.value,
                        password: passwordInput.value
                    })
                });
                
                const result = await response.json();
                
                if (response.ok && result.status === 'success') {
                    window.location.href = 'index.php';
                } else {
                    errorAlert.textContent = result.message || 'Authentication failed.';
                    errorAlert.style.display = 'block';
                }
            } catch (err) {
                errorAlert.textContent = 'Server error. Please try again later.';
                errorAlert.style.display = 'block';
                console.error(err);
            }
        });
        
        document.getElementById('quick-demo-btn').addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('username').value = 'demo';
            document.getElementById('password').value = 'demo123';
        });
    </script>
</body>
</html>
