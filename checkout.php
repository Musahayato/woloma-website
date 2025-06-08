<?php
// pharmacy_management_system/checkout.php
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
$feedbackMessage = '';
$feedbackStatus = ''; // Initialize feedbackStatus for message styling

$customer_id = $_SESSION['customer_id']; // Get customer_id from session

// Redirect if cart is empty
if (empty($_SESSION['cart'])) {
    $_SESSION['feedback_message'] = 'Your cart is empty. Please add items before checking out.';
    $_SESSION['feedback_status'] = 'warning';
    header('Location: online_drugs.php');
    exit();
}

// Fetch customer's address for delivery option pre-fill
$customerAddress = '';
try {
    $stmt = $pdo->prepare("SELECT address FROM Customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['address'])) {
        $customerAddress = $result['address'];
    }
} catch (PDOException $e) {
    // Log error but don't prevent checkout
    error_log("Error fetching customer address: " . $e->getMessage());
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_type = isset($_POST['order_type']) ? trim($_POST['order_type']) : '';
    $delivery_address = ($order_type === 'delivery') ? trim(isset($_POST['delivery_address']) ? $_POST['delivery_address'] : '') : null;
    $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');
    $proof_of_payment_upload = isset($_FILES['proof_of_payment_upload']) ? $_FILES['proof_of_payment_upload'] : null;

    // --- Server-side Validation ---
    $errors = [];

    if (!in_array($order_type, ['pickup', 'delivery'])) {
        $errors[] = 'Please select a valid order type (Pickup or Delivery).';
    }

    if ($order_type === 'delivery') {
        if (empty($delivery_address)) {
            $errors[] = 'Delivery address is required for delivery orders.';
        } elseif (strlen($delivery_address) > 255) { // Max length for delivery address
            $errors[] = 'Delivery address cannot exceed 255 characters.';
        }
    } else {
        $delivery_address = null; // Ensure delivery_address is null if not delivery
    }

    if (strlen($notes) > 500) { // Max length for notes
        $errors[] = 'Notes cannot exceed 500 characters.';
    }

    // Proof of Payment upload validation
    if ($proof_of_payment_upload === null || $proof_of_payment_upload['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Proof of Payment is required to place an order.';
    } elseif ($proof_of_payment_upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Error uploading Proof of Payment: ' . htmlspecialchars($proof_of_payment_upload['error']);
    } else {
        $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
        $file_extension = pathinfo($proof_of_payment_upload['name'], PATHINFO_EXTENSION);

        if (!in_array(strtolower($file_extension), $allowed_types)) {
            $errors[] = 'Only JPG, JPEG, PNG, and PDF files are allowed for Proof of Payment.';
        } elseif ($proof_of_payment_upload['size'] > 10 * 1024 * 1024) { // 10MB limit
            $errors[] = 'Proof of Payment file is too large (max 10MB).';
        }
    }


    if (!empty($errors)) {
        $feedbackMessage = 'Error: ' . implode('<br>', $errors);
        $feedbackStatus = 'error';
    } else { // All initial validations passed, proceed with file upload and order placement
        try {
            $pdo->beginTransaction();

            // 1. Handle Proof of Payment upload
            $proof_of_payment_path = null;
            $target_dir = "uploads/payments/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $file_extension = pathinfo($proof_of_payment_upload['name'], PATHINFO_EXTENSION);
            $file_name = uniqid('payment_proof_') . '.' . $file_extension;
            $target_file = $target_dir . $file_name;

            if (!move_uploaded_file($proof_of_payment_upload['tmp_name'], $target_file)) {
                throw new Exception('Failed to upload Proof of Payment file.');
            } else {
                $proof_of_payment_path = $target_file;
            }

            // Calculate total amount from cart
            $total_amount = 0;
            foreach ($_SESSION['cart'] as $item) {
                $total_amount += $item['price'] * $item['quantity'];
            }

            // Define values for new attributes
            $payment_method = 'online'; // Payment method for customer web orders
            $pharmacist_user_id = NULL; // No pharmacist involved in placing the order by customer
            $discount_amount = 0.00; // No discount for now
            $amount_paid = $total_amount; // Assuming full payment for online orders

            $order_status = 'Pending Payment Verification'; // Initial status for online orders

            // 2. Create a new Sales record (Order Header) with all attributes
            $stmtSales = $pdo->prepare("
                INSERT INTO Sales (
                    customer_id,
                    sale_date,
                    total_amount,
                    payment_status,
                    user_id,
                    notes,
                    discount_amount,
                    amount_paid,
                    proof_of_payment_path,
                    order_status,
                    order_type,
                    delivery_address,
                    stock_deducted /* Add this column to the INSERT statement */
                ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)"); // Default stock_deducted to FALSE for online orders

            $stmtSales->execute([
                $customer_id,
                $total_amount,
                $payment_method,
                $pharmacist_user_id,
                $notes,
                $discount_amount,
                $amount_paid,
                $proof_of_payment_path,
                $order_status,
                $order_type,
                $delivery_address
            ]);
            $sale_id = $pdo->lastInsertId();

            if (!$sale_id) {
                throw new Exception("Failed to create order header.");
            }

            // 3. Insert into SaleItems for each item in the cart
            $stmtSaleItem = $pdo->prepare("INSERT INTO SaleItems (sale_id, drug_id, quantity_sold, price_per_unit_at_sale, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($_SESSION['cart'] as $item) {
                $subtotal = $item['price'] * $item['quantity'];
                $stmtSaleItem->execute([
                    $sale_id,
                    $item['drug_id'],
                    $item['quantity'],
                    $item['price'],
                    $subtotal
                ]);
            }

            $pdo->commit();

            // Clear the cart after successful order
            unset($_SESSION['cart']);

            // Set success message here for display on the current page
            $_SESSION['feedback_message'] = 'Your order has been placed successfully! Order ID: ' . $sale_id . '. We are now verifying your payment. You will be notified once confirmed.';
            $_SESSION['feedback_status'] = 'success';
            header('Location: customer_dashboard.php'); // Redirect to customer dashboard
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $feedbackMessage = 'Database error placing order: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
            // If file was uploaded before PDO error, attempt to delete it
            if (isset($proof_of_payment_path) && file_exists($proof_of_payment_path)) {
                unlink($proof_of_payment_path);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $feedbackMessage = 'Error placing order: ' . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
            // If file was uploaded before general error, attempt to delete it
            if (isset($proof_of_payment_path) && file_exists($proof_of_payment_path)) {
                unlink($proof_of_payment_path);
            }
        }
    }
}

// Display messages from URL params (e.g., from view_cart.php redirect) or from POST processing
if (isset($_SESSION['feedback_message'])) {
    $feedbackMessage = $_SESSION['feedback_message'];
    $feedbackStatus = $_SESSION['feedback_status'];
    unset($_SESSION['feedback_message']);
    unset($_SESSION['feedback_status']);
}


// Calculate cart total for display
$cartTotal = 0;
// Ensure $_SESSION['cart'] is an array before iterating
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartTotal += $item['price'] * $item['quantity'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Woloma Pharmacy</title>
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

        /* Main Content Area - Consistent across pages */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 800px; /* Adjusted max-width for forms */
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

        /* Form & Summary Sections */
        .checkout-summary, .checkout-form {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
            margin-bottom: 30px;
        }
        .checkout-summary h3, .checkout-form h3 {
            color: #205072; /* Consistent header color */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em;
            text-align: center; /* Center form headings */
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0; /* Consistent dashed line */
            font-size: 0.95em;
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-total {
            font-size: 1.5em; /* Slightly larger total font size */
            font-weight: bold;
            margin-top: 20px;
            text-align: right;
            color: #28a745; /* Green for total */
        }

        /* Form Elements */
        .checkout-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 0.95em;
        }
        .checkout-form input[type="radio"] {
            margin-right: 10px;
        }
        .checkout-form input[type="text"],
        .checkout-form textarea,
        .checkout-form input[type="file"] { /* Apply consistent styles to file input */
            width: 100%;
            padding: 10px;
            margin-bottom: 18px; /* Consistent margin */
            border: 1px solid #c0c0c0;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 0.9em;
            height: 40px; /* Standard height for inputs */
        }
        .checkout-form textarea {
            height: auto; /* Allow textarea to expand */
            min-height: 80px; /* Minimum height for textarea */
            resize: vertical; /* Allow vertical resizing */
        }
        .checkout-form .radio-group label {
            display: inline-block;
            margin-right: 20px;
            font-weight: normal; /* Override bold for radio labels */
        }
        .checkout-form button {
            width: 100%;
            padding: 15px;
            background-color: #007bff; /* Primary blue for action button */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .checkout-form button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .payment-upload-section {
            background-color: #e9f5ff; /* Light blue background */
            padding: 20px;
            border: 1px dashed #007bff; /* Dashed primary blue border */
            border-radius: 10px;
            margin-top: 20px;
            text-align: center;
        }
        .payment-upload-section p {
            color: #007bff;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .payment-upload-section label {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .required-payment-notice {
            color: #dc3545; /* Red for required notice */
            font-weight: bold;
            margin-bottom: 10px;
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
            .auth-status button {
                width: 100%; /* Full width on smaller screens */
            }
            .checkout-summary, .checkout-form {
                padding: 20px;
            }
            .checkout-form input[type="text"],
            .checkout-form textarea,
            .checkout-form input[type="file"],
            .checkout-form button {
                width: 100%; /* Ensure full width on smaller screens */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const orderTypeRadios = document.querySelectorAll('input[name="order_type"]');
            const deliveryAddressField = document.getElementById('delivery_address_field');
            const customerAddressInput = document.getElementById('delivery_address');

            function toggleDeliveryAddress() {
                if (document.getElementById('delivery_option_delivery').checked) {
                    deliveryAddressField.style.display = 'block';
                    customerAddressInput.setAttribute('required', 'required');
                } else {
                    deliveryAddressField.style.display = 'none';
                    customerAddressInput.removeAttribute('required');
                }
            }

            orderTypeRadios.forEach(radio => {
                radio.addEventListener('change', toggleDeliveryAddress);
            });

            // Set initial state based on default checked option
            toggleDeliveryAddress();
        });
    </script>
</head>
<body>
    <header>
        <h1>Checkout</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_dashboard.php">Back to Dashboard</a>
            <a href="view_cart.php">Back to Cart</a>
            <a href="online_drugs.php">Continue Shopping</a>
        </div>
    </header>
    <main>
        <?php
        // Display feedback message if available
        if (!empty($feedbackMessage)) {
            // Apply the appropriate class based on feedbackStatus
            $messageClass = '';
            switch ($feedbackStatus) {
                case 'success':
                    $messageClass = 'success';
                    break;
                case 'error':
                    $messageClass = 'error';
                    break;
                case 'warning':
                    $messageClass = 'warning';
                    break;
                default:
                    $messageClass = 'info'; // Fallback for unknown status
                    break;
            }
            echo '<p class="message ' . htmlspecialchars($messageClass) . '">' . htmlspecialchars($feedbackMessage) . '</p>';
        }
        ?>

        <div class="checkout-summary">
            <h3>Order Summary</h3>
            <?php if (empty($_SESSION['cart'])): ?>
                <p>Your cart is empty.</p>
            <?php else: ?>
                <?php foreach ($_SESSION['cart'] as $item): ?>
                    <div class="summary-item">
                        <span><?php echo htmlspecialchars($item['drug_name']); ?> (x<?php echo htmlspecialchars($item['quantity']); ?>)</span>
                        <span>ETB <?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="summary-total">
                    Total: ETB <?php echo number_format($cartTotal, 2); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="checkout-form">
            <h3>Order Details</h3>
            <form action="checkout.php" method="POST" enctype="multipart/form-data">
                <div class="form-group radio-group">
                    <label>Order Type:</label>
                    <label>
                        <input type="radio" id="delivery_option_pickup" name="order_type" value="pickup" checked> Pickup at Pharmacy
                    </label>
                    <label>
                        <input type="radio" id="delivery_option_delivery" name="order_type" value="delivery"> Delivery
                    </label>
                </div>

                <div id="delivery_address_field" style="display:none;">
                    <label for="delivery_address">Delivery Address:</label>
                    <input type="text" id="delivery_address" name="delivery_address" placeholder="e.g., House No., Street, City, Region" value="<?php echo htmlspecialchars($customerAddress); ?>" maxlength="255">
                </div>

                <label for="notes">Special Notes / Instructions (Optional):</label>
                <textarea id="notes" name="notes" rows="4" placeholder="e.g., Call before delivery, specific pickup time, etc." maxlength="500"></textarea>

                <div class="payment-upload-section">
                    <p class="required-payment-notice">Please upload your Proof of Payment to complete the order.</p>
                    <label for="proof_of_payment_upload">Upload Proof of Payment (JPG, PNG, PDF - Max 10MB):</label>
                    <input type="file" id="proof_of_payment_upload" name="proof_of_payment_upload" accept=".jpg, .jpeg, .png, .pdf" required>
                </div>

                <button type="submit">Place Order</button>
            </form>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
