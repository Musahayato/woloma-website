<?php
// pharmacy_management_system/staff_dashboard.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Restrict access: Only Pharmacists and Admins can access this dashboard
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], array('Pharmacist', 'Admin'))) { // PHP 6.9 compatible array()
    header('Location: staff_login.php');
    exit();
}

$feedbackMessage = '';
$feedbackStatus = ''; // Initialize status for consistent feedback message display

// Display feedback message from session if redirected (e.g., from an action)
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = ($_SESSION['feedback_status'] === 'success') ? 'success' : 'error';
    unset($_SESSION['feedback_message']); // Clear message after display
    unset($_SESSION['feedback_status']);
}

// Optional: Fetch a quick summary of pending online orders
$pendingOrdersCount = 0;
try {
    $pdo = getDbConnection();
    // Only count orders that are specifically from online channels (pickup/delivery)
    // and need action by staff (e.g., payment verification)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Sales WHERE order_status = 'Pending Payment Verification' AND order_type IN ('pickup', 'delivery')");
    $stmt->execute();
    $pendingOrdersCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching pending orders count for staff dashboard: " . $e->getMessage());
    $feedbackMessage = '<p class="message error">Could not load pending orders count.</p>'; // Assign directly if no prior message
    $feedbackStatus = 'error'; // Set status for consistency
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles */
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

        /* Header Styles - Consistent with other pages */
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
        .auth-status button.logout-btn {
            background-color: #dc3545; /* Red color for logout button */
        }
        .auth-status button.logout-btn:hover {
            background-color: #c82333; /* Darker red on hover */
        }

        /* Main content area */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1200px;
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
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

        .dashboard-welcome {
            text-align: center;
            margin-bottom: 30px;
            color: #205072; /* Darker blue for headings */
        }
        .dashboard-welcome h2 {
            margin: 0 0 10px 0;
            font-size: 2.2em; /* Slightly larger heading */
        }
        .dashboard-welcome p {
            font-size: 1.1em;
            color: #555;
            max-width: 700px;
            margin: 0 auto;
        }

        .dashboard-cards {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
        }
        .card {
            background-color: #e9f5ff; /* Light blue */
            padding: 25px;
            border-radius: 10px; /* More rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); /* Stronger shadow */
            text-align: center;
            flex: 1 1 calc(33.333% - 20px); /* 3 cards per row with gap */
            min-width: 280px; /* Ensure cards don't get too small */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box; /* Include padding in width */
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15); /* More pronounced shadow on hover */
        }
        .card h3 {
            color: #205072; /* Darker blue for card titles */
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.6em; /* Larger title */
        }
        .card p {
            font-size: 1em;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .card p span { /* For pending orders count */
            font-size: 1.8em; /* Larger number */
            color: #007bff; /* Primary blue for numbers */
            font-weight: bold;
            display: block; /* Ensures it's on its own line */
            margin-bottom: 5px;
        }
        .card .btn-card {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007bff; /* Primary blue button */
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounded corners */
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .card .btn-card:hover {
            background-color: #0056b3; /* Darker blue on hover */
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Footer Styles - Consistent with other pages */
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
        @media (max-width: 992px) {
            .dashboard-cards .card {
                flex: 1 1 calc(50% - 20px); /* 2 cards per row on tablets */
            }
        }

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
            .dashboard-welcome h2 {
                font-size: 1.8em;
            }
            .dashboard-welcome p {
                font-size: 0.95em;
            }
            .dashboard-cards .card {
                flex: 1 1 100%; /* Stack cards vertically on mobile */
                min-width: unset; /* Remove min-width on mobile */
            }
            .card h3 {
                font-size: 1.4em;
            }
            .card p span {
                font-size: 1.5em;
            }
            .card .btn-card {
                width: 100%; /* Full width buttons on mobile */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Staff Dashboard</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <div class="dashboard-welcome">
            <h2>Welcome to the Woloma Pharmacy Staff Portal!</h2>
            <p>Here you can manage online orders, handle in-person sales, and maintain pharmacy data.</p>
        </div>

        <div class="dashboard-cards">
            <div class="card">
                <h3>Online Orders</h3>
                <p>You have <span style="font-size: 1.5em; color: #007bff; font-weight: bold;"><?php echo htmlspecialchars($pendingOrdersCount); ?></span> pending online orders awaiting payment verification.</p>
                <a href="manage_online_orders.php" class="btn-card">Manage Orders</a>
            </div>

            <div class="card">
                <h3>In-Person Sale</h3>
                <p>Process a new sale for customers paying directly at the pharmacy.</p>
                <a href="new_in_person_sale.php" class="btn-card">Start New Sale</a>
            </div>

            <div class="card">
                <h3>Manage Drugs & Stock</h3>
                <p>View and manage drug inventory.</p>
                <a href="manage_drugs_stock.php" class="btn-card">Go to Management</a>
            </div>

            <?php if ($currentUser['role'] === 'Admin'): ?>
            <div class="card">
                <h3>User Management</h3>
                <p>Manage staff and customer accounts.</p>
                <a href="manage_users.php" class="btn-card">Manage Users</a>
            </div>
            <div class="card">
                <h3>Pharmacy Reports</h3>
                <p>View sales summaries, stock levels, and expiry alerts.</p>
                <a href="reports.php" class="btn-card">View Reports</a>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
