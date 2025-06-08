<?php
// pharmacy_management_system/delete_drug.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php'; // Contains redirectIfNotRole and getCurrentUser

// --- Authorization Check ---
// Only allow 'Admin' or 'Pharmacist' roles to delete drugs
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], ['Admin', 'Pharmacist'])) {
    error_log("Unauthorized access attempt to delete_drug.php by User ID: " . ($_SESSION['user_id'] ?? 'N/A') . " Role: " . ($_SESSION['role'] ?? 'N/A'));
    header('Location: dashboard.php?auth_error=2'); // Redirect if not authorized
    exit();
}

$pdo = getDbConnection();
$message = '';
$status = ''; // Initialize status for redirection

// --- Handle DELETE Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $drugIdToDelete = isset($_POST['drug_id']) ? (int)$_POST['drug_id'] : 0;
    $csrfToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    // --- CSRF Token Verification ---
    if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        error_log("CSRF token mismatch for drug deletion. User: " . ($currentUser['username'] ?? 'N/A') . " Drug ID: " . $drugIdToDelete);
        $message = urlencode("Error: Invalid request. Please try again.");
        $status = 'error';
        header('Location: drugs.php?message=' . $message . '&status=' . $status);
        exit();
    }

    // After successful verification, invalidate the token to prevent reuse
    unset($_SESSION['csrf_token']);

    if ($drugIdToDelete === 0) {
        $message = urlencode("Error: Invalid drug ID provided for deletion.");
        $status = 'error';
        error_log("Drug deletion failed: Invalid drug ID (0) provided by user " . $currentUser['username']);
        header('Location: drugs.php?message=' . $message . '&status=' . $status);
        exit();
    }

    try {
        // Prepare SQL statement to delete the drug
        $stmt = $pdo->prepare("DELETE FROM Drugs WHERE drug_id = ?");
        $stmt->execute([$drugIdToDelete]);

        if ($stmt->rowCount() > 0) {
            $message = urlencode("Drug deleted successfully!");
            $status = 'success';
            error_log("Drug ID: " . $drugIdToDelete . " successfully deleted by user " . $currentUser['username'] . " (ID: " . $currentUser['user_id'] . ")");
            header('Location: drugs.php?message=' . $message . '&status=' . $status);
            exit();
        } else {
            // This case means the drug ID was valid, but no row was deleted (e.g., it didn't exist)
            $message = urlencode("No drug found with ID " . $drugIdToDelete . " to delete.");
            $status = 'info'; // Use info status as it's not a critical error, just no action taken
            error_log("Drug deletion attempted for non-existent ID: " . $drugIdToDelete . " by user " . $currentUser['username']);
            header('Location: drugs.php?message=' . $message . '&status=' . $status);
            exit();
        }

    } catch (PDOException $e) {
        // Check for foreign key constraint violation
        if ($e->getCode() == '23000') { // SQLSTATE for Integrity Constraint Violation
            $message = urlencode("Error: Cannot delete drug (ID: " . $drugIdToDelete . "). It is associated with existing stock or sales records. Please update related records first.");
            $status = 'error';
            error_log("Drug deletion failed for ID " . $drugIdToDelete . " due to foreign key constraint violation: " . $e->getMessage() . " by user " . $currentUser['username']);
        } else {
            $message = urlencode("Error deleting drug: " . $e->getMessage());
            $status = 'error';
            error_log("Drug deletion failed for ID " . $drugIdToDelete . " with PDOException: " . $e->getMessage() . " by user " . $currentUser['username']);
        }
        header('Location: drugs.php?message=' . $message . '&status=' . $status);
        exit();
    }
} else {
    // If accessed via GET request (e.g., direct URL access), redirect with an error
    $message = urlencode("Invalid request method for drug deletion. Must be POST.");
    $status = 'error';
    error_log("Attempt to access delete_drug.php via GET request. User: " . ($currentUser['username'] ?? 'N/A'));
    header('Location: drugs.php?message=' . $message . '&status=' . $status);
    exit();
}
?>
