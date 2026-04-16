<?php
require_once 'functions.php';

// Log the logout action before destroying session
if (isset($_SESSION['user'])) {
    logAudit('logout', "User {$_SESSION['user']} logged out");
}

// Clear all session variables
$_SESSION = [];

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to signin page
header('Location: signin.php');
exit;
?>