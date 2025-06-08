<?php
// pharmacy_management_system/view_cart.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in as a customer using the consistent function
redirectIfNotRole(array('Customer'), 'login.php');

$currentUser = getCurrentUser(); // Get current user info

$pdo = getDbConnection();
$feedbackMessage = ''; // Will be populated from session feedback
$feedbackStatus = '';  // Will be populated from session feedback

// Initialize cart if it doesn't exist (should already be set from online_drugs.php)
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array(); // Use array() for PHP 6.9 consistency
}

// --- Handle Cart Updates (Update Quantity, Remove Item) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Use isset() for all $_POST variables for PHP 6.9 compatibility
    $drug_id = isset($_POST['drug_id']) ? (int)$_POST['drug_id'] : 0;

    if ($drug_id > 0) {
        if ($_POST['action'] === 'update_quantity') {
            $new_quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

            if ($new_quantity > 0) {
                // Re-check stock before updating quantity
                $availableQty = 0;
                $stmtQty = $pdo->prepare("SELECT SUM(quantity) AS total_qty FROM Stock WHERE drug_id = ? AND expiry_date >= CURDATE()");
                $stmtQty->execute(array($drug_id)); // Use array() for PHP 6.9 consistency
                $availableQty = $stmtQty->fetchColumn();

                if ($new_quantity > $availableQty) {
                    $_SESSION['feedback_message'] = 'Warning: Not enough stock. Only ' . $availableQty . ' available for ' . htmlspecialchars($_SESSION['cart'][$drug_id]['drug_name']) . '. Your quantity was adjusted.';
                    $_SESSION['feedback_status'] = 'warning';
                    $_SESSION['cart'][$drug_id]['quantity'] = $availableQty; // Cap at available
                } else {
                    $_SESSION['cart'][$drug_id]['quantity'] = $new_quantity;
                    $_SESSION['feedback_message'] = 'Cart updated successfully.';
                    $_SESSION['feedback_status'] = 'success';
                }
            } else {
                // If new quantity is 0 or less, remove item
                unset($_SESSION['cart'][$drug_id]);
                $_SESSION['feedback_message'] = 'Item removed from cart.';
                $_SESSION['feedback_status'] = 'success';
            }
        } elseif ($_POST['action'] === 'remove_item') {
            unset($_SESSION['cart'][$drug_id]);
            $_SESSION['feedback_message'] = 'Item removed from cart.';
            $_SESSION['feedback_status'] = 'success';
        }
    } else {
        $_SESSION['feedback_message'] = 'Error: Invalid drug ID.';
        $_SESSION['feedback_status'] = 'error';
    }
    // Redirect to prevent form resubmission on refresh, using session for message
    header('Location: view_cart.php');
    exit();
}

// Recalculate cart total and item subtotals for display
$cartTotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

