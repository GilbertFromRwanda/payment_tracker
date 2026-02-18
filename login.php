<?php
session_start();
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h1>Payment Tracker</h1>
        <form id="loginForm" class="login-form">
            <div class="error" id="errorMsg" style="display:none;"></div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn" id="loginBtn">Login</button>
        </form>

        <div class="demo-credentials">
            <p>Demo Credentials:</p>
            <p>Username: admin</p>
            <p>Password: admin123</p>
        </div>
    </div>

    <script>
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('loginBtn');
        const errorMsg = document.getElementById('errorMsg');
        const formData = new FormData(this);

        btn.disabled = true;
        btn.textContent = 'Logging in...';
        errorMsg.style.display = 'none';

        fetch('login.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect;
            } else {
                errorMsg.textContent = data.message;
                errorMsg.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Login';
            }
        })
        .catch(() => {
            errorMsg.textContent = 'Something went wrong. Please try again.';
            errorMsg.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Login';
        });
    });
    </script>
</body>
</html>
