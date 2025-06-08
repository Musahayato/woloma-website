<?php
// pharmacy_management_system/manage_drugs_stock.php
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

// --- Fetch Manufacturers (needed for Add Drug form dropdown) ---
$manufacturers = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("SELECT manufacturer_id, manufacturer_name FROM Manufacturers ORDER BY manufacturer_name ASC");
    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading manufacturers: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
}

// --- Fetch Categories (needed for Drug forms if you add categories there) ---
// Note: Categories were not in the original form, but often drugs have categories.
// Adding this for future potential use or if you adapt the drug add form.
$categories = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("SELECT category_id, category_name FROM Categories ORDER BY category_name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading categories: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
}

// --- Handle Form Submissions (POST requests for Admin actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- SERVER-SIDE ROLE ENFORCEMENT ---
    // Only Admin users are allowed to perform add actions on this page
    if ($currentUser['role'] !== 'Admin') {
        $_SESSION['feedback_message'] = "Unauthorized: You do not have permission to modify drug or stock data.";
        $_SESSION['feedback_status'] = "error";
        header('Location: manage_drugs_stock.php');
        exit();
    }
    // --- END SERVER-SIDE ROLE ENFORCEMENT ---

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $errors = array(); // PHP 6.9 compatible array()

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'add_drug':
                $drug_name = trim(isset($_POST['drug_name']) ? $_POST['drug_name'] : '');
                $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
                $manufacturer_id = (int)(isset($_POST['manufacturer_id']) ? $_POST['manufacturer_id'] : 0);
                $category_id = (int)(isset($_POST['category_id']) ? $_POST['category_id'] : 0); // Added for consistency
                $unit_of_measure = trim(isset($_POST['unit_of_measure']) ? $_POST['unit_of_measure'] : '');
                $dosage = trim(isset($_POST['dosage']) ? $_POST['dosage'] : '');
                $brand_name = trim(isset($_POST['brand_name']) ? $_POST['brand_name'] : '');


                // Validate inputs for adding drug
                if (empty($drug_name)) {
                    $errors[] = "Drug Name is required.";
                } elseif (strlen($drug_name) > 255) {
                    $errors[] = "Drug Name cannot exceed 255 characters.";
                }
                if ($manufacturer_id <= 0) {
                    $errors[] = "A valid Manufacturer is required.";
                }
                if ($category_id <= 0) {
                    $errors[] = "A valid Category is required.";
                }
                if (strlen($description) > 1000) {
                    $errors[] = "Description cannot exceed 1000 characters.";
                }
                if (strlen($unit_of_measure) > 50) {
                    $errors[] = "Unit of Measure cannot exceed 50 characters.";
                }
                if (strlen($dosage) > 50) {
                    $errors[] = "Dosage cannot exceed 50 characters.";
                }
                if (strlen($brand_name) > 100) {
                    $errors[] = "Brand Name cannot exceed 100 characters.";
                }


                if (empty($errors)) {
                    // Verify manufacturer_id exists
                    $stmt = $pdo->prepare("SELECT manufacturer_id FROM Manufacturers WHERE manufacturer_id = ?");
                    $stmt->execute(array($manufacturer_id)); // PHP 6.9 compatible array()
                    if (!$stmt->fetch()) {
                        throw new Exception("Selected manufacturer does not exist. Please select a valid manufacturer.");
                    }
                    // Verify category_id exists
                    $stmt = $pdo->prepare("SELECT category_id FROM Categories WHERE category_id = ?");
                    $stmt->execute(array($category_id)); // PHP 6.9 compatible array()
                    if (!$stmt->fetch()) {
                        throw new Exception("Selected category does not exist. Please select a valid category.");
                    }

                    // Check for duplicate drug name
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM Drugs WHERE drug_name = ?");
                    $stmtCheck->execute(array($drug_name)); // PHP 6.9 compatible array()
                    if ($stmtCheck->fetchColumn() > 0) {
                        throw new Exception("A drug with this name already exists. Please use a unique name.");
                    }

                    $stmt = $pdo->prepare("INSERT INTO Drugs (drug_name, brand_name, description, category_id, manufacturer_id, unit_of_measure, dosage) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute(array( // PHP 6.9 compatible array()
                        $drug_name,
                        !empty($brand_name) ? $brand_name : null,
                        !empty($description) ? $description : null,
                        $category_id,
                        $manufacturer_id,
                        !empty($unit_of_measure) ? $unit_of_measure : null,
                        !empty($dosage) ? $dosage : null
                    ));
                    $feedbackMessage = "Drug '" . htmlspecialchars($drug_name) . "' added successfully.";
                    $feedbackStatus = 'success';
                }
                break;

            case 'add_stock':
                $drug_id = (int)(isset($_POST['drug_id']) ? $_POST['drug_id'] : 0);
                $quantity = (int)(isset($_POST['quantity']) ? $_POST['quantity'] : 0);
                $selling_price = (float)(isset($_POST['selling_price']) ? $_POST['selling_price'] : 0.00); // Renamed from 'price' for clarity
                $expiry_date = trim(isset($_POST['expiry_date']) ? $_POST['expiry_date'] : '');
                $batch_number = trim(isset($_POST['batch_number']) ? $_POST['batch_number'] : '');
                $location = trim(isset($_POST['location']) ? $_POST['location'] : '');
                $purchase_price = (float)(isset($_POST['purchase_price']) ? $_POST['purchase_price'] : 0.00);
                $purchase_date = trim(isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '');
                $supplier = trim(isset($_POST['supplier']) ? $_POST['supplier'] : '');

                // Validate inputs for adding stock
                if ($drug_id <= 0) {
                    $errors[] = "Please select a drug.";
                }
                if ($quantity <= 0) {
                    $errors[] = "Quantity must be greater than 0.";
                }
                if ($selling_price <= 0) {
                    $errors[] = "Selling Price per unit must be greater than 0.";
                }
                if (empty($expiry_date)) {
                    $errors[] = "Expiry Date is required.";
                } else {
                    $today = date('Y-m-d');
                    if (strtotime($expiry_date) < strtotime($today)) {
                        $errors[] = "Expiry Date cannot be in the past.";
                    }
                }
                if (empty($batch_number)) {
                    $errors[] = "Batch Number is required.";
                } elseif (strlen($batch_number) > 50) {
                    $errors[] = "Batch Number cannot exceed 50 characters.";
                }
                if (strlen($location) > 100) {
                    $errors[] = "Location cannot exceed 100 characters.";
                }
                if ($purchase_price < 0) {
                    $errors[] = "Purchase Price cannot be negative.";
                }
                if ($selling_price < $purchase_price) {
                    $errors[] = "Selling Price per unit cannot be less than Purchase Price per unit.";
                }
                 if (empty($purchase_date)) {
                    $errors[] = "Purchase Date is required.";
                }
                if (strlen($supplier) > 100) {
                    $errors[] = "Supplier name cannot exceed 100 characters.";
                }

                if (empty($errors)) {
                    // Check if drug_id exists
                    $stmt = $pdo->prepare("SELECT drug_id FROM Drugs WHERE drug_id = ?");
                    $stmt->execute(array($drug_id)); // PHP 6.9 compatible array()
                    if (!$stmt->fetch()) {
                        throw new Exception("Selected drug does not exist.");
                    }

                    $stmt = $pdo->prepare("INSERT INTO Stock (drug_id, quantity, selling_price_per_unit, expiry_date, batch_number, location, purchase_price_per_unit, purchase_date, supplier)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute(array( // PHP 6.9 compatible array()
                        $drug_id,
                        $quantity,
                        $selling_price,
                        $expiry_date,
                        $batch_number,
                        !empty($location) ? $location : null,
                        $purchase_price,
                        $purchase_date,
                        !empty($supplier) ? $supplier : null
                    ));
                    $feedbackMessage = "Stock batch added successfully.";
                    $feedbackStatus = 'success';
                }
                break;
            // Removed update_drug, delete_drug, update_stock, delete_stock cases
            // as they are handled by dedicated pages (edit_drug.php, delete_drug.php, etc.)

            default:
                $errors[] = "Invalid action provided.";
                break;
        }

        if (!empty($errors)) {
            $pdo->rollBack();
            $feedbackMessage = "Error: " . implode('<br>', $errors);
            $feedbackStatus = 'error';
        } else {
            $pdo->commit();
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $feedbackMessage = "Error: " . htmlspecialchars($e->getMessage());
        $feedbackStatus = 'error';
    }

    // Set feedback for redirect
    $_SESSION['feedback_message'] = $feedbackMessage;
    $_SESSION['feedback_status'] = $feedbackStatus;
    header('Location: manage_drugs_stock.php'); // Redirect back to this page
    exit();
}

