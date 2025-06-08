<?php
// pharmacy_management_system/delete_user.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only 'Admin' role can delete users
redirectIfNotRole(array('Admin'), 'staff_login.php');

$currentUser = getCurrentUser(); // Get current user info
$pdo = getDbConnection();

// Get user ID from URL
$userIdToDelete = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userIdToDelete === 0) {
    $_SESSION['feedback_message'] = "Error: No user ID provided for deletion.";
    $_SESSION['feedback_status'] = "error";
    header('Location: manage_users.php');
    exit();
}

// Prevent an admin from deleting their own account
if ($userIdToDelete === $currentUser['user_id']) {
    $_SESSION['feedback_message'] = "Error: You cannot delete your own user account.";
    $_SESSION['feedback_status'] = "error";
    header('Location: manage_users.php');
    exit();
}

try {
    // Optionally, check if the user exists before attempting to delete
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE user_id = ?");
    $stmtCheck->execute(array($userIdToDelete));
    if ($stmtCheck->fetchColumn() == 0) {
        $_SESSION['feedback_message'] = "Error: User with ID " . htmlspecialchars($userIdToDelete) . " not found.";
        $_SESSION['feedback_status'] = "error";
        header('Location: manage_users.php');
        exit();
    }

    // Delete the user
    $stmtDelete = $pdo->prepare("DELETE FROM Users WHERE user_id = ?");
    $stmtDelete->execute(array($userIdToDelete));

    if ($stmtDelete->rowCount() > 0) {
        $_SESSION['feedback_message'] = "User deleted successfully!";
        $_SESSION['feedback_status'] = "success";
    } else {
        $_SESSION['feedback_message'] = "Error: User could not be deleted or did not exist.";
        $_SESSION['feedback_status'] = "error";
    }

} catch (PDOException $e) {
    $_SESSION['feedback_message'] = "Database error: Could not delete user. " . htmlspecialchars($e->getMessage());
    $_SESSION['feedback_status'] = "error";
}

// Redirect back to the user management page
header('Location: manage_users.php');
exit();
?>