<?php
session_start();
require_once 'functions.php';

// Redirect if already logged in
if (isset($_SESSION['user']) && isset($_SESSION['role'])) {
    header('Location: ' . ($_SESSION['role'] === 'Admin' ? 'admin.php' : 'catalog'));
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = $_POST['password'] ?? '';
    
    $users = getUsers();
    $found = false;
    
    foreach ($users as $user) {
        if ($user['email'] === $email) {
            $found = true;
            // ✅ CRITICAL: Use password_verify, NOT direct comparison
            if (password_verify($pass, $user['password'])) {
                if ($user['status'] !== 'Active') {
                    $err = 'Account is inactive. Please contact admin.';
                } else {
                    $_SESSION['user'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['user_type'] = $user['user_type'] ?? 'Customer';
                    
                    header('Location: ' . ($_SESSION['role'] === 'Admin' ? 'admin.php' : 'catalog'));
                    exit;
                }
                // After password_verify check:
                if (!password_verify($pass, $user['password'])) {
                    error_log("Login failed for $email: hash mismatch");
                    $err = 'Invalid credentials (debug: hash mismatch)';
                }
            
            } else {
                $err = 'Invalid password.';
            }
            break;
        }
    }
    
    if (!$found) {
        $err = 'Email not registered.';
    }
}
?>
<!-- Rest of HTML form... -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="padding: 80px 20px; min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; background: var(--bg);">
    <div style="width: 100%; max-width: 420px; background: var(--surface); padding: 32px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border);">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2 style="color: var(--primary); margin-bottom: 8px;">Welcome Back</h2>
            <p style="color: var(--muted); font-size: 0.9rem;">Sign in to access your account</p>
        </div>
        
        <?php if($err): ?>
        <div id="error-msg" style="background: var(--danger); color: white; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
            <?=e($err)?>
        </div>
        <?php endif; ?>
        
        <form method="post" action="signin" id="signin-form">
            <div style="margin-bottom: 16px;">
                <label for="email" class="filter-label">Email Address</label>
                <input type="email" id="email" name="email" required class="filter-input" placeholder="you@company.com" value="<?=e($_POST['email'] ?? '')?>">
            </div>
            <div style="margin-bottom: 20px;">
                <label for="password" class="filter-label">Password</label>
                <input type="password" id="password" name="password" required class="filter-input" placeholder="••••••••">
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; font-size:0.85rem;">
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; color:var(--muted);">
                    <input type="checkbox" name="remember"> Remember me
                </label>
                <a href="#" style="color:var(--accent); text-decoration:none; font-weight:500;">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Sign In</button>
        </form>
        <p style="text-align:center; margin-top:20px; font-size:0.9rem; color:var(--muted);">
            Don't have an account? <a href="signup" style="color:var(--accent); font-weight:500;">Sign Up</a>
        </p>
    </div>
</main>
<?php include 'footer.php'; ?>
<script>
// Auto-hide error messages after 5 seconds
setTimeout(() => {
    const errorMsg = document.getElementById('error-msg');
    if (errorMsg) {
        errorMsg.style.transition = 'opacity 0.5s';
        errorMsg.style.opacity = '0';
        setTimeout(() => errorMsg.remove(), 500);
    }
}, 5000);

</script>

</body>
</html>