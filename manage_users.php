<?php
// pharmacy_management_system/manage_users.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only 'Admin' role can access User Management
$currentUser = getCurrentUser();
if (!isset($currentUser) || $currentUser['role'] !== 'Admin') { // PHP 6.9 compatible
    header('Location: staff_login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';
$users = array(); // PHP 6.9 compatible array()

// Fetch all users from the database
try {
    $stmt = $pdo->query("SELECT user_id, full_name, username, role, created_at FROM Users ORDER BY full_name ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage = "Database error: Could not load user data. " . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
}

// Display feedback message from session if redirected (e.g., after adding/editing/deleting a user)
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
    <title>Manage Staff Users - Woloma Pharmacy</title>
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
            max-width: 1000px; /* Adjusted max-width for tables */
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

        /* Action Buttons (Add New User) - Consistent */
        .action-buttons {
            margin-bottom: 25px; /* Consistent margin */
            text-align: right;
        }
        .action-buttons .btn {
            background-color: #28a745; /* Green for add action */
            color: white;
            padding: 10px 18px; /* Adjusted padding */
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            text-decoration: none;
            font-size: 1em; /* Increased font size */
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: inline-flex; /* For better alignment */
            align-items: center;
            justify-content: center;
        }
        .action-buttons .btn:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Table Styling - Consistent */
        table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 20px; /* Consistent margin */
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        th, td {
            border: none; /* Remove individual cell borders */
            padding: 12px;
            text-align: left;
            vertical-align: middle; /* Align content vertically */
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        tr:nth-child(even) { background-color: #fcfcfc; } /* Changed to even for better contrast */
        tr:last-child td { border-bottom: none; } /* No border on last row */
        tr:hover { background-color: #e2e6ea; }

        /* Specific table header/footer rounding */
        thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }


        /* Action Buttons (Table) - Consistent */
        .btn-action {
            padding: 6px 10px; /* Adjusted padding */
            border: none;
            border-radius: 4px; /* Consistent rounding */
            cursor: pointer;
            text-decoration: none;
            font-size: 0.85em; /* Adjusted font size */
            margin-right: 5px;
            display: inline-flex; /* Allows side-by-side buttons */
            align-items: center;
            justify-content: center;
            min-width: 60px; /* Ensure buttons have minimum width */
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); /* Subtle shadow */
        }
        .btn-edit { background-color: #ffc107; color: #333; } /* Yellow */
        .btn-edit:hover { background-color: #e0a800; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15); }
        .btn-delete { background-color: #dc3545; color: white; } /* Red */
        .btn-delete:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15); }
        .btn-reset-password { background-color: #007bff; color: white; } /* Blue */
        .btn-reset-password:hover { background-color: #0056b3; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15); }

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
        @media (max-width: 992px) { /* Tablet and smaller desktop */
            table {
                display: block; /* Make table scrollable */
                overflow-x: auto; /* Enable horizontal scroll */
                white-space: nowrap; /* Prevent content wrapping */
            }
            th, td {
                padding: 10px; /* Reduce padding on smaller screens */
                font-size: 0.8em; /* Smaller font for table content */
            }
            .btn-action {
                padding: 5px 8px; /* Smaller action buttons */
                font-size: 0.75em;
                min-width: 50px;
            }
        }

        @media (max-width: 768px) { /* Mobile devices */
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
            main h2 {
                font-size: 1.4em;
                margin-bottom: 15px;
            }
            .message {
                margin-bottom: 15px;
            }
            .action-buttons {
                text-align: center; /* Center the "Add New User" button */
            }
            .action-buttons .btn {
                width: 100%; /* Full width for the add button */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Manage Staff Users</h1>
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
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <div class="action-buttons">
            <a href="add_user.php" class="btn">Add New User</a>
        </div>

        <?php if (empty($users)): ?>
            <p class="message info">No staff users found in the system.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($user['created_at']))); ?></td>
                            <td>
                                <a href="edit_user.php?id=<?php echo htmlspecialchars($user['user_id']); ?>" class="btn-action btn-edit">Edit</a>
                                <a href="reset_password.php?id=<?php echo htmlspecialchars($user['user_id']); ?>" class="btn-action btn-reset-password">Reset Password</a>
                                <?php if ($user['user_id'] !== $currentUser['user_id']): // Prevent admin from deleting themselves ?>
                                    <a href="delete_user.php?id=<?php echo htmlspecialchars($user['user_id']); ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['username']); ?>)? This action cannot be undone.');">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
