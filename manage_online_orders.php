<?php
// pharmacy_management_system/manage_online_orders.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Restrict access: Only Pharmacists and Admins can access this page
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], array('Pharmacist', 'Admin'))) { // PHP 6.9 compatible array()
    header('Location: staff_login.php');
    exit();
}

$pdo = getDbConnection();
$orders = array(); // PHP 6.9 compatible array()
$feedbackMessage = '';
$feedbackStatus = ''; // Initialize for consistent feedback styling

try {
    // Fetch all online orders, prioritizing those needing action (e.g., payment verification)
    // Join with Customers table to display customer name instead of just ID
    $stmt = $pdo->prepare("
        SELECT
            s.sale_id,
            s.sale_date,
            s.total_amount,
            s.order_status,
            s.payment_status,
            s.order_type,
            s.delivery_address,
            s.notes,
            -- CONCATENATE first_name and last_name for customer_name
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.phone_number AS customer_phone,
            c.email AS customer_email
        FROM
            Sales s
        JOIN
            Customers c ON s.customer_id = c.customer_id
        WHERE
            s.order_type IN ('pickup', 'delivery')
        ORDER BY
            CASE
                WHEN s.order_status = 'Pending Payment Verification' THEN 1
                WHEN s.order_status = 'Processing' THEN 2
                ELSE 3
            END,
            s.sale_date DESC, s.sale_id DESC
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $feedbackMessage = 'Error loading orders: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
    error_log("Error fetching orders for staff: " . $e->getMessage());
}

// Display feedback message from session if redirected (e.g., from an action page)
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
    <title>Manage Online Orders - Woloma Pharmacy Staff</title>
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
        .auth-status a.back-to-dash-btn { /* Specific style for dashboard button */
            background-color: #007bff; /* Primary blue for dashboard button */
        }
        .auth-status a.back-to-dash-btn:hover {
            background-color: #0056b3;
        }


        /* Main content area - Consistent */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1200px; /* Increased max-width for tables */
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

        /* Orders Table Styling - Consistent with manage_drugs_stock.php tables */
        .orders-table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 20px;
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        .orders-table th, .orders-table td {
            border: none; /* Remove individual cell borders */
            padding: 12px;
            text-align: left;
            vertical-align: middle; /* Center content vertically */
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
        .orders-table tbody tr:hover {
            background-color: #e2e6ea;
        }

        .no-orders-message {
            text-align: center;
            font-size: 1.2em;
            color: #6c757d;
            margin-top: 50px;
            padding: 20px;
            border: 1px dashed #ccc;
            border-radius: 8px;
            background-color: #f9f9f9;
        }

        /* Status Badges - Consistent */
        .status-badge {
            display: inline-block;
            padding: 6px 12px; /* Slightly more padding */
            border-radius: 20px; /* More rounded */
            font-size: 0.8em;
            font-weight: bold;
            color: white;
            text-align: center;
            min-width: 100px; /* Slightly wider */
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-pending { background-color: #ffc107; color: #343a40; } /* yellow */
        .status-processing { background-color: #007bff; } /* blue */
        .status-ready { background-color: #17a2b8; } /* teal */
        .status-completed { background-color: #28a745; } /* green */
        .status-cancelled { background-color: #dc3545; } /* red */
        .status-default { background-color: #6c757d; } /* grey */

        /* Action Button - Consistent */
        .action-btn {
            background-color: #007bff; /* Primary blue */
            color: white;
            border: none;
            padding: 8px 15px; /* Adjusted padding */
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9em;
            font-weight: bold; /* Make bold */
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: inline-flex; /* For better alignment */
            align-items: center;
            justify-content: center;
        }
        .action-btn:hover {
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
        @media (max-width: 992px) { /* Tablet and smaller desktop */
            .orders-table {
                display: block; /* Make table scrollable */
                overflow-x: auto; /* Enable horizontal scroll */
                white-space: nowrap; /* Prevent content wrapping */
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
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Manage Online Orders</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php" class="back-to-dash-btn">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <h2>Online Customer Orders</h2>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <p class="no-orders-message">No online orders found.</p>
        <?php else: ?>
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Customer Name</th>
                        <th>Phone/Email</th>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Order Status</th>
                        <th>Payment Method</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['sale_id']); ?></td>
                            <td><?php echo htmlspecialchars($order['sale_date']); ?></td>
                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($order['customer_phone']); ?><br>
                                <?php echo htmlspecialchars($order['customer_email']); ?>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($order['order_type'])); ?></td>
                            <td>ETB <?php echo number_format($order['total_amount'], 2); ?></td>
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
                                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars($order['order_status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($order['payment_status'])); ?></td>
                            <td>
                                <a href="process_online_order.php?sale_id=<?php echo htmlspecialchars($order['sale_id']); ?>" class="action-btn">Process</a>
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
