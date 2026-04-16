<?php
session_start();
require_once 'functions.php';

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header('Location: catalog');
    exit;
}

$err = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $companyName = sanitize_input($_POST['company_name'] ?? '');
    $userType = sanitize_input($_POST['user_type'] ?? 'Customer');
    $contactName = sanitize_input($_POST['contact_name'] ?? '');
    $phone = sanitize_input($_POST['phone'] ?? '');
    
    // Validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email address';
    } elseif (strlen($password) < 8) {
        $err = 'Password must be at least 8 characters';
    } elseif ($password !== $confirmPassword) {
        $err = 'Passwords do not match';
    } elseif (empty($companyName)) {
        $err = 'Company name is required';
    } else {
        $users = getUsers();
        
        // Check if email already exists
        foreach ($users as $user) {
            if ($user['email'] === $email) {
                $err = 'Email already registered. Please sign in.';
                break;
            }
        }
        
        if (empty($err)) {
            // Add new user
            $users[] = [
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'User', // Default role (not Admin)
                'user_type' => $userType, // Vendor, Buyer, or Customer
                'company_name' => $companyName,
                'contact_name' => $contactName,
                'phone' => $phone,
                'status' => 'Pending', // Requires admin approval
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            saveUsers($users);
            $success = 'Registration successful! Your account is pending admin approval. You will receive an email once activated.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="padding: 80px 20px; min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; background: var(--bg);">
    <div style="width: 100%; max-width: 500px; background: var(--surface); padding: 32px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border);">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2 style="color: var(--primary); margin-bottom: 8px;">Create Account</h2>
            <p style="color: var(--muted); font-size: 0.9rem;">Join AB Chem India</p>
        </div>
        
        <?php if($success): ?>
        <div id="success-msg" style="background: var(--success); color: white; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
            <?=e($success)?>
        </div>
        <?php endif; ?>
        
        <?php if($err): ?>
        <div id="error-msg" style="background: var(--danger); color: white; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
            <?=e($err)?>
        </div>
        <?php endif; ?>
        
        <form method="post" action="signup">
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Email Address *</label>
                <input type="email" name="email" required class="filter-input" placeholder="you@company.com" value="<?=e($_POST['email'] ?? '')?>">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Password *</label>
                <input type="password" name="password" required class="filter-input" placeholder="Min 8 characters">
                <small style="color:var(--muted); font-size:0.8rem;">Must be at least 8 characters</small>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Confirm Password *</label>
                <input type="password" name="confirm_password" required class="filter-input" placeholder="Re-enter password">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Account Type *</label>
                <select name="user_type" required class="filter-input">
                    <option value="Customer" <?=($_POST['user_type'] ?? '') === 'Customer' ? 'selected' : ''?>>Customer</option>
                    <option value="Buyer" <?=($_POST['user_type'] ?? '') === 'Buyer' ? 'selected' : ''?>>Buyer</option>
                    <option value="Vendor" <?=($_POST['user_type'] ?? '') === 'Vendor' ? 'selected' : ''?>>Vendor</option>
                </select>
                <small style="color:var(--muted); font-size:0.8rem;">Select your relationship with us</small>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Company Name *</label>
                <input type="text" name="company_name" required class="filter-input" placeholder="Your company name" value="<?=e($_POST['company_name'] ?? '')?>">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Contact Person</label>
                <input type="text" name="contact_name" class="filter-input" placeholder="Full name" value="<?=e($_POST['contact_name'] ?? '')?>">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label class="filter-label">Phone Number</label>
                <input type="tel" name="phone" class="filter-input" placeholder="+91 XXXXX XXXXX" value="<?=e($_POST['phone'] ?? '')?>">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%;">Create Account</button>
        </form>
        
        <p style="text-align:center; margin-top:20px; font-size:0.9rem; color:var(--muted);">
            Already have an account? <a href="signin" style="color:var(--accent); font-weight:500;">Sign In</a>
        </p>
    </div>
</main>
<?php include 'footer.php'; ?>
<script>
// Auto-hide messages after 5 seconds
setTimeout(() => {
    ['success-msg', 'error-msg'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        }
    });
}, 5000);
</script>
</body>
</html>