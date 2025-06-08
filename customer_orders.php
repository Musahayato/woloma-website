<?php
// pharmacy_management_system/my_orders.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in as a customer
$currentUser = getCurrentUser();
if (!isset($currentUser) || $currentUser['role'] !== 'Customer') {
    header('Location: login.php');
    exit();
}

$pdo = getDbConnection();
$customer_id = $_SESSION['customer_id'];
$orders = [];
$feedbackMessage = '';
$feedbackStatus = ''; // Initialize feedbackStatus for message styling

try {
    // Fetch all sales for the logged-in customer
    $stmt = $pdo->prepare("
        SELECT
            sale_id,
            sale_date,
            total_amount,
            order_status,
            payment_status,
            order_type,
            delivery_address,
            notes,
            discount_amount,
            amount_paid
        FROM
            Sales
        WHERE
            customer_id = ?
        ORDER BY
            sale_date DESC, sale_id DESC
    ");
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $feedbackMessage = 'Error loading your orders: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
}

// Get message from URL if redirected (e.g., from order placement)
if (isset($_GET['message']) && isset($_GET['status'])) {
    $feedbackMessage = htmlspecialchars($_GET['message']);
    $feedbackStatus = htmlspecialchars($_GET['status']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Woloma Pharmacy</title>
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
        .auth-status a.back-to-dash-btn { background-color: #007bff; } /* Primary blue for dashboard link */
        .auth-status a.back-to-dash-btn:hover { background-color: #0056b3; }


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

        /* Page specific header */
        main h2 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.8em; /* Slightly larger heading */
            text-align: center;
        }

        /* Orders Table Styles - Consistent with other tables */
        .orders-table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 25px; /* Increased margin */
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        .orders-table th, .orders-table td {
            border: none; /* Remove individual cell borders */
            padding: 12px; /* Increased padding */
            text-align: left;
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        .orders-table th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        .orders-table tbody tr:nth-child(even) { /* Changed to even for better contrast */
            background-color: #fcfcfc;
        }
        .orders-table tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }
        .orders-table thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        .orders-table thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        .orders-table tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        .orders-table tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }

        .no-orders-message {
            text-align: center;
            font-size: 1.1em; /* Slightly adjusted font size */
            color: #6c757d;
            margin-top: 40px; /* Increased margin */
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .no-orders-message a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s ease;
        }
        .no-orders-message a:hover {
            text-decoration: underline;
            color: #0056b3;
        }

        /* Status Badges - Retained existing styles */
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
            text-align: center;
            min-width: 90px;
        }
        .status-pending { background-color: #ffc107; color: #343a40; } /* yellow */
        .status-processing { background-color: #007bff; } /* blue */
        .status-ready { background-color: #17a2b8; } /* teal */
        .status-completed { background-color: #28a745; } /* green */
        .status-cancelled { background-color: #dc3545; } /* red */
        .status-default { background-color: #6c757d; } /* grey */

        /* View Details Link */
        .orders-table td a {
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease, text-decoration 0.2s ease;
        }
        .orders-table td a:hover {
            color: #0056b3;
            text-decoration: underline;
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
            main h2 {
                font-size: 1.5em;
            }
            .orders-table th, .orders-table td {
                padding: 8px; /* Reduce table padding on small screens */
                font-size: 0.8em;
            }
            /* Make table horizontally scrollable if content overflows */
            .orders-table-container { /* Add a div around the table if it causes overflow */
                overflow-x: auto;
                -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>My Orders</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_dashboard.php" class="back-to-dash-btn">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <h2>Your Order History</h2>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <p class="no-orders-message">You haven't placed any orders yet. <a href="online_drugs.php">Start shopping!</a></p>
        <?php else: ?>
            <div class="orders-table-container"> <!-- Added container for horizontal scroll -->
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Order Date</th>
                            <th>Total Amount</th>
                            <th>Order Type</th>
                            <th>Order Status</th>
                            <th>Payment Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['sale_id']); ?></td>
                                <td><?php echo htmlspecialchars($order['sale_date']); ?></td>
                                <td>ETB <?php echo number_format($order['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($order['order_type'])); ?></td>
                                <td>
                                    <?php
                                        $statusClass = 'status-default';
                                        switch ($order['order_status']) {
                                            case 'Pending Payment Verification': $statusClass = 'status-pending'; break;
                                            case 'Processing': $statusClass = 'status-processing'; break;
                                            case 'Ready for Pickup':
                                            case 'Ready for Delivery': $statusClass = 'status-ready'; break;
                                            case 'Completed': $statusClass = 'status-completed'; break;
                                            case 'Cancelled': $statusClass = 'status-cancelled'; break;
                                        }
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($order['order_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(ucfirst($order['payment_status'])); ?></td>
                                <td>
                                    <a href="view_order_details.php?sale_id=<?php echo htmlspecialchars($order['sale_id']); ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