// Display feedback message from session if present (consistent approach)
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
    <title>Your Shopping Cart - Woloma Pharmacy</title>
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
        .auth-status a.back-to-shop-btn {
            background-color: #007bff; /* Primary blue for shop button */
        }
        .auth-status a.back-to-shop-btn:hover {
            background-color: #0056b3;
        }


        /* Main content area */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 900px; /* Adjusted max-width for forms */
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
        .message.warning {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffc107;
        }

        main h2 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em; /* Slightly larger heading */
        }

        /* Cart Table Styling - Consistent with reports.php tables */
        .cart-table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 30px;
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        .cart-table th, .cart-table td {
            border: none; /* Remove individual cell borders */
            padding: 12px;
            text-align: left;
            vertical-align: middle; /* Align content vertically */
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        .cart-table th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        .cart-table tbody tr:nth-child(even) { /* Changed to even for better contrast */
            background-color: #fcfcfc;
        }
        .cart-table tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }
        .cart-table thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        .cart-table thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        .cart-table tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        .cart-table tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }

        .cart-table .quantity-input {
            width: 70px; /* Slightly wider */
            padding: 8px; /* Adjusted padding */
            border: 1px solid #c0c0c0;
            border-radius: 6px; /* Consistent rounding */
            text-align: center;
            box-sizing: border-box;
            font-size: 0.9em;
        }
        .cart-table .update-btn, .cart-table .remove-btn {
            padding: 8px 15px; /* Adjusted padding */
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            font-size: 0.9em;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-left: 5px; /* Space between input and button */
        }
        .cart-table .update-btn { background-color: #007bff; color: white; }
        .cart-table .update-btn:hover { background-color: #0056b3; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15); }
        .cart-table .remove-btn { background-color: #dc3545; color: white; }
        .cart-table .remove-btn:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15); }

        .cart-summary {
            text-align: right;
            margin-top: 30px; /* Increased margin */
            font-size: 1.3em; /* Slightly larger */
            font-weight: bold;
            color: #205072; /* Darker blue */
            padding: 15px 20px;
            background-color: #e9f5ff;
            border: 1px solid #cceeff;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .cart-summary span {
            color: #28a745; /* Green for total amount */
            font-size: 1.2em;
        }

        .cart-actions {
            text-align: right;
            margin-top: 30px; /* Increased margin */
            padding: 10px 0;
        }
        .cart-actions a {
            display: inline-block;
            background-color: #28a745; /* Green for checkout button */
            color: white;
            padding: 12px 25px;
            border-radius: 6px; /* Consistent rounded corners */
            text-decoration: none;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .cart-actions a:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .empty-cart-message {
            text-align: center;
            font-size: 1.2em;
            color: #6c757d;
            margin-top: 50px;
            padding: 20px;
            background-color: #fdfdfd;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .empty-cart-message a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s ease;
        }
        .empty-cart-message a:hover {
            text-decoration: underline;
            color: #0056b3;
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
            .cart-table {
                display: block; /* Make table responsive to stack rows */
                overflow-x: auto; /* Allow horizontal scroll if necessary, though stacking is preferred */
                white-space: nowrap; /* Prevent content wrapping if horizontal scroll is main intent */
            }
            .cart-table thead {
                display: none; /* Hide header on mobile if stacking rows is desired */
            }
            .cart-table tbody, .cart-table tr, .cart-table th, .cart-table td {
                display: block; /* Make cells behave as block elements for stacking */
            }
            .cart-table tbody tr {
                margin-bottom: 15px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
            }
            .cart-table td {
                text-align: right;
                border-bottom: 1px dashed #e9ecef;
                position: relative;
                padding-left: 50%; /* Space for pseudo-element label */
                white-space: normal; /* Allow text to wrap within the cell */
            }
            .cart-table td:last-child {
                border-bottom: none;
            }
            .cart-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: calc(50% - 20px);
                text-align: left;
                font-weight: bold;
                color: #555;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .cart-table td:nth-child(3) { /* Quantity cell specific */
                padding-left: 10px; /* Reset padding for inline form elements */
                text-align: left;
            }
            .cart-table td:nth-child(3)::before {
                display: none; /* Hide label for quantity as input provides context */
            }
            .cart-table form {
                display: flex !important; /* Override inline-flex for full width */
                flex-wrap: wrap; /* Allow wrapping of elements inside form */
                justify-content: flex-start;
                gap: 5px; /* Adjust gap if needed */
            }
            .cart-table .quantity-input {
                flex-grow: 1; /* Allow input to grow */
                width: auto; /* Reset width to allow flex-grow */
                min-width: 60px; /* Ensure a minimum width */
            }
            .cart-table .update-btn, .cart-table .remove-btn {
                flex-shrink: 0; /* Prevent buttons from shrinking */
                width: auto; /* Reset width */
                margin-left: 0; /* Remove margin-left when stacking */
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
            main h2 {
                font-size: 1.4em;
            }
            .message {
                font-size: 0.85em;
            }
            .cart-table td {
                 padding-left: 10px; /* Reset padding for smaller screens if stacking */
            }
            .cart-table td::before {
                position: static; /* Hide pseudo-element labels if simpler stacking is preferred */
                display: block;
                font-weight: normal;
                color: inherit;
                margin-bottom: 5px;
                text-overflow: clip;
                overflow: visible;
                white-space: normal;
                content: attr(data-label) ": "; /* Add colon for clarity */
            }
            .cart-summary {
                font-size: 1.1em;
                padding: 10px 15px;
            }
            .cart-summary span {
                font-size: 1em;
            }
            .cart-actions a {
                padding: 10px 20px;
                font-size: 1em;
                width: 100%; /* Full width button on mobile */
                box-sizing: border-box;
            }
            .empty-cart-message {
                font-size: 1em;
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Your Shopping Cart</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="online_drugs.php" class="back-to-shop-btn">Continue Shopping</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <h2>Items in Your Cart</h2>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if (empty($_SESSION['cart'])): ?>
            <p class="empty-cart-message">Your cart is empty. <a href="online_drugs.php">Start shopping now!</a></p>
        <?php else: ?>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Drug Name</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($_SESSION['cart'] as $drug_id => $item): ?>
                        <tr>
                            <td data-label="Drug Name"><?php echo htmlspecialchars($item['drug_name']); ?></td>
                            <td data-label="Price">ETB <?php echo number_format($item['price'], 2); ?></td>
                            <td data-label="Quantity">
                                <form action="view_cart.php" method="POST" style="display:inline-flex; gap: 5px;">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug_id); ?>">
                                    <input type="number" name="quantity" value="<?php echo htmlspecialchars($item['quantity']); ?>" min="1" class="quantity-input">
                                    <button type="submit" class="update-btn">Update</button>
                                </form>
                            </td>
                            <td data-label="Subtotal">ETB <?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                            <td data-label="Action">
                                <form action="view_cart.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug_id); ?>">
                                    <button type="submit" class="remove-btn">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="cart-summary">
                <p>Cart Total: <span>ETB <?php echo number_format($cartTotal, 2); ?></span></p>
            </div>

            <div class="cart-actions">
                <a href="checkout.php">Proceed to Checkout</a>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
