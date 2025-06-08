<?php
// pharmacy_management_system/online_drugs.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in as a customer using the consistent function
// If the user is not a customer, they are redirected to login.php
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], array('Customer'))) { // PHP 6.9 compatible array()
    header('Location: login.php');
    exit();
}

$pdo = getDbConnection();
$drugs = array(); // Initialize as empty array for PHP 6.9 consistency
$feedbackMessage = ''; // Will be populated from session feedback
$feedbackStatus = '';  // Will be populated from session feedback

try {
    // Fetch drugs that are currently in stock and are not expired
    // We're joining with Stock table to only show available drugs and get the price from Stock
    $stmt = $pdo->prepare("
        SELECT
            d.drug_id,
            d.drug_name,
            d.description,
            AVG(s.selling_price_per_unit) AS average_price,
            SUM(s.quantity) AS available_quantity
        FROM
            Drugs d
        JOIN
            Stock s ON d.drug_id = s.drug_id
        WHERE
            s.expiry_date >= CURDATE()
        GROUP BY
            d.drug_id, d.drug_name, d.description
        HAVING
            available_quantity > 0
        ORDER BY
            d.drug_name ASC
    ");
    $stmt->execute();
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $_SESSION['feedback_message'] = 'Error loading drugs: ' . htmlspecialchars($e->getMessage());
    $_SESSION['feedback_status'] = 'error';
    // No exit needed, just display the error later
}

// --- Shopping Cart Logic ---
// Initialize cart if it doesn't exist
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array(); // PHP 6.9 compatible array()
}

// Handle adding to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    $drug_id = isset($_POST['drug_id']) ? (int)$_POST['drug_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

    if ($drug_id > 0 && $quantity > 0) {
        // Fetch drug details and its *current price* and available quantity
        // This query is critical as it fetches the stock info for validation
        $stmt = $pdo->prepare("
            SELECT
                d.drug_id,
                d.drug_name,
                AVG(s.selling_price_per_unit) AS current_price,
                SUM(s.quantity) AS available_quantity
            FROM
                Drugs d
            JOIN
                Stock s ON d.drug_id = s.drug_id
            WHERE
                d.drug_id = ? AND s.expiry_date >= CURDATE()
            GROUP BY
                d.drug_id, d.drug_name
            HAVING
                available_quantity > 0
        ");
        $stmt->execute(array($drug_id)); // PHP 6.9 array() syntax
        $drug = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($drug) {
            $availableQty = $drug['available_quantity'];
            $currentPrice = $drug['current_price'];

            // Current quantity in cart for this drug
            $currentCartQty = isset($_SESSION['cart'][$drug_id]['quantity']) ? $_SESSION['cart'][$drug_id]['quantity'] : 0;

            if (($currentCartQty + $quantity) > $availableQty) {
                $_SESSION['feedback_message'] = 'Warning: Not enough stock for ' . htmlspecialchars($drug['drug_name']) . '. Only ' . $availableQty . ' available.';
                $_SESSION['feedback_status'] = 'warning';
            } else {
                if (isset($_SESSION['cart'][$drug_id])) {
                    $_SESSION['cart'][$drug_id]['quantity'] += $quantity;
                    $_SESSION['cart'][$drug_id]['price'] = $currentPrice; // Update price in cart
                } else {
                    $_SESSION['cart'][$drug_id] = array( // PHP 6.9 array() syntax
                        'drug_id' => $drug['drug_id'],
                        'drug_name' => $drug['drug_name'],
                        'price' => $currentPrice,
                        'quantity' => $quantity
                    );
                }
                $_SESSION['feedback_message'] = htmlspecialchars($quantity) . ' of ' . htmlspecialchars($drug['drug_name']) . ' added to cart.';
                $_SESSION['feedback_status'] = 'success';
            }
        } else {
            $_SESSION['feedback_message'] = 'Error: Drug not found or out of stock.';
            $_SESSION['feedback_status'] = 'error';
        }
    } else {
        $_SESSION['feedback_message'] = 'Error: Invalid drug or quantity.';
        $_SESSION['feedback_status'] = 'error';
    }
    header('Location: online_drugs.php'); // Redirect to self to prevent form resubmission
    exit();
}

