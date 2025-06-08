<?php
// pharmacy_management_system/edit_user.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only 'Admin' role can edit users
redirectIfNotRole(['Admin'], 'staff_login.php');

$currentUser = getCurrentUser(); // Get current user info
$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';
$userToEdit = null; // Data of the user being edited

// Define allowed roles for the dropdown (same as add_user.php)
$allowedRoles = ['Admin', 'Pharmacist', 'Cashier'];

// Get user ID from URL
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId === 0) {
    $_SESSION['feedback_message'] = "Error: User ID not provided for editing.";
    $_SESSION['feedback_status'] = "error";
    header('Location: manage_users.php');
    exit();
}

// Fetch existing user data for display
try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, username, role FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userToEdit) {
        $_SESSION['feedback_message'] = "User not found or does not exist.";
        $_SESSION['feedback_status'] = "error";
        header('Location: manage_users.php');
        exit();
    }
} catch (PDOException $e) {
    $feedbackMessage = "Database error: Could not load user data. " . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
    $userToEdit = null; // Ensure no partial data is displayed
}

// Handle form submission for updating user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userToEdit) {
    $updatedFullName = trim(isset($_POST['full_name']) ?$_POST['full_name']: '');
    $updatedUsername = trim(isset($_POST['username'])?$_POST['username']: '');
    $updatedRole = trim(isset($_POST['role'])?$_POST['role']: '');
    $newPassword = isset($_POST['new_password']) ?$_POST['new_password']: ''; // Optional
    $confirmNewPassword = isset($_POST['confirm_new_password'])? $_POST['confirm_new_password']: ''; // Optional

    // Start with current values in case form fields are empty or not submitted
    $finalFullName = $userToEdit['full_name'];
    $finalUsername = $userToEdit['username'];
    $finalRole = $userToEdit['role'];
    $passwordToHash = null; // Only hash if password is provided

    $errors = [];

    // Validation for Full Name
    if (empty($updatedFullName)) {
        $errors[] = "Full Name cannot be empty.";
    } else {
        $finalFullName = $updatedFullName;
    }

    // Validation for Username
    if (empty($updatedUsername)) {
        $errors[] = "Username cannot be empty.";
    } elseif (strlen($updatedUsername) < 3) {
        $errors[] = "Username must be at least 3 characters long.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $updatedUsername)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    } else {
        // Check for duplicate username IF it's changed and is not the current user's username
        if ($updatedUsername !== $userToEdit['username']) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE username = ? AND user_id != ?");
                $stmt->execute([$updatedUsername, $userId]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Username already exists. Please choose a different one.";
                } else {
                    $finalUsername = $updatedUsername;
                }
            } catch (PDOException $e) {
                $errors[] = "Database error during username check: " . htmlspecialchars($e->getMessage());
            }
        } else {
            $finalUsername = $updatedUsername; // Username is the same, no need to check
        }
    }

    // Validation for Role
    if (!in_array($updatedRole, $allowedRoles)) {
        $errors[] = "Invalid role selected.";
    } else {
        // Prevent admin from demoting themselves
        if ($userId === $currentUser['user_id'] && $updatedRole !== 'Admin') {
            $errors[] = "You cannot change your own role from 'Admin'.";
        } else {
            $finalRole = $updatedRole;
        }
    }

    // Validation for Password (if provided)
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            $errors[] = "New password must be at least 6 characters long.";
        } elseif ($newPassword !== $confirmNewPassword) {
            $errors[] = "New password and confirm password do not match.";
        } else {
            $passwordToHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }

    if (!empty($errors)) {
        $feedbackMessage = "Error updating user: " . implode('<br>', $errors);
        $feedbackStatus = 'error';
        // Re-populate form with potentially invalid POST data to let user correct
        $userToEdit['full_name'] = $updatedFullName;
        $userToEdit['username'] = $updatedUsername;
        $userToEdit['role'] = $updatedRole;
    } else {
        try {
            $pdo->beginTransaction();

            // Prepare SQL query based on whether password is being updated
            $sql = "UPDATE Users SET full_name = ?, username = ?, role = ? ";
            $params = [$finalFullName, $finalUsername, $finalRole];

            if ($passwordToHash) {
                $sql .= ", password_hash = ? ";
                $params[] = $passwordToHash;
            }

            $sql .= "WHERE user_id = ?";
            $params[] = $userId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0 || !$passwordToHash) { // rowCount might be 0 if no fields changed except maybe password was empty
                $feedbackMessage = "User '" . htmlspecialchars($finalUsername) . "' updated successfully!";
                $feedbackStatus = 'success';
            } else {
                $feedbackMessage = "No changes were made to the user or an issue occurred.";
                $feedbackStatus = 'info';
            }

            $pdo->commit();

            // Re-fetch the updated data to display it immediately on the form
            $stmt = $pdo->prepare("SELECT user_id, full_name, username, role FROM Users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userToEdit = $stmt->fetch(PDO::FETCH_ASSOC);


        } catch (PDOException $e) {
            $pdo->rollBack();
            $feedbackMessage = "Database error during update: " . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        } catch (Exception $e) { // Catch the specific self-demotion error if needed
             $pdo->rollBack();
             $feedbackMessage = "Application error: " . htmlspecialchars($e->getMessage());
             $feedbackStatus = 'error';
        }
    }
}

