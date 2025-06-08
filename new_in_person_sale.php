<?php
// pharmacy_management_system/new_in_person_sale.php
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
$feedbackMessage = '';
$feedbackStatus = '';

// Initialize temporary cart for this in-person sale in session
// This cart is separate from the customer's online cart
if (!isset($_SESSION['in_person_cart'])) {
    $_SESSION['in_person_cart'] = array(); // PHP 6.9 compatible array()
}

// --- Handle Form Submissions (POST requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        $pdo->beginTransaction(); // Start transaction for atomicity

        if ($action === 'add_drug_to_pos_cart') {
            $drug_id = (int)(isset($_POST['drug_id']) ? $_POST['drug_id'] : 0);
            $quantity_to_add = (int)(isset($_POST['quantity']) ? $_POST['quantity'] : 0);

            if ($drug_id <= 0 || $quantity_to_add <= 0) {
                throw new Exception("Invalid drug or quantity provided.");
            }

            // Fetch drug details and its *current price* from available stock
            // Using SUM(s.quantity) to get total available stock for the drug
            $stmt = $pdo->prepare("
                SELECT
                    d.drug_id,
                    d.drug_name,
                    AVG(s.selling_price_per_unit) AS current_price,
                    SUM(s.quantity) AS available_quantity -- This is the stock quantity
                FROM
                    Drugs d
                JOIN
                    Stock s ON d.drug_id = s.drug_id
                WHERE
                    d.drug_id = ? AND s.expiry_date >= CURDATE() -- Ensures only non-expired stock is considered
                GROUP BY
                    d.drug_id, d.drug_name
                HAVING
                    available_quantity > 0
            ");
            $stmt->execute(array($drug_id)); // PHP 6.9 compatible array()
            $drug = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$drug) {
                throw new Exception("Drug not found or out of stock.");
            }

            $availableQty = $drug['available_quantity'];
            $currentPrice = $drug['current_price'];

            $currentCartQty = isset($_SESSION['in_person_cart'][$drug_id]['quantity']) ? $_SESSION['in_person_cart'][$drug_id]['quantity'] : 0;

            if (($currentCartQty + $quantity_to_add) > $availableQty) {
                throw new Exception("Not enough stock for " . htmlspecialchars($drug['drug_name']) . ". Only " . $availableQty . " available.");
            }

            if (isset($_SESSION['in_person_cart'][$drug_id])) {
                $_SESSION['in_person_cart'][$drug_id]['quantity'] += $quantity_to_add;
                $_SESSION['in_person_cart'][$drug_id]['price'] = $currentPrice; // Update price in case it changed
            } else {
                $_SESSION['in_person_cart'][$drug_id] = array( // PHP 6.9 compatible array()
                    'drug_id' => $drug['drug_id'],
                    'drug_name' => $drug['drug_name'],
                    'price' => $currentPrice,
                    'quantity' => $quantity_to_add
                );
            }
            $feedbackMessage = htmlspecialchars($quantity_to_add) . ' of ' . htmlspecialchars($drug['drug_name']) . ' added to cart.';
            $feedbackStatus = 'success';

        } elseif ($action === 'update_pos_cart_quantity') {
            $drug_id = (int)(isset($_POST['drug_id']) ? $_POST['drug_id'] : 0);
            $new_quantity_in_cart = (int)(isset($_POST['quantity']) ? $_POST['quantity'] : 0);

            if ($drug_id <= 0) {
                throw new Exception("Invalid drug ID.");
            }

            if ($new_quantity_in_cart <= 0) {
                unset($_SESSION['in_person_cart'][$drug_id]);
                $feedbackMessage = "Item removed from cart.";
                $feedbackStatus = 'success';
            } else {
                // Re-check stock before updating quantity
                $stmt = $pdo->prepare("SELECT SUM(quantity) AS available_quantity FROM Stock WHERE drug_id = ? AND expiry_date >= CURDATE()"); // Ensures only non-expired stock is checked
                $stmt->execute(array($drug_id)); // PHP 6.9 compatible array()
                $availableQty = $stmt->fetchColumn();

                if ($new_quantity_in_cart > $availableQty) {
                    throw new Exception("Not enough stock. Only " . $availableQty . " available for this item.");
                }

                $_SESSION['in_person_cart'][$drug_id]['quantity'] = $new_quantity_in_cart;
                $feedbackMessage = "Cart updated successfully.";
                $feedbackStatus = 'success';
            }
        } elseif ($action === 'remove_pos_cart_item') {
            $drug_id = (int)(isset($_POST['drug_id']) ? $_POST['drug_id'] : 0);
            if ($drug_id > 0) {
                unset($_SESSION['in_person_cart'][$drug_id]);
                $feedbackMessage = "Item removed from cart.";
                $feedbackStatus = 'success';
            } else {
                throw new Exception("Invalid drug ID for removal.");
            }
        } elseif ($action === 'finalize_sale') {
            if (empty($_SESSION['in_person_cart'])) {
                throw new Exception("Cannot finalize an empty cart.");
            }

            $customer_id = (int)(isset($_POST['customer_id']) ? $_POST['customer_id'] : 0);
            $payment_method = trim(isset($_POST['payment_method']) ? $_POST['payment_method'] : '');
            $discount_amount = (float)(isset($_POST['discount_amount']) ? $_POST['discount_amount'] : 0.00);
            $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');

            if (empty($payment_method)) {
                throw new Exception("Payment method is required.");
            }

            // Calculate total amount from cart
            $total_amount = 0;
            foreach ($_SESSION['in_person_cart'] as $item) {
                $total_amount += $item['price'] * $item['quantity'];
            }

            // Apply discount
            if ($discount_amount < 0 || $discount_amount > $total_amount) {
                throw new Exception("Invalid discount amount.");
            }
            $final_amount = $total_amount - $discount_amount;
            $amount_paid = $final_amount; // For in-person, assume amount paid is final amount

            // Deduct stock immediately for in-person sales
            foreach ($_SESSION['in_person_cart'] as $item) {
                $drug_id = $item['drug_id'];
                $quantity_ordered_from_cart = $item['quantity'];

                // Check current stock (again, for safety)
                // Using SUM(quantity) to get total available stock for the drug
                $stmtStock = $pdo->prepare("SELECT SUM(quantity) AS current_stock_available FROM Stock WHERE drug_id = ? AND expiry_date >= CURDATE()"); // Ensures only non-expired stock is deducted
                $stmtStock->execute(array($drug_id)); // PHP 6.9 compatible array()
                $current_stock_available = $stmtStock->fetchColumn();

                if ($current_stock_available === false || $current_stock_available < $quantity_ordered_from_cart) {
                    throw new Exception("Insufficient stock for drug ID " . $drug_id . " (" . htmlspecialchars($item['drug_name']) . "). Available: " . (int)$current_stock_available . ", Ordered: " . $quantity_ordered_from_cart . ". Sale cannot be completed.");
                }

                // Deduct stock from the Stock table
                // This logic might need refinement if you manage stock by batch/expiry date
                // For simplicity, we'll just deduct from the overall quantity for that drug_id
                $stmtUpdateStock = $pdo->prepare("UPDATE Stock SET quantity = quantity - ? WHERE drug_id = ?"); // Changed 'quantity_on_hand' to 'quantity'
                $stmtUpdateStock->execute(array($quantity_ordered_from_cart, $drug_id)); // PHP 6.9 compatible array()
            }

            // Insert into Sales table
            $stmtSales = $pdo->prepare("
                INSERT INTO Sales (
                    customer_id,
                    sale_date,
                    total_amount,
                    payment_status,
                    user_id, -- Pharmacist/Admin ID who made the sale
                    notes,
                    discount_amount,
                    amount_paid,
                    proof_of_payment_path, -- NULL for in-person cash/mobile/bank
                    order_status,
                    order_type, -- 'in-person'
                    delivery_address, -- NULL for in-person
                    stock_deducted -- TRUE as stock is deducted immediately
                ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)");

            $stmtSales->execute(array( // PHP 6.9 compatible array()
                ($customer_id > 0) ? $customer_id : NULL,
                $total_amount,
                $payment_method, // This is the actual payment method (Cash, Mobile, Bank)
                $currentUser['user_id'], // The logged-in staff member's ID
                $notes,
                $discount_amount,
                $amount_paid,
                NULL, // No proof of payment path for in-person sales (unless custom process)
                'Completed', // In-person sales are usually completed immediately
                'in-person',
                NULL // No delivery address for in-person sales
            ));
            $sale_id = $pdo->lastInsertId();

            if (!$sale_id) {
                throw new Exception("Failed to create sale record.");
            }

            // Insert into SaleItems
            $stmtSaleItem = $pdo->prepare("INSERT INTO SaleItems (sale_id, drug_id, quantity_sold, price_per_unit_at_sale, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($_SESSION['in_person_cart'] as $item) {
                $subtotal = $item['price'] * $item['quantity'];
                $stmtSaleItem->execute(array( // PHP 6.9 compatible array()
                    $sale_id,
                    $item['drug_id'],
                    $item['quantity'],
                    $item['price'],
                    $subtotal
                ));
            }

            // (inside the finalize_sale block, after successfully committing the transaction)
            $pdo->commit();
            unset($_SESSION['in_person_cart']); // Clear cart after successful sale

            // NEW: Redirect to receipt generation page
            header('Location: generate_receipt.php?sale_id=' . $sale_id);
            exit();

        } else {
            throw new Exception("Invalid action.");
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $feedbackMessage = "Error: " . htmlspecialchars($e->getMessage());
        $feedbackStatus = 'error';
    }
}

