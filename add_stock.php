<?php
// pharmacy_management_system/add_stock.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// --- Authorization Check ---
// Only allow 'Admin' or 'Pharmacist' roles to add stock
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], ['Admin', 'Pharmacist'])) {
    header('Location: dashboard.php?auth_error=2'); // Redirect if not authorized
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';

// --- Handle Add Stock Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $drug_id = isset($_POST['drug_id']) ? (int)$_POST['drug_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $batch_number = isset($_POST['batch_number']) ? trim($_POST['batch_number']) : null;
    $expiry_date = isset($_POST['expiry_date']) ? trim($_POST['expiry_date']) : null;
    $location = isset($_POST['location']) ? trim($_POST['location']) : null;
    $purchase_price = isset($_POST['purchase_price']) ? (float)$_POST['purchase_price'] : 0.0;
    $sale_price = isset($_POST['sale_price']) ? (float)$_POST['sale_price'] : 0.0;
    $purchase_date = isset($_POST['purchase_date']) ? trim($_POST['purchase_date']) : date('Y-m-d'); // Default to today

    $supplier_name = isset($_POST['supplier']) ? trim($_POST['supplier']) : null;

    // --- Server-side Validation ---
    $errors = [];

    if ($drug_id === 0) {
        $errors[] = "Please select a drug.";
    }
    if ($quantity <= 0) {
        $errors[] = "Quantity must be greater than 0.";
    }
    if (empty($purchase_date)) {
        $errors[] = "Purchase Date is required.";
    }
    
    // Validate expiry date: must be in the future if provided
    if ($expiry_date !== null && $expiry_date !== '') {
        $today = date('Y-m-d');
        if (strtotime($expiry_date) < strtotime($today)) {
            $errors[] = "Expiry Date cannot be in the past.";
        }
    } else {
        $expiry_date = null; // Ensure it's null if empty string
    }

    // Validate prices
    if ($purchase_price < 0) {
        $errors[] = "Purchase Price cannot be negative.";
    }
    if ($sale_price < 0) {
        $errors[] = "Sale Price cannot be negative.";
    }
    if ($sale_price < $purchase_price) {
        $errors[] = "Sale Price per unit cannot be less than Purchase Price per unit.";
    }

    // Validate string lengths (optional, but good practice)
    if ($batch_number !== null && strlen($batch_number) > 50) {
        $errors[] = "Batch Number cannot exceed 50 characters.";
    }
    if ($location !== null && strlen($location) > 100) {
        $errors[] = "Location cannot exceed 100 characters.";
    }
    if ($supplier_name !== null && strlen($supplier_name) > 100) {
        $errors[] = "Supplier Name cannot exceed 100 characters.";
    }


    if (!empty($errors)) {
        $feedbackMessage = '<p style="color: red;">Error: ' . implode('<br>', $errors) . '</p>';
        $feedbackStatus = 'error';
    } else {
        try {
            // Corrected SQL: Insert into 'supplier' column directly
            $stmt = $pdo->prepare("INSERT INTO Stock (drug_id, quantity, batch_number, expiry_date, location, purchase_price_per_unit, selling_price_per_unit, purchase_date, supplier)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $drug_id,
                $quantity,
                $batch_number,
                $expiry_date,
                $location,
                $purchase_price,
                $sale_price,
                $purchase_date,
                $supplier_name
            ]);
            $newStockId = $pdo->lastInsertId();
            $_SESSION['feedback_message'] = 'Stock batch added successfully! ID: ' . htmlspecialchars($newStockId);
            $_SESSION['feedback_status'] = 'success';
            header('Location: stock.php'); // Redirect to stock overview page
            exit();
        } catch (PDOException $e) {
            $feedbackMessage = '<p style="color: red;">Error adding stock: ' . htmlspecialchars($e->getMessage()) . '</p>';
            $feedbackStatus = 'error';
        }
    }
}

