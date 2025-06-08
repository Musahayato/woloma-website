<?php
// pharmacy_management_system/logout.php
session_start();

// Initialize redirect path to a default (e.g., general login or customer login)
$redirectPath = 'login.php';

// Check if a user role is set in the session before unsetting/destroying
if (isset($_SESSION['currentUser']) && isset($_SESSION['currentUser']['role'])) {
    $userRole = $_SESSION['currentUser']['role'];

    // Determine the redirect path based on the user's role
    if ($userRole === 'Admin' || $userRole === 'Pharmacist') {
        $redirectPath = 'staff_login.php';
    }
    // If it's a 'Customer' or any other role, it will default to 'login.php'
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect the user to the appropriate login page
header('Location: ' . $redirectPath);
exit();
?>