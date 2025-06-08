<?php
// pharmacy_management_system/generate_receipt.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Only allow logged-in staff roles to view receipts
// Adjust roles array as needed (e.g., if you have a 'Cashier' role)
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], array('Admin', 'Pharmacist', 'Cashier'))) { // PHP 6.9 compatible array()
    header('Location: staff_login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';
$sale = null;
$saleItems = array(); // PHP 6.9 compatible array()

// Get sale_id from URL
$saleId = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;

if ($saleId === 0) {
    $feedbackMessage = "Error: No sale ID provided for receipt generation.";
    $feedbackStatus = 'error';
    // Consider redirecting to dashboard or sales page if no ID
    // header('Location: staff_dashboard.php'); exit();
} else {
    try {
        // Fetch Sale Header Data, including customer name
        $stmt = $pdo->prepare("
            SELECT
                s.sale_id,
                s.sale_date,
                s.total_amount,
                s.discount_amount, -- Added for display
                s.amount_paid,     -- Added for display
                s.payment_status,  -- Added for display
                s.notes,           -- Added for display
                u.full_name AS staff_name,
                c.first_name,
                c.last_name
            FROM
                Sales s
            JOIN
                Users u ON s.user_id = u.user_id
            LEFT JOIN
                Customers c ON s.customer_id = c.customer_id -- Use LEFT JOIN for optional customer
            WHERE
                s.sale_id = ?
        ");
        $stmt->execute(array($saleId)); // PHP 6.9 compatible array()
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            $feedbackMessage = "Error: Sale with ID " . htmlspecialchars($saleId) . " not found.";
            $feedbackStatus = 'error';
        } else {
            // Fetch Sale Items
            $stmtItems = $pdo->prepare("
                SELECT
                    si.quantity_sold,
                    si.price_per_unit_at_sale,
                    d.drug_name
                FROM
                    SaleItems si
                JOIN
                    Drugs d ON si.drug_id = d.drug_id
                WHERE
                    si.sale_id = ?
                ORDER BY
                    d.drug_name ASC
            ");
            $stmtItems->execute(array($saleId)); // PHP 6.9 compatible array()
            $saleItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $feedbackMessage = "Database Error: " . htmlspecialchars($e->getMessage());
        $feedbackStatus = 'error';
    }
}

// Display feedback message from session if redirected (though less common for receipt page)
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
    <title>Sales Receipt #<?php echo htmlspecialchars($saleId); ?></title>
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
            gap: 10px; /* Consistent spacing */
        }
        .auth-status span {
            font-size: 0.9em;
            color: #e0e0e0;
            flex-grow: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .auth-status a,
        .auth-status button,
        .auth-status form {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem; /* Rounded corners */
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9em;
            transition: background-color 0.2s ease, transform 0.1s ease;
            flex-shrink: 0;
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

        /* Main Content Area - Consistent with other pages */
        main {
            padding: 25px;
            max-width: 1000px;
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners for main container */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
            min-height: calc(100vh - 100px - 80px); /* Adjust to make main content fill space */
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Push footer down */
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

        /* Receipt Container - Adapted for consistency while retaining 'receipt' feel */
        .receipt-container {
            max-width: 380px; /* A bit wider than 300px for better readability, but still receipt-like */
            width: 100%;
            background-color: #fff;
            padding: 20px;
            border: 1px solid #e0e0e0; /* Consistent border color */
            border-radius: 8px; /* Consistent rounding */
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); /* Lighter shadow */
            text-align: center;
            font-size: 0.9em;
            margin: 0 auto 30px auto; /* Center within main and add bottom margin */
        }

        .pharmacy-header {
            margin-bottom: 20px;
        }
        .pharmacy-header h2 {
            margin: 0;
            font-size: 1.5em;
            color: #205072; /* Consistent header color */
            font-weight: 700;
        }
        .pharmacy-header p {
            margin: 5px 0;
            font-size: 0.85em;
            color: #666;
        }

        .sale-details {
            text-align: left;
            border-top: 1px dashed #bbb;
            border-bottom: 1px dashed #bbb;
            padding: 10px 0;
            margin-bottom: 15px;
            font-size: 0.9em; /* Adjusted for receipt size */
        }
        .sale-details p {
            margin: 5px 0;
        }
        .sale-details strong {
            color: #444; /* Consistent bold color */
            font-weight: 600;
        }

        /* Item List - Adapted for consistency while retaining 'receipt' feel */
        .item-list {
            width: 100%;
            border-collapse: separate; /* Use separate for consistent rounding */
            border-spacing: 0;
            margin-bottom: 15px;
            font-size: 0.85em; /* Smaller for receipt feel */
        }
        .item-list th, .item-list td {
            padding: 8px 0; /* Adjusted padding */
            text-align: left;
            border-bottom: 1px dotted #e9ecef; /* Subtle dotted border */
        }
        .item-list th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .item-list th:nth-child(2),
        .item-list td:nth-child(2) {
            text-align: center; /* Quantity */
        }
        .item-list th:nth-child(3),
        .item-list td:nth-child(3),
        .item-list th:nth-child(4),
        .item-list td:nth-child(4) {
            text-align: right; /* Price and Subtotal */
        }
        .item-list tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }

        .total-section {
            border-top: 2px solid #333;
            padding-top: 10px;
            margin-top: 15px;
            font-size: 1.2em;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }

        .receipt-footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #bbb;
            font-size: 0.8em;
            color: #666;
        }

        /* Print Buttons - Consistent styling */
        .print-buttons {
            text-align: center;
            margin-top: 30px; /* Increased margin */
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px; /* Spacing between buttons */
        }
        .print-buttons button,
        .print-buttons a {
            padding: 12px 25px; /* Consistent button padding */
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1em;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-decoration: none; /* For anchor tags */
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .print-buttons button { /* Specific style for print button */
            background-color: #007bff; /* Primary blue */
            color: white;
        }
        .print-buttons button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .print-buttons a { /* Style for other links */
            background-color: #6c757d; /* Secondary grey */
            color: white;
        }
        .print-buttons a:hover {
            background-color: #5a6268;
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


        /* Hide buttons and header when printing */
        @media print {
            .print-buttons, header, footer { /* Hide footer too when printing */
                display: none;
            }
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
                font-family: 'Consolas', 'Courier New', monospace; /* Revert to monospace for print */
            }
            main { /* Ensure main content uses full width for print */
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border-radius: 0;
                min-height: auto; /* Reset min-height for printing */
            }
            .receipt-container {
                box-shadow: none;
                border: none;
                width: 100%; /* Use full width for print */
                max-width: none;
                margin: 0;
                padding: 10px; /* Add some padding for print */
            }
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
            .receipt-container {
                padding: 15px;
            }
            .print-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Sales Receipt View</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
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

        <?php if ($sale): ?>
            <div class="receipt-container">
                <div class="pharmacy-header">
                    <h2>Woloma Pharmacy</h2>
                    <p>Your Health, Our Priority</p>
                    <p>Address: Shashemene, Oromia, Ethiopia</p>
                    <p>Phone: +251 9XX XXX XXXX</p>
                    <p>Email: info@wolomapharmacy.com</p>
                </div>

                <div class="sale-details">
                    <p><strong>Sale ID:</strong> #<?php echo htmlspecialchars($sale['sale_id']); ?></p>
                    <p><strong>Date:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($sale['sale_date']))); ?></p>
                    <p><strong>Served by:</strong> <?php echo htmlspecialchars($sale['staff_name']); ?></p>
                    <p><strong>Buyer:</strong>
                        <?php echo (isset($sale['first_name']) && isset($sale['last_name'])) ?
                            htmlspecialchars($sale['first_name'] . ' ' . $sale['last_name']) :
                            'Walk-in Customer';
                        ?>
                    </p>
                    <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($sale['payment_status']); ?></p>
                    <?php if (!empty($sale['notes'])): ?>
                        <p><strong>Notes:</strong> <?php echo htmlspecialchars($sale['notes']); ?></p>
                    <?php endif; ?>
                </div>

                <table class="item-list">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $subtotalCounter = 0;
                        foreach ($saleItems as $item):
                            $itemSubtotal = $item['quantity_sold'] * $item['price_per_unit_at_sale'];
                            $subtotalCounter += $itemSubtotal;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['quantity_sold']); ?></td>
                                <td><?php echo number_format($item['price_per_unit_at_sale'], 2); ?></td>
                                <td><?php echo number_format($itemSubtotal, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="total-section">
                    <span>Subtotal:</span>
                    <span>ETB <?php echo number_format($subtotalCounter, 2); ?></span>
                </div>
                <?php if ($sale['discount_amount'] > 0): ?>
                    <div class="total-section" style="font-size: 1em; font-weight: normal; border-top: none; padding-top: 0;">
                        <span>Discount:</span>
                        <span>- ETB <?php echo number_format($sale['discount_amount'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="total-section">
                    <span>TOTAL PAID:</span>
                    <span>ETB <?php echo number_format($sale['total_amount'] - $sale['discount_amount'], 2); ?></span>
                </div>
                <div class="total-section" style="font-size: 1.1em; font-weight: bold; border-top: none; padding-top: 0;">
                    <span>Change Due:</span>
                    <span>ETB <?php echo number_format($sale['amount_paid'] - ($sale['total_amount'] - $sale['discount_amount']), 2); ?></span>
                </div>


                <?php if (abs($sale['total_amount'] - $subtotalCounter) > 0.01): ?>
                    <p style="color: red; font-size: 0.8em; margin-top: 10px;">*Calculated item total (<?php echo number_format($subtotalCounter, 2); ?>) differs from stored sale total (<?php echo number_format($sale['total_amount'], 2); ?>). Please check sale processing logic.</p>
                <?php endif; ?>

                <div class="receipt-footer">
                    <p>Thank you for your purchase!</p>
                    <p>Visit us again soon.</p>
                </div>
            </div>

            <div class="print-buttons">
                <button onclick="window.print()">Print Receipt</button>
                <a href="staff_dashboard.php">Back to Dashboard</a>
                <a href="new_in_person_sale.php">New Sale</a>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
