<?php
// pharmacy_management_system/customer_register.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php'; // Include auth_check for consistent redirection

// If user is already logged in, redirect them to their dashboard
// Using redirectIfLoggedIn from auth_check.php if it exists, otherwise manual check
// Assuming customer_dashboard.php is the target for logged-in customers
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'Customer') {
    header('Location: customer_dashboard.php');
    exit();
}
// If it's a staff user somehow trying to register as a customer, redirect to staff dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] !== 'Customer') {
    header('Location: staff_dashboard.php');
    exit();
}


$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = ''; // Initialize for consistent feedback styling

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use isset() for all $_POST variables for PHP 6.9 compatibility
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $first_name = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
    $last_name = trim(isset($_POST['last_name']) ? $_POST['last_name'] : '');
    $phone_number = trim(isset($_POST['phone_number']) ? $_POST['phone_number'] : '');
    $address = trim(isset($_POST['address']) ? $_POST['address'] : '');
    $date_of_birth = trim(isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : '');
    $gender = trim(isset($_POST['gender']) ? $_POST['gender'] : '');

    // --- Server-side Validation ---
    $errors = [];

    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }

    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($first_name)) {
        $errors[] = 'First Name is required.';
    } elseif (strlen($first_name) > 50) {
        $errors[] = 'First Name cannot exceed 50 characters.';
    }

    if (empty($last_name)) {
        $errors[] = 'Last Name is required.';
    } elseif (strlen($last_name) > 50) {
        $errors[] = 'Last Name cannot exceed 50 characters.';
    }

    if (empty($phone_number)) {
        $errors[] = 'Phone Number is required.';
    } elseif (!preg_match('/^2519\d{8}$/', $phone_number)) {
        $errors[] = 'Phone number must be 10 digits and start with 2519 (e.g., 2519XXXXXXXX).';
    }

    if (strlen($address) > 255) {
        $errors[] = 'Address cannot exceed 255 characters.';
    }

    if (!empty($date_of_birth) && strtotime($date_of_birth) > time()) {
        $errors[] = 'Date of Birth cannot be in the future.';
    }


    if (!empty($errors)) {
        $feedbackMessage = 'Error: ' . implode('<br>', $errors);
        $feedbackStatus = 'error';
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction(); // Start a transaction for atomicity

            // Check if username already exists
            $stmtCheckUsername = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE username = ?");
            $stmtCheckUsername->execute(array($username)); // Use array() for PHP 6.9
            if ($stmtCheckUsername->fetchColumn() > 0) {
                throw new Exception("The username '" . htmlspecialchars($username) . "' is already taken. Please choose another.");
            }

            // Check if phone number already exists
            $stmtCheckPhone = $pdo->prepare("SELECT COUNT(*) FROM Customers WHERE phone_number = ?");
            $stmtCheckPhone->execute(array($phone_number)); // Use array() for PHP 6.9
            if ($stmtCheckPhone->fetchColumn() > 0) {
                throw new Exception("The phone number '" . htmlspecialchars($phone_number) . "' is already registered. Please use a different one or log in.");
            }

            // 1. Insert into Users table with 'Customer' role
            $stmtUser = $pdo->prepare("INSERT INTO Users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'Customer')");
            $stmtUser->execute(array($username, $password_hash, $first_name . ' ' . $last_name)); // Use array() for PHP 6.9
            $newUserId = $pdo->lastInsertId();

            if (!$newUserId) {
                throw new Exception("Failed to create user account.");
            }

            // 2. Insert into Customers table, linking to the new user_id
            $stmtCustomer = $pdo->prepare("INSERT INTO Customers (first_name, last_name, phone_number, address, date_of_birth, gender, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtCustomer->execute(array( // Use array() for PHP 6.9
                $first_name,
                $last_name,
                $phone_number,
                !empty($address) ? $address : null,
                !empty($date_of_birth) ? $date_of_birth : null,
                !empty($gender) ? $gender : null,
                $newUserId
            ));
            $newCustomerId = $pdo->lastInsertId();

            if (!$newCustomerId) {
                throw new Exception("Failed to create customer record.");
            }

            $pdo->commit(); // Commit the transaction

            // Log the new customer in automatically
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $first_name . ' ' . $last_name;
            $_SESSION['role'] = 'Customer';
            $_SESSION['customer_id'] = $newCustomerId; // Set customer_id in session

            // Set session feedback message for consistency
            $_SESSION['feedback_message'] = 'Registration successful! Welcome to Woloma Pharmacy Online.';
            $_SESSION['feedback_status'] = 'success';
            header('Location: customer_dashboard.php'); // Redirect to customer-specific dashboard
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on database error
            $feedbackMessage = 'Database error during registration: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';

            // More specific duplicate entry error handling (PHP 6.9 compatible)
            if ($e->getCode() == '23000') { // Integrity constraint violation
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    if (strpos($e->getMessage(), 'username') !== false) {
                        $feedbackMessage = 'Error: The username "' . htmlspecialchars($username) . '" is already taken. Please choose another.';
                    } elseif (strpos($e->getMessage(), 'phone_number') !== false) {
                        $feedbackMessage = 'Error: The phone number "' . htmlspecialchars($phone_number) . '" is already registered. Please use a different one or log in.';
                    } else {
                        $feedbackMessage = 'Error: A record with this unique information already exists. Please check your inputs.';
                    }
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack(); // Rollback on custom exceptions
            $feedbackMessage = 'Registration failed: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// Prepare values to re-populate the form if there's an error
// Use isset() for all $_POST variables for PHP 6.9 compatibility
$formUsername = isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '';
$formFirstName = isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '';
$formLastName = isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '';
$formPhoneNumber = isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : '';
$formAddress = isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '';
$formDateOfBirth = isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '';
$formGender = isset($_POST['gender']) ? htmlspecialchars($_POST['gender']) : '';

// Display feedback message from session if redirected (e.g., from login.php)
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = $_SESSION['feedback_status'];
    unset($_SESSION['feedback_message']); // Clear message after display
    unset($_SESSION['feedback_status']); // Clear status after display
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration - Woloma Pharmacy</title>
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

        /* Header Styles - Consistent across pages (adapted for registration) */
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

        /* Main Content Area - Centering the form */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 800px; /* Adjusted max-width for forms */
            margin: 25px auto; /* Center the main content block */
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
            display: flex;
            flex-direction: column;
            align-items: center; /* Center content horizontally within main */
        }

        /* Message Styling (Feedback) - Consistent */
        .message {
            padding: 12px;
            margin-bottom: 25px; /* Increased margin */
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 0.95em;
            border-left: 5px solid; /* Add a colored border on the left */
            width: 100%; /* Ensure message takes full width */
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
        .message.warning {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }
        .message.info {
            background-color: #e2f0ff;
            color: #0056b3;
            border-color: #007bff;
        }

        /* Form Container Styles - Consistent with other forms */
        .form-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
            width: 100%; /* Take full width of its parent (main) */
            max-width: 450px; /* Limit width of the form itself for readability */
            box-sizing: border-box;
        }
        .form-container h2 {
            color: #205072; /* Darker blue for headings */
            text-align: center;
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 25px; /* Increased margin */
            font-size: 1.6em;
        }
        .form-group {
            margin-bottom: 18px; /* Consistent margin */
        }
        .form-group label {
            display: block;
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="tel"],
        .form-group input[type="date"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box;
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .form-group textarea {
            height: auto; /* Allow textarea to expand */
            min-height: 80px; /* Minimum height for textarea */
            resize: vertical; /* Allow vertical resizing */
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .form-group small {
            color: #6c757d;
            font-size: 0.85em;
            display: block;
            margin-top: 5px;
        }
        .form-container button[type="submit"] {
            width: 100%;
            padding: 12px;
            background-color: #28a745; /* Green for submit button */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 15px; /* Adjusted margin */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .form-container button[type="submit"]:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .login-link {
            text-align: center;
            margin-top: 25px; /* Adjusted margin */
            font-size: 0.95em;
            color: #555;
        }
        .login-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s ease, text-decoration 0.2s ease;
        }
        .login-link a:hover {
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
            .header-links {
                margin-top: 10px;
                flex-direction: column;
                gap: 10px;
            }
            .header-links a {
                width: 100%; /* Full width on smaller screens */
            }
            .form-container {
                padding: 20px;
                width: auto; /* Allow form to take full width of main */
            }
            .form-group input,
            .form-group textarea,
            .form-group select,
            .form-container button[type="submit"] {
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
        <h1>Customer Registration</h1>
        <div class="header-links">
            <a href="login.php">Login</a>
        </div>
    </header>

    <main>
        <div class="form-container">
            <h2>Create Your Account</h2>
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
            <?php endif; ?>
            <form action="customer_register.php" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" value="<?php echo $formUsername; ?>" required maxlength="50">
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
                    <label for="first_name">First Name:</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo $formFirstName; ?>" required maxlength="50">
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name:</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo $formLastName; ?>" required maxlength="50">
                </div>

                <div class="form-group">
                    <label for="phone_number">Phone Number:</label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo $formPhoneNumber; ?>" placeholder="e.g., 2519XXXXXXXX" required pattern="^2519\d{8}$" title="Phone number must be 10 digits and start with 2519.">
                </div>

                <div class="form-group">
                    <label for="address">Address (Optional):</label>
                    <textarea id="address" name="address" rows="3" placeholder="Street, City, Region" maxlength="255"><?php echo $formAddress; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth (Optional):</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo $formDateOfBirth; ?>">
                </div>

                <div class="form-group">
                    <label for="gender">Gender (Optional):</label>
                    <select id="gender" name="gender">
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo ($formGender == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($formGender == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo ($formGender == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <button type="submit">Register Account</button>
            </form>
            <p class="login-link">Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
