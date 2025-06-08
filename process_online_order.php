<?php
// pharmacy_management_system/process_online_order.php
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
$order_id = null;
$order = null;
$order_items = array(); // PHP 6.9 compatible array()
$feedbackMessage = '';
$feedbackStatus = '';

// --- Handle Form Submissions (POST requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $new_status = isset($_POST['new_order_status']) ? $_POST['new_order_status'] : ''; // For general status update
    $cancellation_reason = isset($_POST['cancellation_reason']) ? $_POST['cancellation_reason'] : ''; // For cancellation

    if ($order_id === 0) {
        $_SESSION['feedback_message'] = "Invalid Order ID for action.";
        $_SESSION['feedback_status'] = "error";
        header('Location: manage_online_orders.php');
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Fetch current order state to prevent race conditions and invalid transitions
        $stmt = $pdo->prepare("SELECT order_status, stock_deducted FROM Sales WHERE sale_id = ? FOR UPDATE"); // Add FOR UPDATE for locking
        $stmt->execute(array($order_id)); // PHP 6.9 compatible array()
        $currentOrderState = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentOrderState) {
            throw new Exception("Order not found or already processed unexpectedly.");
        }

        $current_order_status = $currentOrderState['order_status'];
        $stock_already_deducted = (bool)$currentOrderState['stock_deducted']; // Cast to boolean

        switch ($action) {
            case 'verify_payment':
                if ($current_order_status === 'Pending Payment Verification') {
                    // Deduct stock here immediately upon verification
                    if (!$stock_already_deducted) {
                        $stmtItems = $pdo->prepare("SELECT drug_id, quantity_sold FROM SaleItems WHERE sale_id = ?");
                        $stmtItems->execute(array($order_id)); // PHP 6.9 compatible array()
                        $itemsToDeduct = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($itemsToDeduct as $item) {
                            $drug_id = $item['drug_id'];
                            $quantity_ordered = $item['quantity_sold'];

                            // Check current stock
                            $stmtStock = $pdo->prepare("SELECT SUM(quantity) AS current_stock FROM Stock WHERE drug_id = ? AND expiry_date >= CURDATE() FOR UPDATE"); // Lock stock row
                            $stmtStock->execute(array($drug_id)); // PHP 6.9 compatible array()
                            $current_stock = $stmtStock->fetchColumn();

                            if ($current_stock === false || $current_stock < $quantity_ordered) {
                                // Rollback immediately if stock is insufficient for ANY item
                                $pdo->rollBack();
                                $_SESSION['feedback_message'] = "Action failed: Insufficient stock for drug ID " . $drug_id . ". Available: " . (int)$current_stock . ", Ordered: " . $quantity_ordered . ". Order not processed.";
                                $_SESSION['feedback_status'] = "error";
                                header('Location: process_online_order.php?sale_id=' . $order_id);
                                exit();
                            }

                            // Deduct stock
                            // Deduct from oldest stock first for proper expiry management (simplified logic)
                            $remaining_to_deduct = $quantity_ordered;
                            $stmtBatches = $pdo->prepare("SELECT stock_id, quantity FROM Stock WHERE drug_id = ? AND quantity > 0 AND expiry_date >= CURDATE() ORDER BY expiry_date ASC FOR UPDATE");
                            $stmtBatches->execute(array($drug_id)); // PHP 6.9 compatible array()

                            while ($remaining_to_deduct > 0 && $batch = $stmtBatches->fetch(PDO::FETCH_ASSOC)) {
                                $stock_id = $batch['stock_id'];
                                $batch_quantity = $batch['quantity'];

                                if ($batch_quantity >= $remaining_to_deduct) {
                                    // Deduct fully from this batch
                                    $stmtUpdateStock = $pdo->prepare("UPDATE Stock SET quantity = quantity - ? WHERE stock_id = ?");
                                    $stmtUpdateStock->execute(array($remaining_to_deduct, $stock_id)); // PHP 6.9 compatible array()
                                    $remaining_to_deduct = 0;
                                } else {
                                    // Deduct entire batch and move to next
                                    $stmtUpdateStock = $pdo->prepare("UPDATE Stock SET quantity = 0 WHERE stock_id = ?");
                                    $stmtUpdateStock->execute(array($stock_id)); // PHP 6.9 compatible array()
                                    $remaining_to_deduct -= $batch_quantity;
                                }
                            }
                        }

                        // Mark stock as deducted for the sale
                        $stmtUpdateSaleStockStatus = $pdo->prepare("UPDATE Sales SET stock_deducted = TRUE WHERE sale_id = ?");
                        $stmtUpdateSaleStockStatus->execute(array($order_id)); // PHP 6.9 compatible array()
                        $_SESSION['feedback_message'] = "Stock deducted and "; // Prefix for success message
                    } else {
                        $_SESSION['feedback_message'] = "Stock was already deducted. ";
                    }

                    // Now update order status and payment status
                    $stmt = $pdo->prepare("UPDATE Sales SET order_status = 'Processing', payment_status = 'Verified' WHERE sale_id = ?");
                    $stmt->execute(array($order_id)); // PHP 6.9 compatible array()
                    $_SESSION['feedback_message'] .= "Payment verified successfully. Order status set to 'Processing'.";
                    $_SESSION['feedback_status'] = "success";
                } else {
                    throw new Exception("Payment cannot be verified for current order status: " . $current_order_status);
                }
                break;

            case 'update_status':
                $valid_status_transitions = array( // PHP 6.9 compatible array()
                    'Pending Payment Verification' => array('Processing', 'Cancelled'), // PHP 6.9 compatible array()
                    'Processing' => array('Ready for Pickup', 'Ready for Delivery', 'Completed', 'Cancelled'), // PHP 6.9 compatible array()
                    'Ready for Pickup' => array('Completed', 'Cancelled'), // PHP 6.9 compatible array()
                    'Ready for Delivery' => array('Completed', 'Cancelled'), // PHP 6.9 compatible array()
                    // 'Completed' and 'Cancelled' are usually final states
                );

                if (!isset($valid_status_transitions[$current_order_status]) || !in_array($new_status, $valid_status_transitions[$current_order_status])) {
                    throw new Exception("Invalid status transition from '" . $current_order_status . "' to '" . $new_status . "'.");
                }

                // --- Stock Return Logic (for cancellation) ---
                if ($new_status === 'Cancelled' && $stock_already_deducted) {
                    $stmtItems = $pdo->prepare("SELECT drug_id, quantity_sold FROM SaleItems WHERE sale_id = ?");
                    $stmtItems->execute(array($order_id)); // PHP 6.9 compatible array()
                    $itemsToReturn = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($itemsToReturn as $item) {
                        $drug_id = $item['drug_id'];
                        $quantity_ordered = $item['quantity_sold'];

                        // Return stock (add back to any valid stock entry for that drug_id)
                        $stmtUpdateStock = $pdo->prepare("UPDATE Stock SET quantity = quantity + ? WHERE drug_id = ? LIMIT 1"); // Add to any existing batch, or could create new if needed
                        $stmtUpdateStock->execute(array($quantity_ordered, $drug_id)); // PHP 6.9 compatible array()
                    }
                    // Mark stock as not deducted for the sale
                    $stmtUpdateSaleStockStatus = $pdo->prepare("UPDATE Sales SET stock_deducted = FALSE WHERE sale_id = ?");
                    $stmtUpdateSaleStockStatus->execute(array($order_id)); // PHP 6.9 compatible array()
                    $_SESSION['feedback_message'] = "Stock returned and "; // Prefix for success message
                }

                // Update order status
                $stmt = $pdo->prepare("UPDATE Sales SET order_status = ? WHERE sale_id = ?");
                $stmt->execute(array($new_status, $order_id)); // PHP 6.9 compatible array()
                $_SESSION['feedback_message'] .= "Order status updated to '" . $new_status . "'.";
                $_SESSION['feedback_status'] = "success";
                break;

            case 'cancel_order':
                // Check if the order is NOT in 'Pending Payment Verification' status
                if ($current_order_status !== 'Pending Payment Verification') {
                    throw new Exception("Order cannot be cancelled once payment has been verified or order is already being processed.");
                }

                // --- Stock Return Logic for Cancellation ---
                if ($stock_already_deducted) { // This condition should ideally not be true if payment is not yet verified.
                    $stmtItems = $pdo->prepare("SELECT drug_id, quantity_sold FROM SaleItems WHERE sale_id = ?");
                    $stmtItems->execute(array($order_id)); // PHP 6.9 compatible array()
                    $itemsToReturn = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($itemsToReturn as $item) {
                        $drug_id = $item['drug_id'];
                        $quantity_ordered = $item['quantity_sold'];

                        // Return stock (add back to any valid stock entry for that drug_id)
                        $stmtUpdateStock = $pdo->prepare("UPDATE Stock SET quantity = quantity + ? WHERE drug_id = ? LIMIT 1");
                        $stmtUpdateStock->execute(array($quantity_ordered, $drug_id)); // PHP 6.9 compatible array()
                    }
                    // Mark stock as not deducted for the sale
                    $stmtUpdateSaleStockStatus = $pdo->prepare("UPDATE Sales SET stock_deducted = FALSE WHERE sale_id = ?");
                    $stmtUpdateSaleStockStatus->execute(array($order_id)); // PHP 6.9 compatible array()
                    $_SESSION['feedback_message'] = "Stock returned and "; // Prefix for success message
                }

                $stmt = $pdo->prepare("UPDATE Sales SET order_status = 'Cancelled', cancellation_reason = ? WHERE sale_id = ?"); // Uncommented cancellation_reason
                $stmt->execute(array($cancellation_reason, $order_id)); // PHP 6.9 compatible array()
                $_SESSION['feedback_message'] .= "Order successfully cancelled.";
                $_SESSION['feedback_status'] = "success";
                break;

            default:
                throw new Exception("Invalid action specified.");
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['feedback_message'] = "Action failed: " . htmlspecialchars($e->getMessage());
        $_SESSION['feedback_status'] = "error";
    }
    // Redirect back to this page to show updated status and prevent re-submission
    header('Location: process_online_order.php?sale_id=' . $order_id);
    exit();
}