// --- Fetch Data for Drugs Dropdown ---
$drugs = [];
try {
    $stmt = $pdo->query("SELECT drug_id, drug_name, brand_name, dosage, unit_of_measure FROM Drugs ORDER BY drug_name");
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading drugs for dropdown: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Get current user info for display in header (already done at top)
// $currentUser = getCurrentUser(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Stock Batch - Woloma Pharmacy System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent with reports.php */
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

        /* Header Styles - Consistent with reports.php */
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

        /* Main content area - Consistent with reports.php */
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

        /* Message Styling (Feedback) - Consistent with reports.php */
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

        /* Form specific styles - Consistent with report section forms */
        .add-stock-form-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
        }
        .add-stock-form-container h2 {
            margin-bottom: 20px;
            color: #205072; /* Darker blue for headings */
            text-align: center;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em;
        }
        .add-stock-form-container label {
            display: block;
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .add-stock-form-container input[type="text"],
        .add-stock-form-container input[type="number"],
        .add-stock-form-container input[type="date"],
        .add-stock-form-container select {
            width: 100%; /* Full width */
            padding: 10px; /* Consistent padding */
            margin-bottom: 18px; /* Consistent margin */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box; /* Include padding in width */
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .add-stock-form-container button {
            width: 100%;
            padding: 12px; /* Consistent padding */
            background-color: #28a745; /* Green for add button */
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            margin-top: 10px;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .add-stock-form-container button:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        p.back-link { 
            text-align: center; 
            margin-top: 30px; /* Increased margin for consistency */
        }
        p.back-link a { 
            color: #007bff; 
            text-decoration: none; 
            font-weight: 600; /* Bolder for prominence */
            transition: color 0.2s ease;
        }
        p.back-link a:hover { 
            text-decoration: underline; 
            color: #0056b3;
        }

        /* Footer Styles - Consistent with reports.php */
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


        /* Responsive Adjustments - Consistent with reports.php */
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
            .add-stock-form-container {
                padding: 20px;
                width: auto; /* Allow form to take full width */
            }
            .add-stock-form-container input[type="text"],
            .add-stock-form-container input[type="number"],
            .add-stock-form-container input[type="date"],
            .add-stock-form-container select,
            .add-stock-form-container button {
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
        <h1>Add New Stock Batch</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <div class="add-stock-form-container">
            <h2>Add Stock Details</h2>
            <div class="message-container">
                <?php if (!empty($feedbackMessage)): ?>
                    <p class="message <?php echo $feedbackStatus; ?>"><?php echo $feedbackMessage; ?></p>
                <?php endif; ?>
            </div>

            <form action="add_stock.php" method="POST">
                <label for="drug_id">Drug:</label>
                <select id="drug_id" name="drug_id" required>
                    <option value="">Select Drug</option>
                    <?php foreach ($drugs as $drug): ?>
                        <option value="<?php echo htmlspecialchars($drug['drug_id']); ?>"
                            <?php echo ((isset($_POST['drug_id']) ? $_POST['drug_id'] : '') == $drug['drug_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($drug['drug_name'] . ' (' . (isset($drug['brand_name'])?$drug['brand_name']: 'N/A') . ') - ' . (isset($drug['dosage'])?$drug['dosage']: 'N/A') . ' ' . (isset($drug['unit_of_measure'])?$drug['unit_of_measure']: 'N/A')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="quantity">Quantity:</label>
                <input type="number" id="quantity" name="quantity" min="1" value="<?php echo htmlspecialchars(isset($_POST['quantity']) ? $_POST['quantity'] : ''); ?>" required>

                <label for="batch_number">Batch Number (Optional):</label>
                <input type="text" id="batch_number" name="batch_number" value="<?php echo htmlspecialchars(isset($_POST['batch_number']) ? $_POST['batch_number'] : ''); ?>" maxlength="50">

                <label for="expiry_date">Expiry Date (Optional):</label>
                <input type="date" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars(isset($_POST['expiry_date']) ? $_POST['expiry_date'] : ''); ?>">

                <label for="location">Location (e.g., Shelf A1, Refrigerator):</label>
                <input type="text" id="location" name="location" value="<?php echo htmlspecialchars(isset($_POST['location']) ? $_POST['location'] : ''); ?>" maxlength="100">

                <label for="purchase_price">Purchase Price per Unit:</label>
                <input type="number" id="purchase_price" name="purchase_price" step="0.01" min="0" value="<?php echo htmlspecialchars(isset($_POST['purchase_price']) ? $_POST['purchase_price'] : '0.00'); ?>" required>

                <label for="sale_price">Sale Price per Unit:</label>
                <input type="number" id="sale_price" name="sale_price" step="0.01" min="0" value="<?php echo htmlspecialchars(isset($_POST['sale_price']) ? $_POST['sale_price'] : '0.00'); ?>" required>

                <label for="purchase_date">Purchase Date:</label>
                <input type="date" id="purchase_date" name="purchase_date" value="<?php echo htmlspecialchars(isset($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d')); ?>" required>

                <label for="supplier">Supplier Name (Optional):</label>
                <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars(isset($_POST['supplier']) ? $_POST['supplier'] : ''); ?>" maxlength="100">

                <button type="submit">Add Stock Batch</button>
            </form>
        </div>
        <p class="back-link"><a href="stock.php">Back to Stock Overview</a></p>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
