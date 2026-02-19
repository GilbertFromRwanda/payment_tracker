<?php
require_once 'includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
        exit();
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit();
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$hashed, $_SESSION['user_id']]);

    echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></div>
        <a href="#" onclick="history.go(-1); return false;" class="back-btn">← Back</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <h1>Change Password</h1>

            <div class="form-card">
                <div id="alertMsg" class="alert" style="display:none;"></div>

                <form id="changePasswordForm">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn" id="submitBtn">Change Password</button>
                </form>
            </div>
        </main>
    </div>

    <script>
    document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn      = document.getElementById('submitBtn');
        const alertMsg = document.getElementById('alertMsg');
        const formData = new FormData(this);

        btn.disabled    = true;
        btn.textContent = 'Saving...';
        alertMsg.style.display = 'none';
        alertMsg.className     = 'alert';

        fetch('change_password.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            alertMsg.style.display = 'block';
            if (data.success) {
                alertMsg.classList.add('alert-success');
                alertMsg.textContent = data.message;
                document.getElementById('changePasswordForm').reset();
            } else {
                alertMsg.classList.add('alert-error');
                alertMsg.textContent = data.message;
            }
        })
        .catch(() => {
            alertMsg.style.display  = 'block';
            alertMsg.classList.add('alert-error');
            alertMsg.textContent = 'Something went wrong. Please try again.';
        })
        .finally(() => {
            btn.disabled    = false;
            btn.textContent = 'Change Password';
        });
    });
    </script>
</body>
</html>