// Display feedback message from session if present
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
    <title>Order Drugs Online - Woloma Pharmacy</title>
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
        /* Specific button colors for navigation */
        .auth-status a.view-cart-btn { background-color: #28a745; }
        .auth-status a.view-cart-btn:hover { background-color: #218838; }
        .auth-status a.dashboard-btn { background-color: #007bff; } /* Added dashboard button */
        .auth-status a.dashboard-btn:hover { background-color: #0056b3; }
        .auth-status form button { background-color: #dc3545; } /* Logout button */
        .auth-status form button:hover { background-color: #c82333; }

        /* Main content area - Consistent */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 900px;
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
        }

        h2 {
            text-align: center;
            color: #205072; /* Consistent header color */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 25px;
            font-size: 1.6em;
        }

        /* Unified message styling - Consistent */
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
            border-color: #ffeeba;
        }

        /* Order Form Container */
        .order-form-container {
            background-color: #fdfdfd; /* Light background */
            border: 1px solid #e0e0e0; /* Consistent border color */
            border-radius: 10px; /* Consistent rounding */
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            max-width: 500px;
            margin: 30px auto; /* Center the form */
            text-align: center;
        }
        .order-form-container h3 {
            margin-top: 0;
            color: #205072; /* Consistent header color */
            margin-bottom: 25px;
            font-size: 1.5em;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
        }
        .order-form-container form {
            display: flex;
            flex-direction: column; /* Stack elements vertically */
            gap: 15px; /* Space between form groups */
            align-items: center; /* Center items horizontally */
        }
        .form-group { /* Added for consistent styling */
            width: 100%; /* Take full width of parent */
            max-width: 300px; /* Limit max width for better appearance */
            text-align: left; /* Align label and input to left */
        }
        .form-group label {
            font-weight: 600; /* Bolder label */
            color: #555;
            margin-bottom: 8px; /* Space between label and input */
            display: block; /* Make label take its own line */
            font-size: 0.95em;
        }
        .form-group select,
        .form-group input[type="number"] {
            width: 100%; /* Full width within its container */
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box; /* Include padding in width */
            font-size: 0.9em;
            height: 40px; /* Standard height */
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-group select:focus,
        .form-group input[type="number"]:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .order-form-container button[type="submit"] {
            padding: 12px 25px;
            background-color: #007bff; /* Primary blue for add to cart */
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            margin-top: 10px; /* Space above button */
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            width: 100%;
            max-width: 300px; /* Match width of inputs */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .order-form-container button[type="submit"]:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .no-drugs {
            text-align: center;
            font-size: 1.2em;
            color: #6c757d;
            margin-top: 50px;
            padding: 20px;
            border: 1px dashed #ccc;
            border-radius: 8px;
            background-color: #f9f9f9;
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
            .order-form-container {
                padding: 20px;
                width: auto;
            }
            .form-group,
            .order-form-container button[type="submit"] {
                max-width: none; /* Allow form elements to take full width */
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Woloma Pharmacy Online Catalog</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_dashboard.php" class="dashboard-btn">Dashboard</a>
            <a href="view_cart.php" class="view-cart-btn">View Cart (<?php echo count($_SESSION['cart']); ?> items)</a>
            <form action="logout.php" method="POST">
                <button type="submit">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <h2>Order Drugs Online</h2>
        <?php if (!empty($feedbackMessage)): ?>
            <p class="message <?php echo htmlspecialchars($feedbackStatus); ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
        <?php endif; ?>

        <?php if (empty($drugs)): ?>
            <p class="no-drugs">No drugs currently available for online order. Please check back later or contact the pharmacy.</p>
        <?php else: ?>
            <div class="order-form-container">
                <h3>Select Drug to Add to Cart</h3>
                <form action="online_drugs.php" method="POST">
                    <input type="hidden" name="action" value="add_to_cart">

                    <div class="form-group">
                        <label for="drug_id">Select Drug:</label>
                        <select id="drug_id" name="drug_id" required>
                            <option value="">-- Select a Drug --</option>
                            <?php foreach ($drugs as $drug): ?>
                                <option value="<?php echo htmlspecialchars($drug['drug_id']); ?>">
                                    <?php echo htmlspecialchars($drug['drug_name']); ?> (ETB <?php echo number_format($drug['average_price'], 2); ?> / Available: <?php echo htmlspecialchars($drug['available_quantity']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity:</label>
                        <input type="number" id="quantity" name="quantity" value="1" min="1" required>
                    </div>

                    <button type="submit">Add to Cart</button>
                </form>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
