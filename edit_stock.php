<?php
// pharmacy_management_system/edit_stock.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php'; // Using your consistent auth check

// --- Authorization Check ---
// Only allow 'Admin' or 'Pharmacist' roles to access this page
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], ['Admin', 'Pharmacist'])) {
    header('Location: staff_login.php'); // Redirect to login, not dashboard, for unauthorized access
    exit();
}

$pdo = getDbConnection(); // Use PDO as per your other files
$feedbackMessage = '';
$feedbackStatus = ''; // Added for styling messages (success/error/info)
$stockEntry = null; // Initialize stock entry data

// Get stock ID from URL (e.g., edit_stock.php?id=123)
$stockId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no stock ID provided or it's invalid, redirect back to stock list
if ($stockId === 0) {
    $_SESSION['feedback_message'] = "Invalid stock ID provided.";
    $_SESSION['feedback_status'] = "error";
    header('Location: stock.php'); // Redirect back to the main management page
    exit();
}

// --- Fetch existing stock entry data for display ---
try {
    $stmt = $pdo->prepare("
        SELECT
            s.stock_id,
            s.drug_id,
            d.drug_name,
            s.quantity,
            s.selling_price_per_unit,
            s.expiry_date,
            s.batch_number,
            s.location,
            s.purchase_price_per_unit,
            s.purchase_date,
            s.supplier
        FROM
            Stock s
        JOIN
            Drugs d ON s.drug_id = d.drug_id
        WHERE
            s.stock_id = ?
    ");
    $stmt->execute(array($stockId)); // Use array() for PHP 6.9 compatibility
    $stockEntry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stockEntry) {
        // If stock entry not found, redirect back to stock list
        $_SESSION['feedback_message'] = "Stock entry not found or does not exist.";
        $_SESSION['feedback_status'] = "error";
        header('Location: stock.php');
        exit();
    }
} catch (PDOException $e) {
    $feedbackMessage = "Error loading stock entry data: " . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
    $stockEntry = null; // Ensure no partial data is displayed
}

