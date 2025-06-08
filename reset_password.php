<?php
// pharmacy_management_system/reset_password.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only 'Admin' role can access this page
$currentUser = getCurrentUser();
if (!isset($currentUser) || $currentUser['role'] !== 'Admin') { // PHP 6.9 compatible
    header('Location: staff_login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';
$targetUser = null;

// Get user ID from URL
$userIdToReset = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userIdToReset === 0) {
    $_SESSION['feedback_message'] = "Invalid user ID provided for password reset.";
    $_SESSION['feedback_status'] = "error";
    header('Location: manage_users.php');
    exit();
}

// Fetch details of the user whose password is being reset
try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, username, role FROM Users WHERE user_id = ?");
    $stmt->execute(array($userIdToReset)); // PHP 6.9 compatible array()
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $_SESSION['feedback_message'] = "User not found or does not exist.";
        $_SESSION['feedback_status'] = "error";
        header('Location: manage_users.php');
        exit();
    }
} catch (PDOException $e) {
    $feedbackMessage = "Database error: Could not load user details. " . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $form_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    // Ensure the ID from the form matches the ID from the URL
    if ($form_user_id !== $userIdToReset) {
        $feedbackMessage = "Security error: Mismatched user ID in form submission.";
        $feedbackStatus = 'error';
    } elseif (empty($new_password) || empty($confirm_password)) {
        $feedbackMessage = "New password and confirmation are required.";
        $feedbackStatus = 'error';
    } elseif ($new_password !== $confirm_password) {
        $feedbackMessage = "New password and confirmation do not match.";
        $feedbackStatus = 'error';
    } elseif (strlen($new_password) < 8) { // Example: enforce minimum password length
        $feedbackMessage = "New password must be at least 8 characters long.";
        $feedbackStatus = 'error';
    } else {
        try {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("UPDATE Users SET password_hash = ? WHERE user_id = ?");
            $stmt->execute(array($password_hash, $userIdToReset)); // PHP 6.9 compatible array()

            if ($stmt->rowCount() > 0) {
                $_SESSION['feedback_message'] = "Password for " . htmlspecialchars($targetUser['full_name']) . " has been reset successfully.";
                $_SESSION['feedback_status'] = "success";
            } else {
                $_SESSION['feedback_message'] = "No changes made to the password or user not found.";
                $_SESSION['feedback_status'] = "info";
            }
            header('Location: manage_users.php');
            exit();
        } catch (PDOException $e) {
            $feedbackMessage = "Database error: Could not reset password. " . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// Display feedback message from session if redirected
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = $_SESSION['feedback_status'];
    unset($_SESSION['feedback_message']);
    unset($_SESSION['feedback_status']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent */
        body {
            font-family: 'Inter', Arial, sans-serif; /* Consistent font */
            background-color: #f0f2f5; /* Light grey background */
            margin: 0;
            padding: 0;
            line-height: 1.6;
            color: #333;
            display: flex;
            flex-direction: column;
            min-height: 100vh; /* Ensure body takes full viewport height */
        }

        /* Header Styles - Consistent */
        header {
            background-color: #205072; /* Darker blue for header */
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: fixed; /* Keep header visible */
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            box-sizing: border-box; /* Include padding in width calculation */
        }
        header h1 {
            margin: 0;
            font-size: 1.8em;
            font-weight: 600;
        }
        .auth-status {
            display: flex;
            align-items: center;
            gap: 10px; /* Adjusted gap for better spacing */
        }
        .auth-status span {
            font-size: 0.9em;
            color: #e0e0e0;
            flex-grow: 1; /* Allow the span to grow/shrink */
            min-width: 0; /* Important for flex items to shrink below content size */
            white-space: nowrap; /* Prevent text wrapping */
            overflow: hidden; /* Hide overflow */
            text-overflow: ellipsis; /* Add ellipsis for overflowed text */
        }
        .auth-status a,
        .auth-status button,
        .auth-status form { /* Apply styles to form as well for consistent alignment */
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem; /* Consistent rounded corners */
            cursor: pointer;
            text-decoration: none;
            display: inline-flex; /* Use flex for vertical alignment of text */
            align-items: center;
            justify-content: center;
            font-size: 0.9em;
            transition: background-color 0.2s ease, transform 0.1s ease;
            flex-shrink: 0; /* Prevent these items from shrinking */
        }
        .auth-status a:hover,
        .auth-status button:hover,
        .auth-status form:hover {
            background-color: #5a6268;
            transform: translateY(-1px); /* Slight lift effect */
        }
        .auth-status button.logout-btn { background-color: #dc3545; }
        .auth-status button.logout-btn:hover { background-color: #c82333; }


        /* Main content area - Consistent */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 800px; /* Adjusted max-width for forms */
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
        }
        main h2 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 25px;
            font-size: 1.6em;
            text-align: center;
        }


        /* Message Styling (Feedback) - Consistent */
        .message {
            padding: 12px;
            margin-bottom: 25px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 0.95em;
            border-left: 5px solid; /* Add a colored border on the left */
            width: 100%;
            box-sizing: border-box;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #dc3545;
        }
        .message.info {
            background-color: #e2f0ff;
            color: #0056b3;
            border-color: #007bff;
        }

        /* Form specific styles - Consistent with other forms */
        .reset-password-form-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
        }
        .reset-password-form-container p {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.1em;
            color: #333;
        }
        .reset-password-form-container label {
            display: block;
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .reset-password-form-container input[type="password"] {
            width: 100%; /* Full width */
            padding: 10px; /* Consistent padding */
            margin-bottom: 18px; /* Consistent margin */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box; /* Include padding in width */
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .reset-password-form-container button {
            width: 100%;
            padding: 12px; /* Consistent padding */
            background-color: #007bff; /* Primary blue for reset button */
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            margin-top: 10px;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .reset-password-form-container button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        p.back-link { 
            text-align: center; 
            margin-top: 30px; /* Increased margin for consistency */
        }
        p.back-link a { 
            color: #007bff; 
            text-decoration: none; 
            font-weight: 600; /* Bolder for prominence */
            transition: color 0.2s ease;
        }
        p.back-link a:hover { 
            text-decoration: underline; 
            color: #0056b3;
        }

        /* Footer Styles - Consistent */
        footer {
            background-color: #205072;
            color: white;
            text-align: center;
            padding: 1rem 2rem;
            margin-top: 40px; /* Space above footer */
            font-size: 0.85em;
            box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.1);
        }
        footer p {
            margin: 0;
            color: #e0e0e0;
        }
        footer a {
            color: #a7d9eb; /* Lighter blue for links */
            text-decoration: none;
            transition: color 0.2s ease;
        }
        footer a:hover {
            color: #ffffff;
            text-decoration: underline;
        }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            header {
                flex-direction: column;
                padding: 1rem;
                position: static; /* Unfix header on small screens for better scrolling */
                box-shadow: none; /* Remove shadow if not fixed */
            }
            main {
                padding: 15px;
                margin: 15px auto;
                padding-top: 15px; /* No fixed header to account for */
            }
            header h1 {
                font-size: 1.5em;
            }
            .auth-status {
                margin-top: 10px;
                flex-direction: column;
                gap: 10px;
            }
            .auth-status a,
            .auth-status button,
            .auth-status form {
                width: 100%; /* Full width on smaller screens */
            }
            .reset-password-form-container {
                padding: 20px;
                width: auto; /* Allow form to take full width */
            }
            .reset-password-form-container input[type="password"],
            .reset-password-form-container button {
                width: 100%; /* Ensure full width on smaller screens */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Reset User Password</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <div class="reset-password-form-container">
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
            <?php endif; ?>

            <?php if ($targetUser): ?>
                <h2>Reset Password for: <?php echo htmlspecialchars($targetUser['full_name']); ?> (<?php echo htmlspecialchars($targetUser['username']); ?>)</h2>
                <form action="reset_password.php?id=<?php echo htmlspecialchars($targetUser['user_id']); ?>" method="POST">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($targetUser['user_id']); ?>">
                    
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8">

                    <label for="confirm_password">Confirm New Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

                    <button type="submit">Reset Password</button>
                </form>
            <?php else: ?>
                <p class="message error">User data could not be loaded. Please return to the user management page.</p>
            <?php endif; ?>
        </div>
        <p class="back-link"><a href="manage_users.php">Back to Manage Staff Users</a></p>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