// --- Fetch Order Details for Display (GET request or after POST redirect) ---
if (isset($_GET['sale_id']) && is_numeric($_GET['sale_id'])) {
    $order_id = (int)$_GET['sale_id'];

    try {
        // Fetch the specific order details for staff
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
                s.discount_amount,
                s.amount_paid,
                s.proof_of_payment_path,
                s.stock_deducted,
                -- Use CONCAT for first_name and last_name as customer_name
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                c.phone_number AS customer_phone,
                c.email AS customer_email,
                c.address AS customer_address
            FROM
                Sales s
            JOIN
                Customers c ON s.customer_id = c.customer_id -- Simplified JOIN (assuming direct link)
            WHERE
                s.sale_id = ? AND s.order_type IN ('pickup', 'delivery')
        ");
        $stmt->execute(array($order_id)); // PHP 6.9 compatible array()
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Fetch the items for this order along with current stock levels
            $stmtItems = $pdo->prepare("
                SELECT
                    si.quantity_sold,
                    si.price_per_unit_at_sale,
                    si.subtotal,
                    d.drug_name,
                    d.description,
                    SUM(st.quantity) AS current_stock_available -- SUM quantities from all batches for the drug
                FROM
                    SaleItems si
                JOIN
                    Drugs d ON si.drug_id = d.drug_id
                LEFT JOIN -- Use LEFT JOIN in case a drug doesn't have stock entry
                    Stock st ON d.drug_id = st.drug_id AND st.expiry_date >= CURDATE() -- Filter for non-expired stock
                WHERE
                    si.sale_id = ?
                GROUP BY
                    si.drug_id, si.quantity_sold, si.price_per_unit_at_sale, si.subtotal, d.drug_name, d.description
            ");
            $stmtItems->execute(array($order_id)); // PHP 6.9 compatible array()
            $order_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $_SESSION['feedback_message'] = "Error: Order not found or is not an online order.";
            $_SESSION['feedback_status'] = "error";
            header('Location: manage_online_orders.php');
            exit();
        }

    } catch (PDOException $e) {
        $_SESSION['feedback_message'] = 'Database error loading order details: ' . htmlspecialchars($e->getMessage());
        $_SESSION['feedback_status'] = "error";
        error_log("Error fetching order " . $order_id . " for processing: " . $e->getMessage());
        header('Location: manage_online_orders.php');
        exit();
    }
} else {
    $_SESSION['feedback_message'] = 'Error: No order ID provided.';
    $_SESSION['feedback_status'] = "error";
    header('Location: manage_online_orders.php');
    exit();
}