// --- Fetch data for display (GET requests and after POST redirect) ---
// Fetch all drugs for selection in the POS
$availableDrugs = array(); // PHP 6.9 compatible array()
try {
    // Fetch drugs that are currently in stock and are not expired
    // Using SUM(s.quantity) to get total available stock for the drug
    $stmt = $pdo->prepare("
        SELECT
            d.drug_id,
            d.drug_name,
            AVG(s.selling_price_per_unit) AS average_price,
            SUM(s.quantity) AS available_quantity -- This is the stock quantity
        FROM
            Drugs d
        JOIN
            Stock s ON d.drug_id = s.drug_id
        WHERE
            s.expiry_date >= CURDATE() -- This ensures only non-expired drugs are listed in the dropdown
        GROUP BY
            d.drug_id, d.drug_name
        HAVING
            available_quantity > 0
        ORDER BY
            d.drug_name ASC
    ");
    $stmt->execute();
    $availableDrugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= 'Error loading available drugs: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error'; // Set status for consistency
}

// Fetch existing customers for selection
$customers = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->prepare("SELECT customer_id, CONCAT(first_name, ' ', last_name) AS customer_name FROM Customers ORDER BY customer_name ASC");
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= 'Error loading customers: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error'; // Set status for consistency
}

// Calculate current cart total
$currentCartTotal = 0;
foreach ($_SESSION['in_person_cart'] as $item) {
    $currentCartTotal += $item['price'] * $item['quantity'];
}

