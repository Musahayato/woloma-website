<?php
// pharmacy_management_system/customer_dashboard.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in as a customer
$currentUser = getCurrentUser();
if (!isset($currentUser) || $currentUser['role'] !== 'Customer') {
    // If not logged in or not a customer, redirect to login
    header('Location: login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';
$newlyAddedDrugs = array(); // Initialize for PHP 6.9 compatibility

// --- Notification System: Fetch Newly Added/Stocked Drugs ---
$notificationThresholdDays = 7; // Define what 'recently added/stocked' means (e.g., last 7 days)

try {
    $stmtNewDrugs = $pdo->prepare("
        SELECT
            d.drug_id,
            d.drug_name,
            d.description,
            AVG(s.selling_price_per_unit) AS average_price,
            SUM(s.quantity) AS total_available_quantity,
            MAX(s.updated_at) AS latest_stock_date
        FROM
            Drugs d
        JOIN
            Stock s ON d.drug_id = s.drug_id
        WHERE
            s.expiry_date >= CURDATE() AND s.quantity > 0 AND s.updated_at >= (CURDATE() - INTERVAL ? DAY)
        GROUP BY
            d.drug_id, d.drug_name, d.description
        ORDER BY
            latest_stock_date DESC, d.drug_name ASC
    ");
    $stmtNewDrugs->execute(array($notificationThresholdDays)); // PHP 6.9 array() syntax
   $newlyAddedDrugs = $stmtNewDrugs->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log the error but don't stop the page from loading
    error_log("Error fetching newly added drugs: " . $e->getMessage());
    // Optionally set a feedback message for the user, but this isn't critical
}


// Check for feedback messages from session (e.g., from customer_register.php, customer_profile.php)
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
    <title>Customer Dashboard - Woloma Pharmacy</title>
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
        /* Specific button colors for navigation (retained and adjusted for consistency) */
        .auth-status a.primary-btn { background-color: #007bff; }
        .auth-status a.primary-btn:hover { background-color: #0056b3; }
        .auth-status a.success-btn { background-color: #28a745; }
        .auth-status a.success-btn:hover { background-color: #218838; }


        /* Main Content Area - Consistent across pages */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1200px; /* Adjusted max-width for better PC viewing */
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

        /* Welcome Section */
        .welcome-section {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef; /* Consistent border */
        }
        .welcome-section h2 {
            color: #205072; /* Darker blue for headings */
            margin-bottom: 10px;
            font-size: 2em; /* Slightly larger heading */
            font-weight: 700;
        }
        .welcome-section p {
            font-size: 1.1em;
            color: #555;
            line-height: 1.5;
        }

        /* Dashboard Grid & Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); /* Adjusted minmax for better fit */
            gap: 20px;
            margin-top: 30px;
        }
        .dashboard-card {
            background-color: #fdfdfd; /* Light background */
            border: 1px solid #e0e0e0; /* Consistent border */
            border-radius: 10px; /* Consistent rounded corners */
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .dashboard-card h3 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.5em; /* Adjusted heading size */
            font-weight: 600;
        }
        .dashboard-card p {
            color: #6c757d;
            margin-bottom: 20px;
            font-size: 0.95em;
        }
        .dashboard-card a {
            display: inline-block;
            background-color: #007bff; /* Default primary button color */
            color: white;
            padding: 10px 20px;
            border-radius: 6px; /* Consistent rounded corners */
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .dashboard-card a:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        /* Specific card button colors - retained and adapted */
        .dashboard-card.profile a { background-color: #28a745; } /* Green */
        .dashboard-card.profile a:hover { background-color: #218838; }
        .dashboard-card.drugs a { background-color: #17a2b8; } /* Cyan */
        .dashboard-card.drugs a:hover { background-color: #138496; }
        .dashboard-card.cart a { background-color: #6f42c1; } /* Purple */
        .dashboard-card.cart a:hover { background-color: #563d7c; }
        .dashboard-card.orders a { background-color: #ffc107; color: #333; } /* Yellow - special case for text color */
        .dashboard-card.orders a:hover { background-color: #e0a800; }

        /* Notification specific styling - adapted */
        .new-drugs-notification {
            background-color: #e0f7fa; /* Light blue background */
            border: 1px solid #a7d9eb; /* Softer blue border */
            color: #0056b3; /* Darker blue text */
            padding: 25px; /* Consistent padding */
            border-radius: 10px; /* Consistent rounded corners */
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
        }
        .new-drugs-notification h3 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.6em;
            text-align: center;
        }
        .new-drugs-notification p {
            color: #555;
            text-align: center;
            margin-bottom: 20px;
        }
        .new-drugs-notification ul {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
            max-height: 250px; /* Increased max height */
            overflow-y: auto;
            border-top: 1px dashed #cceeff; /* Consistent dashed line */
            padding-top: 15px;
        }
        .new-drugs-notification ul li {
            padding: 10px 0;
            border-bottom: 1px dotted #e0f2f7; /* Lighter dotted line */
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; /* Allow wrapping for long drug names */
        }
        .new-drugs-notification ul li:last-child {
            border-bottom: none;
        }
        .new-drugs-notification .drug-info strong {
            color: #007bff; /* Primary blue for drug name */
            font-size: 1em;
        }
        .new-drugs-notification .drug-info span {
            font-size: 0.9em;
            color: #555;
            margin-left: 8px;
        }
        .new-drugs-notification .stock-info {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 5px; /* Space below drug name on small screens */
            width: 100%; /* Take full width on small screens */
            text-align: right;
        }
        @media (min-width: 769px) { /* Adjust for larger screens */
            .new-drugs-notification .stock-info {
                width: auto;
                margin-top: 0;
            }
        }
        .new-drugs-notification .notification-action-btn {
            display: block;
            width: fit-content;
            margin: 20px auto 0 auto; /* Centered button */
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .new-drugs-notification .notification-action-btn:hover {
            background-color: #0056b3;
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
            .welcome-section h2 {
                font-size: 1.8em;
            }
            .dashboard-grid {
                grid-template-columns: 1fr; /* Stack cards vertically */
            }
            .dashboard-card {
                padding: 20px;
            }
            .new-drugs-notification {
                padding: 20px;
            }
            .new-drugs-notification ul li {
                flex-direction: column;
                align-items: flex-start;
            }
            .new-drugs-notification .stock-info {
                text-align: left; /* Align to left when stacked */
                margin-top: 5px;
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Customer Dashboard</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_profile.php" class="primary-btn">Profile</a>
            <a href="online_drugs.php" class="success-btn">Order Drugs</a>
            <a href="view_cart.php" class="primary-btn">View Cart</a>
            <a href="customer_orders.php" class="primary-btn">My Orders</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>

    <main>
        <div class="welcome-section">
            <h2>Hello, <?php echo htmlspecialchars(isset($currentUser['first_name']) ? $currentUser['first_name'] : $currentUser['full_name']); ?>!</h2>
            <p>Welcome to your Woloma Pharmacy Customer Dashboard.</p>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if (!empty($newlyAddedDrugs)): ?>
            <div class="new-drugs-notification">
                <h3><i class="fas fa-bell"></i> New Drugs & Stock Alerts!</h3>
                <p>Check out these recent additions and restocks to our catalog:</p>
                <ul>
                    <?php foreach ($newlyAddedDrugs as $drug): ?>
                        <li>
                            <div class="drug-info">
                                <strong><?php echo htmlspecialchars($drug['drug_name']); ?></strong>
                                <span>(ETB <?php echo number_format($drug['average_price'], 2); ?>)</span>
                            </div>
                            <div class="stock-info">
                                Available: <?php echo htmlspecialchars($drug['total_available_quantity']); ?> | Latest Stock: <?php echo date('M d, Y', strtotime($drug['latest_stock_date'])); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="online_drugs.php" class="notification-action-btn">Browse All Drugs</a>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <div class="dashboard-card profile">
                <h3>Manage Profile</h3>
                <p>View and update your personal information.</p>
                <a href="customer_profile.php">Go to Profile</a>
            </div>

            <div class="dashboard-card drugs">
                <h3>Order Drugs</h3>
                <p>Browse our available drugs and place new orders.</p>
                <a href="online_drugs.php">Start Shopping</a>
            </div>

            <div class="dashboard-card cart">
                <h3>My Cart</h3>
                <p>Review items in your shopping cart before checkout.</p>
                <a href="view_cart.php">View Cart</a>
            </div>

            <div class="dashboard-card orders">
                <h3>My Orders</h3>
                <p>Track the status of your past and current orders.</p>
                <a href="customer_orders.php">View Orders</a>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