// Display feedback message from session if redirected
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = $_SESSION['feedback_status'];
    unset($_SESSION['feedback_message']);
    unset($_SESSION['feedback_status']);
}

// Define available status options for staff, depends on current status
$next_status_options = array(); // PHP 6.9 compatible array()
$current_status = $order['order_status'];

if ($current_status === 'Pending Payment Verification') {
    $next_status_options = array('Processing', 'Cancelled'); // PHP 6.9 compatible array()
} elseif ($current_status === 'Processing') {
    $next_status_options = array('Ready for Pickup', 'Ready for Delivery', 'Completed', 'Cancelled'); // PHP 6.9 compatible array()
} elseif ($current_status === 'Ready for Pickup' || $current_status === 'Ready for Delivery') {
    $next_status_options = array('Completed', 'Cancelled'); // PHP 6.9 compatible array()
}
// Completed and Cancelled are generally final states, no further transitions allowed from UI
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Online Order #<?php echo htmlspecialchars($order['sale_id']); ?> - Woloma Pharmacy Staff</title>
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

        /* Main content area - Consistent with other pages */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1000px;
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

        /* Order Sections - Consistent styling */
        .order-section {
            margin-bottom: 25px;
            padding: 20px; /* Increased padding */
            border: 1px solid #e0e0e0; /* Consistent border color */
            border-radius: 10px; /* Consistent rounding */
            background-color: #fdfdfd; /* Light background */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
        }
        .order-section h3 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.4em;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0; /* Increased padding */
            border-bottom: 1px dashed #f0f0f0;
            font-size: 0.95em;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-row span:first-child {
            font-weight: 600; /* Bolder for prominence */
            color: #555;
        }
        .order-items-table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 20px;
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        .order-items-table th, .order-items-table td {
            border: none; /* Remove individual cell borders */
            padding: 12px;
            text-align: left;
            vertical-align: middle; /* Align content vertically */
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        .order-items-table th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        .order-items-table tbody tr:nth-child(even) { /* Changed to even for better contrast */
            background-color: #fcfcfc;
        }
        .order-items-table tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }
        .order-items-table thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        .order-items-table thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        .order-items-table tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        .order-items-table tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }

        .status-badge {
            display: inline-block;
            padding: 6px 12px; /* Adjusted padding */
            border-radius: 20px; /* More rounded */
            font-size: 0.85em; /* Slightly larger */
            font-weight: bold;
            color: white;
            text-align: center;
            min-width: 100px; /* Slightly wider */
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); /* Subtle shadow */
        }
        .status-pending { background-color: #ffc107; color: #343a40; } /* yellow */
        .status-processing { background-color: #007bff; } /* blue */
        .status-ready { background-color: #17a2b8; } /* teal */
        .status-completed { background-color: #28a745; } /* green */
        .status-cancelled { background-color: #dc3545; } /* red */
        .status-default { background-color: #6c757d; } /* grey */

        .proof-of-payment-link {
            margin-top: 20px;
            text-align: center;
            padding: 15px; /* Increased padding */
            border: 1px dashed #007bff;
            border-radius: 8px; /* Consistent rounding */
            background-color: #eaf5ff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Subtle shadow */
        }
        .proof-of-payment-link p {
            margin: 0;
        }
        .proof-of-payment-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s ease, text-decoration 0.2s ease;
        }
        .proof-of-payment-link a:hover {
            text-decoration: underline;
            color: #0056b3;
        }
        
        .action-forms {
            display: flex;
            flex-wrap: wrap;
            gap: 25px; /* Increased gap */
            margin-top: 30px;
            justify-content: center;
        }
        .action-form-group {
            background-color: #fdfdfd; /* Light background */
            padding: 25px; /* Increased padding */
            border-radius: 10px; /* Consistent rounding */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Consistent border */
            flex: 1 1 calc(50% - 12.5px); /* Two columns, adjusted for gap */
            min-width: 320px; /* Ensure a decent minimum width */
            box-sizing: border-box; /* Include padding/border in width */
        }
        .action-form-group h4 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.25em;
        }
        .action-form-group p {
            font-size: 0.95em;
            color: #666;
            margin-bottom: 15px;
        }
        .action-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.9em;
        }
        .action-form-group select,
        .action-form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            margin-bottom: 18px; /* Increased margin */
            box-sizing: border-box;
            font-size: 0.9em;
        }
        .action-form-group button {
            padding: 12px 20px; /* Adjusted padding */
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1em;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        button.btn-primary { background-color: #007bff; color: white; }
        button.btn-primary:hover { background-color: #0056b3; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15); }
        button.btn-success { background-color: #28a745; color: white; }
        button.btn-success:hover { background-color: #218838; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15); }
        button.btn-danger { background-color: #dc3545; color: white; }
        button.btn-danger:hover { background-color: #c82333; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15); }
        button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
            opacity: 0.8;
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
            .action-form-group {
                flex: 1 1 calc(100% - 25px); /* Stack action forms vertically */
                min-width: unset;
            }
            .order-items-table {
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
            .auth-status form {
                width: 100%; /* Full width on smaller screens */
            }
            .order-section,
            .action-form-group,
            .proof-of-payment-link {
                padding: 15px;
            }
            .action-form-group select,
            .action-form-group textarea,
            .action-form-group button {
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
        <h1>Process Order #<?php echo htmlspecialchars($order['sale_id']); ?></h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="manage_online_orders.php">Back to Order List</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if ($order): ?>
            <div class="order-section">
                <h3>Order Summary</h3>
                <div class="detail-row">
                    <span>Order ID:</span>
                    <span><?php echo htmlspecialchars($order['sale_id']); ?></span>
                </div>
                <div class="detail-row">
                    <span>Order Date:</span>
                    <span><?php echo htmlspecialchars($order['sale_date']); ?></span>
                </div>
                <div class="detail-row">
                    <span>Order Type:</span>
                    <span><?php echo htmlspecialchars(ucfirst($order['order_type'])); ?></span>
                </div>
                <?php if ($order['order_type'] === 'delivery' && !empty($order['delivery_address'])): ?>
                    <div class="detail-row">
                        <span>Delivery Address:</span>
                        <span><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span>Order Status:</span>
                    <span>
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
                    </span>
                </div>
                <div class="detail-row">
                    <span>Payment Method:</span>
                    <span><?php echo htmlspecialchars(ucfirst($order['payment_status'])); ?></span>
                </div>
                <div class="detail-row">
                    <span>Total Amount:</span>
                    <span>ETB <?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
                <div class="detail-row">
                    <span>Amount Paid:</span>
                    <span>ETB <?php echo number_format($order['amount_paid'], 2); ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="detail-row">
                        <span>Discount:</span>
                        <span>- ETB <?php echo number_format($order['discount_amount'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span>Stock Deducted:</span>
                    <span><?php echo $order['stock_deducted'] ? 'Yes' : 'No'; ?></span>
                </div>
                <?php if (!empty($order['notes'])): ?>
                    <div class="detail-row">
                        <span>Customer Notes:</span>
                        <span><?php echo nl2br(htmlspecialchars($order['notes'])); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="order-section">
                <h3>Customer Information</h3>
                <div class="detail-row">
                    <span>Name:</span>
                    <span><?php echo htmlspecialchars($order['customer_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span>Phone:</span>
                    <span><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                </div>
                <div class="detail-row">
                    <span>Email:</span>
                    <span><?php echo htmlspecialchars($order['customer_email']); ?></span>
                </div>
                <div class="detail-row">
                    <span>Address:</span>
                    <span><?php echo htmlspecialchars($order['customer_address']); ?></span>
                </div>
            </div>

            <div class="order-section">
                <h3>Ordered Items</h3>
                <?php if (empty($order_items)): ?>
                    <p>No items found for this order.</p>
                <?php else: ?>
                    <table class="order-items-table">
                        <thead>
                            <tr>
                                <th>Drug Name</th>
                                <th>Description</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                                <th>Current Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['drug_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td><?php echo htmlspecialchars($item['quantity_sold']); ?></td>
                                    <td>ETB <?php echo number_format($item['price_per_unit_at_sale'], 2); ?></td>
                                    <td>ETB <?php echo number_format($item['subtotal'], 2); ?></td>
                                    <td>
                                        <?php
                                            $stock_text = ($item['current_stock_available'] !== null) ? htmlspecialchars($item['current_stock_available']) : 'N/A';
                                            $stock_color = '';
                                            if ($item['current_stock_available'] !== null && $item['current_stock_available'] < $item['quantity_sold']) {
                                                $stock_color = 'color: red; font-weight: bold;';
                                            }
                                        ?>
                                        <span style="<?php echo htmlspecialchars($stock_color); ?>"><?php echo $stock_text; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if (!empty($order['proof_of_payment_path'])): ?>
                <div class="proof-of-payment-link">
                    <p>
                        <a href="<?php echo htmlspecialchars($order['proof_of_payment_path']); ?>" target="_blank">
                            View Proof of Payment (Click to Open)
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <p class="message info">No proof of payment uploaded for this order.</p>
            <?php endif; ?>

            <div class="action-forms">
                <?php if ($order['order_status'] === 'Pending Payment Verification'): ?>
                    <div class="action-form-group">
                        <h4>Verify Payment</h4>
                        <p>Review the proof of payment. If valid, click 'Verify'. This will deduct stock.</p>
                        <form action="process_online_order.php" method="POST">
                            <input type="hidden" name="sale_id" value="<?php echo htmlspecialchars($order['sale_id']); ?>">
                            <input type="hidden" name="action" value="verify_payment">
                            <button type="submit" class="btn-success">Verify Payment & Deduct Stock</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($next_status_options) && ($order['order_status'] !== 'Completed' && $order['order_status'] !== 'Cancelled')): ?>
                    <div class="action-form-group">
                        <h4>Update Order Status</h4>
                        <p>Change the status of the order. If setting to 'Cancelled' after stock deduction, stock will be returned.</p>
                        <form action="process_online_order.php" method="POST">
                            <input type="hidden" name="sale_id" value="<?php echo htmlspecialchars($order['sale_id']); ?>">
                            <input type="hidden" name="action" value="update_status">
                            <label for="new_order_status">Change Status To:</label>
                            <select name="new_order_status" id="new_order_status" required>
                                <?php foreach ($next_status_options as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-primary">Update Status</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php
                // The cancel option is now only available if the order is 'Pending Payment Verification'
                if ($order['order_status'] === 'Pending Payment Verification'):
                ?>
                    <div class="action-form-group">
                        <h4>Cancel Order</h4>
                        <p>Cancel this order. Stock will be returned if already deducted.</p>
                        <form action="process_online_order.php" method="POST">
                            <input type="hidden" name="sale_id" value="<?php echo htmlspecialchars($order['sale_id']); ?>">
                            <input type="hidden" name="action" value="cancel_order">
                            <label for="cancellation_reason">Reason for Cancellation (Optional):</label>
                            <textarea name="cancellation_reason" id="cancellation_reason" rows="3"></textarea>
                            <button type="submit" class="btn-danger" onclick="return confirm('Are you sure you want to cancel this order? This action may return stock.');">Cancel Order</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <p class="message error">Order not found or you do not have permission to view/process it.</p>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