// Display feedback message from session if redirected
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
    <title>New In-Person Sale - Woloma Pharmacy POS</title>
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
            gap: 10px; /* Consistent gap for better spacing */
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
        /* Adjusted logout button styling for consistency */
        .auth-status button.logout-btn {
             background-color: #dc3545; /* Red color for logout button */
        }
        .auth-status button.logout-btn:hover {
            background-color: #c82333; /* Darker red on hover */
        }

        /* Main Content Area - Consistent with other pages */
        main {
            padding: 25px;
            max-width: 1200px;
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners for main container */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            display: flex;
            flex-wrap: wrap;
            gap: 25px; /* Increased gap between panels */
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
        .message.info {
            background-color: #e2f0ff;
            color: #0056b3;
            border-color: #007bff;
        }

        /* POS Panels */
        .pos-left-panel, .pos-right-panel {
            flex: 1;
            min-width: calc(50% - 12.5px); /* Adjusted for half of the 25px gap */
            padding: 25px; /* Increased padding */
            border: 1px solid #e0e0e0; /* Consistent border color */
            border-radius: 10px; /* Consistent rounding */
            background-color: #fdfdfd; /* Light background */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Lighter shadow */
            box-sizing: border-box; /* Include padding/border in width */
            display: flex; /* Make panels flex containers for internal content */
            flex-direction: column;
        }
        .pos-left-panel {
            min-width: calc(60% - 12.5px); /* Slightly wider for drug selection */
        }
        .pos-right-panel {
            min-width: calc(40% - 12.5px);
        }


        .pos-section h3 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.4em;
        }

        /* Drug Search & Add Form */
        .drug-search-form {
            display: flex;
            gap: 15px; /* Increased gap */
            margin-bottom: 25px; /* Increased margin */
            align-items: flex-end; /* Align items at the bottom */
        }
        .drug-search-form select {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            font-size: 0.9em;
            height: 40px; /* Standard height */
            box-sizing: border-box; /* Ensures consistent height */
        }
        .drug-search-form input[type="number"] {
            width: 80px;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            text-align: center;
            font-size: 0.9em;
            height: 40px; /* Standard height */
            box-sizing: border-box; /* Ensures consistent height */
        }
        .drug-search-form button {
            padding: 10px 18px; /* Adjusted padding */
            background-color: #28a745; /* Green for add button */
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            font-weight: bold;
            font-size: 0.95em;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            height: 40px; /* Standard height */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box; /* Ensures consistent height */
        }
        .drug-search-form button:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Current Sale Cart Table */
        .pos-cart-table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 25px; /* Increased margin */
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        .pos-cart-table th, .pos-cart-table td {
            border: none; /* Remove individual cell borders */
            padding: 12px; /* Increased padding */
            text-align: left;
            vertical-align: middle; /* Center content vertically */
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        .pos-cart-table th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        .pos-cart-table tbody tr:nth-child(even) { /* Changed to even for better contrast */
            background-color: #fcfcfc;
        }
        .pos-cart-table tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }
        .pos-cart-table thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        .pos-cart-table thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        .pos-cart-table tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        .pos-cart-table tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }

        .pos-cart-table input[type="number"] {
            width: 60px; /* Adjusted width */
            padding: 6px; /* Adjusted padding */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 4px; /* Consistent rounding */
            text-align: center;
            font-size: 0.85em;
        }
        .pos-cart-table .update-btn,
        .pos-cart-table .remove-btn {
            padding: 6px 10px; /* Adjusted padding */
            border: none;
            border-radius: 4px; /* Consistent rounding */
            cursor: pointer;
            font-size: 0.85em; /* Adjusted font size */
            transition: background-color 0.3s ease, transform 0.1s ease;
        }
        .pos-cart-table .update-btn {
            background-color: #007bff; /* Blue for update */
            color: white;
        }
        .pos-cart-table .update-btn:hover {
            background-color: #0056b3;
            transform: translateY(-1px);
        }
        .pos-cart-table .remove-btn {
            background-color: #dc3545; /* Red for remove */
            color: white;
        }
        .pos-cart-table .remove-btn:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }

        .cart-total-display {
            text-align: right;
            font-size: 1.8em; /* Larger total font size */
            font-weight: bold;
            margin-top: auto; /* Push to bottom of flex panel */
            padding-top: 20px; /* Space above total */
            border-top: 1px solid #e9ecef; /* Separator line */
            color: #205072; /* Darker blue for total */
        }
        .empty-cart-message {
            text-align: center;
            color: #888; /* Slightly darker grey */
            margin-top: 30px;
            font-style: italic;
        }

        /* Finalize Sale Form */
        .finalize-form label {
            display: block;
            margin-bottom: 8px; /* Increased margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .finalize-form select,
        .finalize-form input[type="number"],
        .finalize-form textarea {
            width: 100%; /* Full width */
            padding: 10px;
            margin-bottom: 18px; /* Increased margin */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box;
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .finalize-form textarea {
            height: auto;
            min-height: 80px;
        }
        .finalize-form button {
            width: 100%;
            padding: 12px;
            background-color: #28a745; /* Green for complete sale */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .finalize-form button:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .finalize-form button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
            opacity: 0.8;
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
        @media (max-width: 992px) { /* Adjust breakpoint for panels */
            .pos-left-panel, .pos-right-panel {
                flex: 1 1 100%; /* Stack panels vertically */
                min-width: unset;
            }
            .pos-cart-table {
                display: block; /* Make table scrollable */
                overflow-x: auto; /* Enable horizontal scroll */
                white-space: nowrap; /* Prevent content wrapping */
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
            .auth-status form { /* Ensure forms also stack */
                width: 100%; /* Full width on smaller screens */
            }
            .drug-search-form {
                flex-direction: column;
                align-items: stretch;
            }
            .drug-search-form input[type="number"] {
                width: 100%;
                text-align: left;
            }
            .drug-search-form button {
                width: 100%;
            }
            .pos-left-panel, .pos-right-panel {
                padding: 15px; /* Reduce padding on small screens */
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>New In-Person Sale</h1>
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

        <div class="pos-left-panel pos-section">
            <h3>Add Drugs to Sale</h3>
            <form action="new_in_person_sale.php" method="POST" class="drug-search-form">
                <input type="hidden" name="action" value="add_drug_to_pos_cart">
                <select name="drug_id" required>
                    <option value="">Select a Drug</option>
                    <?php foreach ($availableDrugs as $drug): ?>
                        <option value="<?php echo htmlspecialchars($drug['drug_id']); ?>"
                                data-price="<?php echo htmlspecialchars(number_format($drug['average_price'], 2)); ?>"
                                data-stock="<?php echo htmlspecialchars($drug['available_quantity']); ?>">
                            <?php echo htmlspecialchars($drug['drug_name']); ?> (ETB <?php echo number_format($drug['average_price'], 2); ?> | Stock: <?php echo htmlspecialchars($drug['available_quantity']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="quantity" value="1" min="1" required>
                <button type="submit">Add</button>
            </form>

            <div class="pos-section" style="flex-grow: 1; display: flex; flex-direction: column;">
                <h3>Current Sale Items</h3>
                <?php if (empty($_SESSION['in_person_cart'])): ?>
                    <p class="empty-cart-message">No items in the current sale. Add drugs using the form above.</p>
                <?php else: ?>
                    <table class="pos-cart-table">
                        <thead>
                            <tr>
                                <th>Drug</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['in_person_cart'] as $drug_id => $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['drug_name']); ?></td>
                                    <td>
                                        <form action="new_in_person_sale.php" method="POST" style="display:inline-flex; gap: 5px;">
                                            <input type="hidden" name="action" value="update_pos_cart_quantity">
                                            <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug_id); ?>">
                                            <input type="number" name="quantity" value="<?php echo htmlspecialchars($item['quantity']); ?>" min="0" class="quantity-input">
                                            <button type="submit" class="update-btn">Update</button>
                                        </form>
                                    </td>
                                    <td>ETB <?php echo number_format($item['price'], 2); ?></td>
                                    <td>ETB <?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                                    <td>
                                        <form action="new_in_person_sale.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_pos_cart_item">
                                            <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug_id); ?>">
                                            <button type="submit" class="remove-btn">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="cart-total-display">
                        Total: ETB <?php echo number_format($currentCartTotal, 2); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="pos-right-panel pos-section">
            <h3>Finalize Sale</h3>
            <form action="new_in_person_sale.php" method="POST" class="finalize-form">
                <input type="hidden" name="action" value="finalize_sale">

                <label for="customer_id">Select Customer (Optional):</label>
                <select name="customer_id" id="customer_id">
                    <option value="0">Walk-in Customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo htmlspecialchars($customer['customer_id']); ?>">
                            <?php echo htmlspecialchars($customer['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="payment_method">Payment Method:</label>
                <select name="payment_method" id="payment_method" required>
                    <option value="">Select Method</option>
                    <option value="Cash">Cash</option>
                    <option value="Mobile Transfer">Mobile Transfer</option>
                    <option value="Bank Deposit">Bank Deposit</option>
                    </select>

                <label for="discount_amount">Discount Amount (ETB):</label>
                <input type="number" id="discount_amount" name="discount_amount" value="0.00" min="0" step="0.01">

                <label for="notes">Notes (Optional):</label>
                <textarea id="notes" name="notes" rows="3"></textarea>

                <button type="submit" <?php echo empty($_SESSION['in_person_cart']) ? 'disabled' : ''; ?>>Complete Sale</button>
            </form>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
