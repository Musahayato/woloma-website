<?php
// pharmacy_management_system/staff_login.php
session_start();

require_once 'includes/db.php';

$feedbackMessage = '';
$feedbackStatus = ''; // Initialize for consistent feedback styling

// If a user is already logged in, redirect them based on their role
if (isset($_SESSION['user_id'])) {
    $pdo = getDbConnection();
    try {
        $stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
        $stmt->execute(array($_SESSION['user_id'])); // Use array() for PHP 6.9 compatibility
        $userRole = $stmt->fetchColumn();

        if ($userRole === 'Customer') {
            header('Location: customer_dashboard.php');
            exit();
        } elseif (in_array($userRole, array('Pharmacist', 'Admin'))) { // Use array() for PHP 6.9 compatibility
            header('Location: staff_dashboard.php');
            exit();
        }
    } catch (PDOException $e) {
        error_log("Error checking existing user role in staff_login.php: " . $e->getMessage());
        $feedbackMessage = 'A database error occurred. Please try again.';
        $feedbackStatus = 'error';
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(isset($_POST['username']) ? $_POST['username'] : ''); 
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password)) {
        $feedbackMessage = 'Please enter both username and password.';
        $feedbackStatus = 'error';
    } else {
        $pdo = getDbConnection();
        try {
            // Fetch user by username
            $stmt = $pdo->prepare("SELECT user_id, username, password_hash, full_name, role FROM Users WHERE username = ?");
            $stmt->execute(array($username)); // Use array() for PHP 6.9 compatibility
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password and check role (only Pharmacist or Admin can log in here)
            if ($user && password_verify($password, $user['password_hash']) && in_array($user['role'], array('Pharmacist', 'Admin'))) { // Use array() for PHP 6.9 compatibility
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role']; // Store role in session

                // Set a success message in session before redirecting
                $_SESSION['feedback_message'] = 'Login successful! Welcome.';
                $_SESSION['feedback_status'] = 'success';

                // Redirect to staff dashboard
                header('Location: staff_dashboard.php');
                exit();
            } else {
                $feedbackMessage = 'Invalid username, password, or insufficient permissions.';
                $feedbackStatus = 'error';
            }
        } catch (PDOException $e) {
            $feedbackMessage = 'A database error occurred during login. Please try again.';
            $feedbackStatus = 'error';
            error_log("Login error: " . $e->getMessage()); // Log the error for debugging
        }
    }
}

// Display feedback message from session if redirected
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = $_SESSION['feedback_status'];
    unset($_SESSION['feedback_message']); // Clear message after display
    unset($_SESSION['feedback_status']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent with other pages */
        body {
            font-family: 'Inter', Arial, sans-serif; /* Consistent font */
            background-color: #f0f2f5; /* Light grey background */
            margin: 0;
            padding: 0;
            line-height: 1.6;
            color: #333;
            display: flex;
            flex-direction: column; /* For header, main, footer layout */
            min-height: 100vh; /* Ensure body takes full viewport height */
        }

        /* Header (for login page, minimal) */
        header {
            background-color: #205072; /* Darker blue for header */
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
            box-sizing: border-box;
        }
        header h1 {
            margin: 0;
            font-size: 1.8em;
            font-weight: 600;
        }
        .header-links { /* New container for header navigation */
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-links a {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9em;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }
        .header-links a:hover {
            background-color: #5a6268;
            transform: translateY(-1px);
        }


        /* Main content area - Centering the form */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 800px; /* Adjusted max-width for forms */
            margin: 25px auto; /* Center the main content block */
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            display: flex;
            flex-direction: column;
            align-items: center; /* Center content horizontally within main */
            justify-content: center; /* Center content vertically within main */
        }

        /* Login Container Styles - Consistent with other forms */
        .login-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
            width: 100%; /* Take full width of its parent (main) */
            max-width: 400px; /* Limit width of the form itself for readability */
            box-sizing: border-box;
            text-align: center;
        }
        .login-container h2 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 25px;
            font-size: 1.6em;
            text-align: center;
        }
        .form-group {
            margin-bottom: 18px; /* Consistent margin */
            text-align: left; /* Align labels/inputs to left */
        }
        .form-group label {
            display: block;
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box;
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .form-group input:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #007bff; /* Primary blue for login button */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 15px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .btn-login:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Message Styling (Feedback) - Consistent with other pages */
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

        .link-back {
            margin-top: 20px;
            font-size: 0.95em;
            color: #555;
        }
        .link-back a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s ease, text-decoration 0.2s ease;
        }
        .link-back a:hover {
            text-decoration: underline;
            color: #0056b3;
        }

        /* Footer Styles - Consistent across pages */
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

        /* Responsive Adjustments - Consistent across pages */
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
            }
            header h1 {
                font-size: 1.5em;
            }
            .header-links {
                margin-top: 10px;
                flex-direction: column;
                gap: 10px;
            }
            .header-links a {
                width: 100%; /* Full width on smaller screens */
            }
            .login-container {
                padding: 20px;
                width: auto; /* Allow form to take full width */
            }
            .form-group input[type="text"],
            .form-group input[type="password"],
            .btn-login {
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
        <h1>Woloma Pharmacy</h1>
        <div class="header-links">
            <a href="login.php">Customer Login</a>
        </div>
    </header>
    <main>
        <div class="login-container">
            <h2>Staff Login</h2>
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
            <?php endif; ?>
            <form action="staff_login.php" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn-login">Login</button>
            </form>
            <div class="link-back">
                <p><a href="login.php">Are you a customer? Log in here.</a></p>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