// Use current userToEdit data for form pre-filling
$formFullName = isset($_POST['full_name']) ? $_POST['full_name'] : '';
$formUsername = isset($_POST['username']) ? $_POST['username'] : '';
$formRole = isset($_POST['role']) ? $_POST['role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Staff User - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Re-using styles from other forms */
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; }
        header { background-color: #007bff; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 24px; }
        .auth-status { display: flex; align-items: center; }
        .auth-status span { margin-right: 15px; }
        .auth-status a, .auth-status button {
            background-color: #6c757d; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;
            text-decoration: none; margin-left: 10px; display: inline-block;
        }
        .auth-status a:hover, .auth-status button:hover { background-color: #5a6268; }
        .auth-status button.logout-btn { background-color: #dc3545; }
        .auth-status button.logout-btn:hover { background-color: #c82333; }

        main { padding: 20px; max-width: 600px; margin: 20px auto; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 5px; text-align: center; font-weight: bold; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        /* Form Styling */
        .form-container {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .form-container h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px dashed #ddd;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .form-container form {
            display: grid;
            grid-template-columns: 1fr; /* Single column for simplicity */
            gap: 15px;
        }
        .form-container .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-container label {
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        .form-container input[type="text"],
        .form-container input[type="password"],
        .form-container select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box; /* Include padding in width */
        }
        .form-container button {
            padding: 10px 20px;
            background-color: #007bff; /* Blue for update */
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease;
        }
        .form-container button:hover { background-color: #0056b3; }

        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #007bff; text-decoration: none; font-weight: bold; }
        .back-link a:hover { text-decoration: underline; }

        .password-section {
            border-top: 1px dashed #ccc;
            padding-top: 15px;
            margin-top: 20px;
        }
        .password-section h4 {
            margin-top: 0;
            color: #555;
        }
        .warning-message {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <header>
        <h1>Edit Staff User</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo $feedbackStatus; ?>"><?php echo $feedbackMessage; ?></p>
        <?php endif; ?>

        <?php if ($userToEdit): ?>
            <div class="form-container">
                <h3>Editing User: <?php echo htmlspecialchars($userToEdit['full_name']); ?> (ID: <?php echo htmlspecialchars($userToEdit['user_id']); ?>)</h3>

                <form action="edit_user.php?id=<?php echo htmlspecialchars($userToEdit['user_id']); ?>" method="POST">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($userToEdit['user_id']); ?>">

                    <div class="form-group">
                        <label for="full_name">Full Name:</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo $formFullName; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" value="<?php echo $formUsername; ?>" required>
                        <small>Only letters, numbers, and underscores. Min 3 characters.</small>
                    </div>
                    <div class="form-group">
                        <label for="role">Role:</label>
                        <select id="role" name="role" required <?php echo ($userToEdit['user_id'] === $currentUser['user_id']) ? 'disabled' : ''; ?>>
                            <option value="">Select Role</option>
                            <?php foreach ($allowedRoles as $roleOption): ?>
                                <option value="<?php echo htmlspecialchars($roleOption); ?>"
                                    <?php echo ($formRole === $roleOption) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($roleOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($userToEdit['user_id'] === $currentUser['user_id']): ?>
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($formRole); ?>">
                            <small class="warning-message">You cannot change your own role.</small>
                        <?php endif; ?>
                    </div>

                    <div class="password-section">
                        <h4>Change Password (Optional)</h4>
                        <p class="warning-message">Leave these fields blank if you do not want to change the password.</p>
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password">
                            <small>Min 6 characters.</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm_new_password">Confirm New Password:</label>
                            <input type="password" id="confirm_new_password" name="confirm_new_password">
                        </div>
                    </div>

                    <button type="submit">Update User</button>
                </form>
            </div>
        <?php else: ?>
            <p class="message error">Error: User data could not be loaded.</p>
        <?php endif; ?>
        <p class="back-link"><a href="manage_users.php">Back to Manage Users</a></p>
    </main>
</body>
</html>