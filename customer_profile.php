<?php
// pharmacy_management_system/customer_profile.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Ensure only logged-in customers can access this page
$currentUser = getCurrentUser();
if (!isset($currentUser) || $currentUser['role'] !== 'Customer') {
    header('Location: login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';

// Fetch customer data
$customerData = array();
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.full_name, c.customer_id, c.first_name, c.last_name, c.phone_number, c.address, c.date_of_birth, c.gender
        FROM Users u
        JOIN Customers c ON u.user_id = c.user_id
        WHERE u.user_id = ? AND u.role = 'Customer'
    ");
    $stmt->execute(array($currentUser['user_id']));
    $customerData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customerData) {
        // Customer data not found, something is wrong with session or database
        $feedbackMessage = 'Error: Customer profile data not found.';
        $feedbackStatus = 'error';
        // Consider logging out the user if their data is inconsistent
        // session_destroy();
        // header('Location: login.php');
        // exit();
    }
} catch (PDOException $e) {
    $feedbackMessage = 'Database error fetching profile: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
}

// Handle POST request for updating profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customerData) {
    $newFirstName = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
    $newLastName = trim(isset($_POST['last_name']) ? $_POST['last_name'] : '');
    $newPhoneNumber = trim(isset($_POST['phone_number']) ? $_POST['phone_number'] : '');
    $newAddress = trim(isset($_POST['address']) ? $_POST['address'] : '');
    $newDateOfBirth = trim(isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : '');
    $newGender = trim(isset($_POST['gender']) ? $_POST['gender'] : '');

    // Basic validation
    $errors = [];
    if (empty($newFirstName) || empty($newLastName) || empty($newPhoneNumber)) {
        $errors[] = 'First Name, Last Name, and Phone Number are required.';
    }
    // Phone number format validation (example for 10 digits starting with 2519)
    if (!preg_match('/^2519\d{8}$/', $newPhoneNumber)) {
        $errors[] = 'Phone number must be 10 digits and start with 2519 (e.g., 2519XXXXXXXX).';
    }
    if (strlen($newAddress) > 255) {
        $errors[] = 'Address cannot exceed 255 characters.';
    }
    // Date of birth validation (optional: check if it's a valid date and not in the future)
    if (!empty($newDateOfBirth) && strtotime($newDateOfBirth) > time()) {
        $errors[] = 'Date of Birth cannot be in the future.';
    }


    if (!empty($errors)) {
        $feedbackMessage = 'Error: ' . implode('<br>', $errors);
        $feedbackStatus = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // Check for duplicate phone number if it's being changed
            if ($newPhoneNumber !== $customerData['phone_number']) {
                $stmtCheckPhone = $pdo->prepare("SELECT COUNT(*) FROM Customers WHERE phone_number = ? AND customer_id != ?");
                $stmtCheckPhone->execute(array($newPhoneNumber, $customerData['customer_id']));
                if ($stmtCheckPhone->fetchColumn() > 0) {
                    throw new Exception("The phone number '" . htmlspecialchars($newPhoneNumber) . "' is already registered to another customer.");
                }
            }

            // Update Customers table
            $stmtUpdateCustomer = $pdo->prepare("
                UPDATE Customers
                SET first_name = ?, last_name = ?, phone_number = ?, address = ?, date_of_birth = ?, gender = ?
                WHERE customer_id = ?
            ");
            $stmtUpdateCustomer->execute(array(
                $newFirstName,
                $newLastName,
                $newPhoneNumber,
                !empty($newAddress) ? $newAddress : null,
                !empty($newDateOfBirth) ? $newDateOfBirth : null,
                !empty($newGender) ? $newGender : null,
                $customerData['customer_id']
            ));

            // Update Users table full_name if it changed
            $newFullName = $newFirstName . ' ' . $newLastName;
            if ($newFullName !== $customerData['full_name']) {
                $stmtUpdateUser = $pdo->prepare("UPDATE Users SET full_name = ? WHERE user_id = ?");
                $stmtUpdateUser->execute(array($newFullName, $currentUser['user_id']));
                $_SESSION['full_name'] = $newFullName; // Update session
            }

            $pdo->commit();

            // Refetch customer data to display updated info
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.username, u.full_name, c.customer_id, c.first_name, c.last_name, c.phone_number, c.address, c.date_of_birth, c.gender
                FROM Users u
                JOIN Customers c ON u.user_id = c.user_id
                WHERE u.user_id = ? AND u.role = 'Customer'
            ");
            $stmt->execute(array($currentUser['user_id']));
            $customerData = $stmt->fetch(PDO::FETCH_ASSOC);

            $_SESSION['feedback_message'] = 'Your profile has been updated successfully.';
            $_SESSION['feedback_status'] = 'success';
            header('Location: customer_profile.php'); // Redirect to self to clear POST data and show message
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $feedbackMessage = 'Database error updating profile: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        } catch (Exception $e) {
            $pdo->rollBack();
            $feedbackMessage = 'Error updating profile: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// Check for feedback messages from session (e.g., from registration redirect)
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
    <title>Customer Profile - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent across pages */
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

        /* Header Styles - Consistent across pages */
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
        /* Specific button colors for navigation (adapted for consistency) */
        .auth-status a.dashboard-btn { background-color: #007bff; }
        .auth-status a.dashboard-btn:hover { background-color: #0056b3; }
        .auth-status a.online-drugs-btn { background-color: #28a745; }
        .auth-status a.online-drugs-btn:hover { background-color: #218838; }
        .auth-status a.view-cart-btn { background-color: #17a2b8; }
        .auth-status a.view-cart-btn:hover { background-color: #138496; }
        .auth-status form button.logout-btn { background-color: #dc3545; }
        .auth-status form button.logout-btn:hover { background-color: #c82333; }


        /* Main Content Area - Consistent across pages */
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

        /* Message Styling (Feedback) - Consistent */
        .message {
            padding: 12px;
            margin-bottom: 25px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 0.95em;
            border-left: 5px solid; /* Add a colored border on the left */
            width: 100%; /* Ensure message takes full width in flex container */
            box-sizing: border-box; /* Include padding/border in width */
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
        .message.warning { /* Added info message style for consistency */
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }
        .message.info { /* Existing info message style - adapted */
            background-color: #e2f0ff;
            color: #0056b3;
            border-color: #007bff;
        }

        /* Profile Section Styles - Consistent with form containers */
        .profile-section {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
        }
        .profile-section h2 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em;
            text-align: center; /* Center form headings */
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
        .form-group input[type="text"]:focus,
        .form-group input[type="tel"]:focus,
        .form-group input[type="date"]:focus,
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
        button[type="submit"] {
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
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        button[type="submit"]:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
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
            .profile-section {
                padding: 20px;
            }
            .form-group input[type="text"],
            .form-group input[type="tel"],
            .form-group input[type="date"],
            .form-group textarea,
            .form-group select,
            button[type="submit"] {
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
        <h1>Customer Profile</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_dashboard.php" class="dashboard-btn">Dashboard</a>
            <a href="online_drugs.php" class="online-drugs-btn">Online Drugs</a>
            <a href="view_cart.php" class="view-cart-btn">View Cart</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>

    <main>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if ($customerData): ?>
            <div class="profile-section">
                <h2>Your Details</h2>
                <form action="customer_profile.php" method="POST">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($customerData['username']); ?>" disabled title="Username cannot be changed">
                        <small>Your username cannot be changed.</small>
                    </div>

                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($customerData['first_name']); ?>" required maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($customerData['last_name']); ?>" required maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="phone_number">Phone Number:</label>
                        <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($customerData['phone_number']); ?>" placeholder="e.g., 2519XXXXXXXX" required pattern="^2519\d{8}$" title="Phone number must be 10 digits and start with 2519.">
                    </div>

                    <div class="form-group">
                        <label for="address">Address (Optional):</label>
                        <textarea id="address" name="address" rows="3" placeholder="Street, City, Region" maxlength="255"><?php echo htmlspecialchars($customerData['address']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth (Optional):</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($customerData['date_of_birth']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender (Optional):</label>
                        <select id="gender" name="gender">
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo ($customerData['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($customerData['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($customerData['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <button type="submit">Update Profile</button>
                </form>
            </div>
        <?php else: ?>
            <p class="message error">Unable to load your profile. Please try again or contact support.</p>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
