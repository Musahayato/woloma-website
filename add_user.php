<?php
// pharmacy_management_system/add_user.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only 'Admin' role can add new users
redirectIfNotRole(['Admin'], 'staff_login.php');

$currentUser = getCurrentUser(); // Get current user info

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';

// Define allowed roles for the dropdown
$allowedRoles = ['Admin', 'Pharmacist', 'Cashier']; // Add or remove roles as per your system's setup

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim(isset($_POST['full_name']) ? $_POST['full_name']: '');
    $username = trim(isset($_POST['username']) ? $_POST['username']: '');
    $password = isset($_POST['password'])?$_POST['password']: '';
    $confirmPassword = isset($_POST['confirm_password']) ?$_POST['confirm_password']: '';
    $role = trim(isset($_POST['role']) ?$_POST['role']: '');

    // --- Server-side Validation ---
    $errors = [];

    if (empty($fullName)) {
        $errors[] = "Full Name is required.";
    } elseif (strlen($fullName) > 255) { // Added full name length validation
        $errors[] = "Full Name cannot exceed 255 characters.";
    }

    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    }

    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Password and confirm password do not match.";
    }

    if (empty($role)) {
        $errors[] = "Role is required.";
    } elseif (!in_array($role, $allowedRoles)) {
        $errors[] = "Invalid role selected.";
    }

    if (!empty($errors)) {
        $feedbackMessage = '<p>' . implode('<br>', $errors) . '</p>';
        $feedbackStatus = 'error';
    } else {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $feedbackMessage = "Username already exists. Please choose a different one.";
                $feedbackStatus = 'error';
            } else {
                // Hash the password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user into the database
                $stmt = $pdo->prepare("INSERT INTO Users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$fullName, $username, $passwordHash, $role]);

                $_SESSION['feedback_message'] = "User '" . htmlspecialchars($username) . "' added successfully!";
                $_SESSION['feedback_status'] = "success";
                header('Location: manage_users.php'); // Redirect to user list
                exit();
            }
        } catch (PDOException $e) {
            $feedbackMessage = "Database error: Could not add user. " . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// Prepare values to re-populate the form if there's an error
$formFullName = isset($_POST['full_name']) ? $_POST['full_name']: '';
$formUsername = isset($_POST['username']) ? $_POST['username']: '';
$formRole = isset($_POST['role']) ? $_POST['role']: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Staff User - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent with add_stock.php */
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

        /* Header Styles - Consistent with add_stock.php */
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

        /* Main content area - Consistent with add_stock.php */
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

        /* Message Styling (Feedback) - Consistent with add_stock.php */
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

        /* Form specific styles - Consistent with add_stock.php */
        .form-container { /* Renamed from add-stock-form-container to generic .form-container */
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
        }
        .form-container h3 {
            margin-bottom: 20px;
            color: #205072; /* Darker blue for headings */
            text-align: center;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em;
        }
        .form-container label {
            display: block;
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .form-container input[type="text"],
        .form-container input[type="password"],
        .form-container select {
            width: 100%; /* Full width */
            padding: 10px; /* Consistent padding */
            margin-bottom: 18px; /* Consistent margin */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box; /* Include padding in width */
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .form-container small {
            font-size: 0.85em;
            color: #666;
            margin-top: -10px; /* Pull closer to input */
            margin-bottom: 10px;
            display: block;
        }
        .form-container button {
            width: 100%;
            padding: 12px; /* Consistent padding */
            background-color: #28a745; /* Green for add button */
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
        .form-container button:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .back-link {
            text-align: center;
            margin-top: 30px; /* Increased margin for consistency */
        }
        .back-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: 600; /* Bolder for prominence */
            transition: color 0.2s ease;
        }
        .back-link a:hover {
            text-decoration: underline;
            color: #0056b3;
        }

        /* Footer Styles - Consistent with add_stock.php */
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


        /* Responsive Adjustments - Consistent with add_stock.php */
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
            .form-container { /* Applies to this form container */
                padding: 20px;
                width: auto; /* Allow form to take full width */
            }
            .form-container input[type="text"],
            .form-container input[type="password"],
            .form-container select,
            .form-container button {
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
        <h1>Add New Staff User</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <div class="form-container">
            <h3>New Staff Account Details</h3>
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo $feedbackStatus; ?>"><?php echo $feedbackMessage; ?></p>
            <?php endif; ?>

            <form action="add_user.php" method="POST">
                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($formFullName); ?>" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($formUsername); ?>" required maxlength="50">
                    <small>Only letters, numbers, and underscores. Min 3 characters.</small>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required minlength="6">
                    <small>Min 6 characters.</small>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="role">Role:</label>
                    <select id="role" name="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($allowedRoles as $roleOption): ?>
                            <option value="<?php echo htmlspecialchars($roleOption); ?>"
                                <?php echo ($formRole === $roleOption) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($roleOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Add User</button>
            </form>
        </div>
        <p class="back-link"><a href="manage_users.php">Back to Manage Users</a></p>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