// --- Fetch data for display (GET requests) ---
// Fetch all drugs with their current available stock, average price, and manufacturer name
$drugs = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("
        SELECT
            d.drug_id,
            d.drug_name,
            d.brand_name,
            d.description,
            d.dosage,
            d.unit_of_measure,
            m.manufacturer_name,
            cat.category_name,
            SUM(CASE WHEN s.expiry_date >= CURDATE() THEN s.quantity ELSE 0 END) AS total_available_quantity,
            AVG(CASE WHEN s.expiry_date >= CURDATE() THEN s.selling_price_per_unit ELSE NULL END) AS average_current_price
        FROM
            Drugs d
        LEFT JOIN
            Stock s ON d.drug_id = s.drug_id
        LEFT JOIN
            Manufacturers m ON d.manufacturer_id = m.manufacturer_id
        LEFT JOIN
            Categories cat ON d.category_id = cat.category_id
        GROUP BY
            d.drug_id, d.drug_name, d.brand_name, d.description, d.dosage, d.unit_of_measure, m.manufacturer_name, cat.category_name
        ORDER BY
            d.drug_name ASC
    ");
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading drugs: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
}

// Fetch all individual stock entries with drug names
$stockEntries = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("
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
            s.supplier,
            s.created_at AS stock_added_date
        FROM
            Stock s
        JOIN
            Drugs d ON s.drug_id = d.drug_id
        ORDER BY
            d.drug_name ASC, s.expiry_date ASC
    ");
    $stockEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading stock entries: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
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
    <title>Manage Drugs & Stock - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* General Body and Container Styles - Consistent with other pages */
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
        .auth-status button.logout-btn { background-color: #dc3545; }
        .auth-status button.logout-btn:hover { background-color: #c82333; }

        /* Main content area - Consistent with other pages */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1200px; /* Increased max-width for tables */
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
            
            /* --- New: Flexbox for two-column layout --- */
            display: flex;
            flex-wrap: wrap; /* Allow wrapping to next row on small screens */
            gap: 25px; /* Gap between the two main columns */
            /* --- End New --- */
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
            /* --- New: Ensure message spans full width in flex container --- */
            flex-basis: 100%; 
            /* --- End New --- */
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

        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #205072; /* Consistent blue border */
            padding-bottom: 10px;
            /* --- New: Adjust margin-top --- */
            margin-top: 0; /* Reset default margin-top if using flex gap for spacing */
            /* --- End New --- */
        }
        .section-header h2 {
            color: #205072; /* Consistent header color */
            margin: 0;
            font-size: 1.6em;
        }
        .action-buttons a {
            background-color: #28a745; /* Green for add actions */
            color: white;
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .action-buttons a:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .action-buttons a:not(:last-child) { margin-right: 10px; }


        /* Form Styling (adapted for single column) */
        .form-container {
            background-color: #fdfdfd; /* Light background */
            padding: 30px;
            border-radius: 10px; /* Consistent rounded corners */
            margin-bottom: 0; /* Removed old margin-bottom */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
            /* --- New: Ensure form fills its column --- */
            width: 100%;
            box-sizing: border-box; /* Include padding in width */
            /* --- End New --- */
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
            display: flex; /* Changed to flex for single column layout */
            flex-direction: column;
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
            height: auto;
        }
        .form-container button {
            padding: 12px;
            background-color: #007bff; /* Primary blue for form buttons */
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            margin-top: 10px;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .form-container button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }


        /* Table Styling */
        table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 0; /* Removed old margin-top */
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        th, td {
            border: none; /* Remove individual cell borders */
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef; /* Only bottom border for rows */
            font-size: 0.9em;
        }
        th {
            background-color: #e9ecef; /* Light grey header */
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.85em;
        }
        tr:nth-child(even) { background-color: #fcfcfc; } /* Changed to even for better contrast */
        tr:last-child td { border-bottom: none; } /* No border on last row */
        tr:hover { background-color: #e2e6ea; }

        /* Specific table header/footer rounding */
        thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }


        .action-buttons-table a, .action-buttons-table button {
            padding: 6px 10px; /* Adjusted padding */
            margin-right: 5px;
            border: none;
            border-radius: 4px; /* Consistent rounding */
            cursor: pointer;
            text-decoration: none;
            font-size: 0.85em; /* Adjusted font size */
            transition: background-color 0.3s ease, transform 0.1s ease;
            display: inline-flex; /* For better alignment */
            align-items: center;
            justify-content: center;
            min-width: 60px; /* Ensure buttons have minimum width */
        }
        .action-buttons-table a.edit-btn { background-color: #ffc107; color: #333; }
        .action-buttons-table a.edit-btn:hover { background-color: #e0a800; transform: translateY(-1px); }
        .action-buttons-table button.delete-btn { background-color: #dc3545; color: white; }
        .action-buttons-table button.delete-btn:hover { background-color: #c82333; transform: translateY(-1px); }
        .action-buttons-table .view-btn { background-color: #17a2b8; color: white; }
        .action-buttons-table .view-btn:hover { background-color: #138496; transform: translateY(-1px); }

        .expired-stock {
            color: #dc3545; /* Red color for expired stock */
            font-weight: bold;
        }
        .low-stock {
            color: #ffc107; /* Orange/Warning color for low stock */
            font-weight: bold;
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
        @media (min-width: 993px) { /* Two-column layout for larger screens */
            .drugs-column-wrapper,
            .stock-column-wrapper {
                flex: 1 1 calc(50% - 12.5px); /* Take up roughly half width, considering the 25px gap */
                display: flex;
                flex-direction: column;
                gap: 25px; /* Gap between elements within each column */
            }
            /* Adjust margins if section-header or form-container had specific top margins that would clash with flex gap */
            .drugs-column-wrapper .section-header,
            .stock-column-wrapper .section-header {
                margin-top: 0; /* Reset explicit margins for sections within flex columns */
            }
        }

        @media (max-width: 992px) { /* Stack columns vertically on tablet/smaller desktop */
            .drugs-column-wrapper,
            .stock-column-wrapper {
                flex: 1 1 100%; /* Each column takes full width */
                min-width: unset;
                gap: 25px; /* Maintain gap between forms/tables within the stacked columns */
            }
            table {
                display: block; /* Make table scrollable */
                overflow-x: auto; /* Enable horizontal scroll */
                white-space: nowrap; /* Prevent content wrapping */
            }
        }

        @media (max-width: 768px) { /* Adjust for mobile */
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
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .section-header h2 {
                margin-bottom: 10px;
            }
            .action-buttons {
                width: 100%;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .action-buttons a {
                width: 100%;
                text-align: center;
            }
            .form-container {
                padding: 20px;
                width: auto;
            }
            footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Manage Drugs & Stock</h1>
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

        <div class="drugs-column-wrapper">
            <?php if ($currentUser['role'] === 'Admin'): ?>
            <div class="form-container">
                <h3>Add New Drug</h3>
                <form action="manage_drugs_stock.php" method="POST">
                    <input type="hidden" name="action" value="add_drug">
                    
                    <div class="form-group">
                        <label for="drug_name">Drug Name:</label>
                        <input type="text" id="drug_name" name="drug_name" value="<?php echo htmlspecialchars(isset($_POST['drug_name']) ? $_POST['drug_name'] : ''); ?>" required maxlength="255">
                    </div>
                    
                    <div class="form-group">
                        <label for="brand_name">Brand Name (Optional):</label>
                        <input type="text" id="brand_name" name="brand_name" value="<?php echo htmlspecialchars(isset($_POST['brand_name']) ? $_POST['brand_name'] : ''); ?>" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category:</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['category_id']); ?>"
                                    <?php echo ((isset($_POST['category_id']) ? $_POST['category_id'] : '') == $category['category_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="manufacturer_id">Manufacturer:</label>
                        <select id="manufacturer_id" name="manufacturer_id" required>
                            <option value="">Select Manufacturer</option>
                            <?php foreach ($manufacturers as $manufacturer): ?>
                                <option value="<?php echo htmlspecialchars($manufacturer['manufacturer_id']); ?>"
                                    <?php echo ((isset($_POST['manufacturer_id']) ? $_POST['manufacturer_id'] : '') == $manufacturer['manufacturer_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($manufacturer['manufacturer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="unit_of_measure">Unit of Measure (e.g., mg, ml):</label>
                        <input type="text" id="unit_of_measure" name="unit_of_measure" value="<?php echo htmlspecialchars(isset($_POST['unit_of_measure']) ? $_POST['unit_of_measure'] : ''); ?>" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="dosage">Dosage (e.g., 100mg, 5ml):</label>
                        <input type="text" id="dosage" name="dosage" value="<?php echo htmlspecialchars(isset($_POST['dosage']) ? $_POST['dosage'] : ''); ?>" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="description">Description (Optional):</label>
                        <textarea id="description" name="description" rows="3" maxlength="1000"><?php echo htmlspecialchars(isset($_POST['description']) ? $_POST['description'] : ''); ?></textarea>
                    </div>

                    <button type="submit">Add Drug</button>
                </form>
            </div>
            <?php endif; // End Admin-only forms ?>

            <div class="section-header">
                <h2>All Registered Drugs</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Brand</th>
                        <th>Manufacturer</th>
                        <th>Category</th>
                        <th>Dosage</th>
                        <th>Unit</th>
                        <th>Total Available Quantity</th>
                        <th>Avg. Selling Price</th>
                        <?php if ($currentUser['role'] === 'Admin'): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($drugs)): ?>
                        <tr><td colspan="<?php echo ($currentUser['role'] === 'Admin' ? 10 : 9); ?>" style="text-align: center;">No drugs registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($drugs as $drug): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($drug['drug_id']); ?></td>
                                <td><?php echo htmlspecialchars($drug['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['brand_name']) ? $drug['brand_name'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['manufacturer_name']) ? $drug['manufacturer_name'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['category_name']) ? $drug['category_name'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['dosage']) ? $drug['dosage'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['unit_of_measure']) ? $drug['unit_of_measure'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($drug['total_available_quantity']) ? $drug['total_available_quantity'] : 0); ?></td>
                                <td>ETB <?php echo number_format(isset($drug['average_current_price']) ? $drug['average_current_price'] : 0, 2); ?></td>
                                <?php if ($currentUser['role'] === 'Admin'): ?>
                                    <td class="action-buttons-table">
                                        <a href="edit_drug.php?id=<?php echo htmlspecialchars($drug['drug_id']); ?>" class="edit-btn">Edit</a>
                                        <form action="delete_drug.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this drug? This will fail if there is associated stock or sales items!');">
                                            <input type="hidden" name="action" value="delete_drug">
                                            <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug['drug_id']); ?>">
                                            <!-- CSRF token will need to be added here -->
                                            <button type="submit" class="delete-btn">Delete</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="stock-column-wrapper">
            <?php if ($currentUser['role'] === 'Admin'): ?>
            <div class="form-container">
                <h3>Add New Stock Batch</h3>
                <form action="manage_drugs_stock.php" method="POST">
                    <input type="hidden" name="action" value="add_stock">
                    
                    <div class="form-group">
                        <label for="drug_id_stock">Drug Name:</label>
                        <select id="drug_id_stock" name="drug_id" required>
                            <option value="">Select Drug</option>
                            <?php foreach ($drugs as $drug): ?>
                                <option value="<?php echo htmlspecialchars($drug['drug_id']); ?>"
                                    <?php echo ((isset($_POST['drug_id']) ? $_POST['drug_id'] : '') == $drug['drug_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($drug['drug_name'] . ' (' . (isset($drug['brand_name']) ? $drug['brand_name'] : 'N/A') . ') - ' . (isset($drug['dosage']) ? $drug['dosage'] : 'N/A') . ' ' . (isset($drug['unit_of_measure']) ? $drug['unit_of_measure'] : 'N/A')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Quantity:</label>
                        <input type="number" id="quantity" name="quantity" min="1" value="<?php echo htmlspecialchars(isset($_POST['quantity']) ? $_POST['quantity'] : ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="selling_price">Selling Price (per unit):</label>
                        <input type="number" id="selling_price" name="selling_price" min="0.01" step="0.01" value="<?php echo htmlspecialchars(isset($_POST['selling_price']) ? $_POST['selling_price'] : ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="purchase_price">Purchase Price per Unit:</label>
                        <input type="number" id="purchase_price" name="purchase_price" step="0.01" min="0" value="<?php echo htmlspecialchars(isset($_POST['purchase_price']) ? $_POST['purchase_price'] : '0.00'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date:</label>
                        <input type="date" id="expiry_date" name="expiry_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars(isset($_POST['expiry_date']) ? $_POST['expiry_date'] : ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="batch_number">Batch Number:</label>
                        <input type="text" id="batch_number" name="batch_number" value="<?php echo htmlspecialchars(isset($_POST['batch_number']) ? $_POST['batch_number'] : ''); ?>" required maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location (e.g., Shelf A1, Refrigerator) (Optional):</label>
                        <input type="text" id="location" name="location" value="<?php echo htmlspecialchars(isset($_POST['location']) ? $_POST['location'] : ''); ?>" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="purchase_date">Purchase Date:</label>
                        <input type="date" id="purchase_date" name="purchase_date" value="<?php echo htmlspecialchars(isset($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="supplier">Supplier Name (Optional):</label>
                        <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars(isset($_POST['supplier']) ? $_POST['supplier'] : ''); ?>" maxlength="100">
                    </div>

                    <button type="submit">Add Stock Batch</button>
                </form>
            </div>
            <?php endif; // End Admin-only forms ?>

            <div class="section-header">
                <h2>All Stock Entries</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Stock ID</th>
                        <th>Drug Name</th>
                        <th>Batch</th>
                        <th>Quantity</th>
                        <th>Loc.</th>
                        <th>Pur. Price</th>
                        <th>Sell. Price</th>
                        <th>Pur. Date</th>
                        <th>Exp. Date</th>
                        <th>Supplier</th>
                        <th>Added On</th>
                        <?php if ($currentUser['role'] === 'Admin'): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockEntries)): ?>
                        <tr><td colspan="<?php echo ($currentUser['role'] === 'Admin' ? 12 : 11); ?>" style="text-align: center;">No stock entries found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stockEntries as $stock):
                            $is_expired = strtotime($stock['expiry_date']) < time();
                            $is_low_stock = $stock['quantity'] < 10; // Example threshold for low stock
                        ?>
                            <tr class="<?php echo $is_expired ? 'expired-stock' : ''; ?> <?php echo (!$is_expired && $is_low_stock) ? 'low-stock' : ''; ?>">
                                <td><?php echo htmlspecialchars($stock['stock_id']); ?></td>
                                <td><?php echo htmlspecialchars($stock['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars(isset($stock['batch_number']) ? $stock['batch_number'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($stock['quantity']); ?></td>
                                <td><?php echo htmlspecialchars(isset($stock['location']) ? $stock['location'] : 'N/A'); ?></td>
                                <td>ETB <?php echo number_format(isset($stock['purchase_price_per_unit']) ? $stock['purchase_price_per_unit'] : 0, 2); ?></td>
                                <td>ETB <?php echo number_format(isset($stock['selling_price_per_unit']) ? $stock['selling_price_per_unit'] : 0, 2); ?></td>
                                <td><?php echo htmlspecialchars(isset($stock['purchase_date']) ? $stock['purchase_date'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($stock['expiry_date']); ?></td>
                                <td><?php echo htmlspecialchars(isset($stock['supplier']) ? $stock['supplier'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(isset($stock['stock_added_date']) ? $stock['stock_added_date'] : 'N/A'); ?></td>
                                <?php if ($currentUser['role'] === 'Admin'): ?>
                                    <td class="action-buttons-table">
                                        <a href="edit_stock.php?id=<?php echo htmlspecialchars($stock['stock_id']); ?>" class="edit-btn">Edit</a>
                                        <form action="delete_stock.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this stock entry?');">
                                            <input type="hidden" name="action" value="delete_stock">
                                            <input type="hidden" name="stock_id" value="<?php echo htmlspecialchars($stock['stock_id']); ?>">
                                            <!-- CSRF token will need to be added here -->
                                            <button type="submit" class="delete-btn">Delete</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