// --- Handle Form Submission for Updating Stock ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updatedDrugId = (int)(isset($_POST['drug_id']) ? $_POST['drug_id'] : 0);
    $updatedQuantity = (int)(isset($_POST['quantity']) ? $_POST['quantity'] : 0);
    $updatedSellingPrice = (float)(isset($_POST['selling_price_per_unit']) ? $_POST['selling_price_per_unit'] : 0.00); // Changed to selling_price_per_unit
    $updatedExpiryDate = trim(isset($_POST['expiry_date']) ? $_POST['expiry_date'] : '');
    $updatedBatchNumber = trim(isset($_POST['batch_number']) ? $_POST['batch_number'] : '');
    $updatedLocation = trim(isset($_POST['location']) ? $_POST['location'] : '');
    $updatedPurchasePrice = (float)(isset($_POST['purchase_price_per_unit']) ? $_POST['purchase_price_per_unit'] : 0.00);
    $updatedPurchaseDate = trim(isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '');
    $updatedSupplier = trim(isset($_POST['supplier']) ? $_POST['supplier'] : '');

    // --- Server-side Validation ---
    $errors = [];

    if ($updatedDrugId === 0) {
        $errors[] = "Please select a drug.";
    }
    // Allow quantity to be 0 for 'out of stock' status, but not negative
    if ($updatedQuantity < 0) {
        $errors[] = "Quantity cannot be negative.";
    }
    if ($updatedSellingPrice <= 0) {
        $errors[] = "Selling Price per unit must be greater than 0.";
    }
    if (empty($updatedExpiryDate)) {
        $errors[] = "Expiry Date is required.";
    } else {
        // Validate expiry date: must not be in the past
        $today = date('Y-m-d');
        if (strtotime($updatedExpiryDate) < strtotime($today)) {
            $errors[] = "Expiry Date cannot be in the past.";
        }
    }
    if (empty($updatedBatchNumber)) {
        $errors[] = "Batch Number is required.";
    } elseif (strlen($updatedBatchNumber) > 50) {
        $errors[] = "Batch Number cannot exceed 50 characters.";
    }
    if ($updatedLocation !== null && strlen($updatedLocation) > 100) {
        $errors[] = "Location cannot exceed 100 characters.";
    }
    if ($updatedPurchasePrice < 0) {
        $errors[] = "Purchase Price cannot be negative.";
    }
    if ($updatedSellingPrice < $updatedPurchasePrice) {
        $errors[] = "Selling Price per unit cannot be less than Purchase Price per unit.";
    }
    if (empty($updatedPurchaseDate)) {
        $errors[] = "Purchase Date is required.";
    }
    if ($updatedSupplier !== null && strlen($updatedSupplier) > 100) {
        $errors[] = "Supplier name cannot exceed 100 characters.";
    }


    if (!empty($errors)) {
        $feedbackMessage = "Error: " . implode('<br>', $errors);
        $feedbackStatus = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // Verify the new drug_id exists (if changed)
            $stmt = $pdo->prepare("SELECT drug_id FROM Drugs WHERE drug_id = ?");
            $stmt->execute(array($updatedDrugId)); // Use array() for PHP 6.9 compatibility
            if (!$stmt->fetch()) {
                throw new Exception("Selected drug does not exist. Please select a valid drug.");
            }

            $sql = "UPDATE Stock SET
                        drug_id = ?,
                        quantity = ?,
                        selling_price_per_unit = ?,
                        expiry_date = ?,
                        batch_number = ?,
                        location = ?,
                        purchase_price_per_unit = ?,
                        purchase_date = ?,
                        supplier = ?
                    WHERE stock_id = ?";

            $params = array( // Use array() for PHP 6.9 compatibility
                $updatedDrugId,
                $updatedQuantity,
                $updatedSellingPrice,
                $updatedExpiryDate,
                $updatedBatchNumber,
                !empty($updatedLocation) ? $updatedLocation : null, // Handle optional field
                $updatedPurchasePrice,
                $updatedPurchaseDate,
                !empty($updatedSupplier) ? $updatedSupplier : null, // Handle optional field
                $stockId
            );

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                $feedbackMessage = "Stock entry updated successfully!";
                $feedbackStatus = 'success';
            } else {
                $feedbackMessage = "No changes made to this stock entry.";
                $feedbackStatus = 'info';
            }

            $pdo->commit();

            // Re-fetch stock data to display latest info in form
            $stmt = $pdo->prepare("
                SELECT
                    s.stock_id,
                    s.drug_id,
                    d.drug_name,
                    s.quantity,
                    s.selling_price_per_unit,
                    s.expiry_date,
                    s.batch_number,
                    s.location,
                    s.purchase_price_per_unit,
                    s.purchase_date,
                    s.supplier
                FROM
                    Stock s
                JOIN
                    Drugs d ON s.drug_id = d.drug_id
                WHERE
                    s.stock_id = ?
            ");
            $stmt->execute(array($stockId)); // Use array() for PHP 6.9 compatibility
            $stockEntry = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $feedbackMessage = "Error updating stock: " . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// --- Fetch all drugs for the dropdown ---
$drugs = [];
try {
    $stmt = $pdo->query("SELECT drug_id, drug_name FROM Drugs ORDER BY drug_name ASC");
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<br>Error loading drugs for dropdown: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Stock - Woloma Pharmacy</title>
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
        .auth-status button.logout-btn { background-color: #dc3545; }
        .auth-status button.logout-btn:hover { background-color: #c82333; }


        /* Main content area - Consistent across pages */
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

        /* Message Styling (Feedback) - Consistent across pages */
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
            background-color: #e2f0ff; /* Changed from original for consistency with other pages */
            color: #0056b3; /* Changed from original for consistency with other pages */
            border-color: #007bff; /* Changed from original for consistency with other pages */
        }


        /* Form Styling - Consistent with other forms */
        .form-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
        }
        .form-container h3 {
            margin-top: 0;
            color: #205072; /* Darker blue for headings */
            text-align: center;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em;
        }
        .form-container form {
            display: grid;
            grid-template-columns: 1fr; /* Single column for consistency and responsiveness */
            gap: 15px;
        }
        .form-container .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-container label {
            margin-bottom: 8px; /* Consistent margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .form-container input[type="text"],
        .form-container input[type="number"],
        .form-container input[type="date"],
        .form-container textarea,
        .form-container select {
            width: 100%;
            padding: 10px;
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            box-sizing: border-box; /* Include padding in width */
            font-size: 0.9em;
            height: 40px; /* Standard height */
        }
        .form-container textarea {
            resize: vertical;
            min-height: 80px;
            height: auto; /* Allow height to adjust */
        }
        .form-container button {
            padding: 12px; /* Consistent padding */
            background-color: #007bff; /* Primary blue for save button */
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            margin-top: 15px; /* Adjusted margin */
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            width: 100%; /* Ensure button takes full width */
        }
        .form-container button:hover {
            background-color: #0056b3;
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

        /* Footer Styles - Consistent across pages */
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


        /* Responsive Adjustments - Consistent across pages */
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
            .form-container {
                padding: 20px;
                width: auto; /* Allow form to take full width */
            }
            .form-container input[type="text"],
            .form-container input[type="number"],
            .form-container input[type="date"],
            .form-container textarea,
            .form-container select,
            .form-container button {
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
        <h1>Edit Stock Entry</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
            <a href="staff_dashboard.php">Back to Dashboard</a>
            <form action="logout.php" method="POST">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <div class="form-container">
            <h3>Edit Stock for: <?php echo htmlspecialchars(isset($stockEntry['drug_name']) ? $stockEntry['drug_name'] : 'N/A'); ?> (Batch: <?php echo htmlspecialchars(isset($stockEntry['batch_number']) ? $stockEntry['batch_number'] : 'N/A'); ?>)</h3>
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo $feedbackStatus; ?>"><?php echo $feedbackMessage; ?></p>
            <?php endif; ?>

            <?php if ($stockEntry): ?>
                <form action="edit_stock.php?id=<?php echo htmlspecialchars($stockEntry['stock_id']); ?>" method="POST">
                    <input type="hidden" name="stock_id" value="<?php echo htmlspecialchars($stockEntry['stock_id']); ?>">

                    <div class="form-group">
                        <label for="drug_id">Drug Name:</label>
                        <select id="drug_id" name="drug_id" required>
                            <option value="">Select Drug</option>
                            <?php foreach ($drugs as $drug): ?>
                                <option value="<?php echo htmlspecialchars($drug['drug_id']); ?>"
                                    <?php echo ((isset($_POST['drug_id']) ? $_POST['drug_id'] : (isset($stockEntry['drug_id']) ? $stockEntry['drug_id'] : '')) == $drug['drug_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($drug['drug_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity:</label>
                        <input type="number" id="quantity" name="quantity" min="0" value="<?php echo htmlspecialchars(isset($_POST['quantity']) ? $_POST['quantity'] : (isset($stockEntry['quantity']) ? $stockEntry['quantity'] : '')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="selling_price_per_unit">Selling Price (per unit):</label>
                        <input type="number" id="selling_price_per_unit" name="selling_price_per_unit" min="0.01" step="0.01" value="<?php echo htmlspecialchars(isset($_POST['selling_price_per_unit']) ? $_POST['selling_price_per_unit'] : (isset($stockEntry['selling_price_per_unit']) ? $stockEntry['selling_price_per_unit'] : '')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="expiry_date">Expiry Date:</label>
                        <input type="date" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars(isset($_POST['expiry_date']) ? $_POST['expiry_date'] : (isset($stockEntry['expiry_date']) ? $stockEntry['expiry_date'] : '')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="batch_number">Batch Number:</label>
                        <input type="text" id="batch_number" name="batch_number" value="<?php echo htmlspecialchars(isset($_POST['batch_number']) ? $_POST['batch_number'] : (isset($stockEntry['batch_number']) ? $stockEntry['batch_number'] : '')); ?>" required maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="location">Location (Optional):</label>
                        <input type="text" id="location" name="location" value="<?php echo htmlspecialchars(isset($_POST['location']) ? $_POST['location'] : (isset($stockEntry['location']) ? $stockEntry['location'] : '')); ?>" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="purchase_price_per_unit">Purchase Price (per unit):</label>
                        <input type="number" id="purchase_price_per_unit" name="purchase_price_per_unit" step="0.01" min="0" value="<?php echo htmlspecialchars(isset($_POST['purchase_price_per_unit']) ? $_POST['purchase_price_per_unit'] : (isset($stockEntry['purchase_price_per_unit']) ? $stockEntry['purchase_price_per_unit'] : '0.00')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="purchase_date">Purchase Date:</label>
                        <input type="date" id="purchase_date" name="purchase_date" value="<?php echo htmlspecialchars(isset($_POST['purchase_date']) ? $_POST['purchase_date'] : (isset($stockEntry['purchase_date']) ? $stockEntry['purchase_date'] : date('Y-m-d'))); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="supplier">Supplier Name (Optional):</label>
                        <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars(isset($_POST['supplier']) ? $_POST['supplier'] : (isset($stockEntry['supplier']) ? $stockEntry['supplier'] : '')); ?>" maxlength="100">
                    </div>

                    <button type="submit">Save Changes</button>
                </form>
            <?php else: ?>
                <p>Stock entry data could not be loaded or stock entry does not exist.</p>
            <?php endif; ?>
        </div>
        <p class="back-link"><a href="manage_drugs_stock">Back to Stock Overview</a></p>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
