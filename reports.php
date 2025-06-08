<?php
// pharmacy_management_system/reports.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// Restrict access: Both Pharmacists and Admins can view reports
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], array('Pharmacist', 'Admin'))) { // PHP 6.9 compatible array()
    header('Location: staff_login.php');
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = '';

// Define tax rate for consistent calculations across reports
define('TAX_RATE', 0.00);

// --- Report Filters (used for all sales sections) ---
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Default to start of current month
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default to today
$selectedSalespersonId = isset($_GET['salesperson_id']) ? (int)$_GET['salesperson_id'] : 0;
$selectedCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$selectedPaymentMethod = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$selectedOrderType = isset($_GET['order_type']) ? $_GET['order_type'] : ''; // New filter for order type


// --- Sales Data Variables (Unified Source) ---
$detailedSales = array(); // Primary array for all sales transactions fetched (PHP 6.9 compatible)
$salesSummary = array(); // For daily sales summary derived from detailedSales (PHP 6.9 compatible)
$totalSalesAmount = 0; // For overall daily sales summary (from salesSummary)

$detailedSalesAggregate = array( // Summary for the detailed transactions section (PHP 6.9 compatible)
    'total_sales_amount' => 0, // This will be the final amount (after discount, incl. tax)
    'total_discount_amount' => 0,
    'total_tax_amount' => 0,
    'net_sales_amount' => 0, // total_amount from DB (after discount, before tax)
    'total_amount_paid' => 0,
    'num_transactions' => 0,
    'num_items_sold' => 0
);


// --- Low Stock Report Variables ---
$lowStockThreshold = isset($_GET['low_stock_threshold']) ? (int)$_GET['low_stock_threshold'] : 10; // Default threshold for low stock (PHP 6.9 compatible)
if ($lowStockThreshold <= 0) { // Ensure threshold is at least 1
    $lowStockThreshold = 1;
}
$lowStockDrugs = array(); // PHP 6.9 compatible array()

// --- Expired/Expiring Soon Stock Variables ---
$expiringSoonDays = isset($_GET['expiring_soon_days']) ? (int)$_GET['expiring_soon_days'] : 90; // Drugs expiring within the next 90 days (PHP 6.9 compatible)
if ($expiringSoonDays < 0) {
    $expiringSoonDays = 0; // Don't allow negative days
}
$expiredAndExpiringStock = array(); // PHP 6.9 compatible array()

// --- Fetch Filter Options (Salespersons, Customers) ---
$salespersons = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("SELECT user_id, full_name FROM Users ORDER BY full_name");
    $salespersons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading salespersons for detailed report: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

$allCustomers = array(); // PHP 6.9 compatible array()
try {
    $stmt = $pdo->query("SELECT customer_id, CONCAT(first_name, ' ', last_name) AS customer_name FROM Customers ORDER BY customer_name ASC");
    $allCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading all customers for filter: ' . htmlspecialchars($e->getMessage()) . '</p>';
}


// --- Fetch All Sales Data (Unified Logic) ---
try {
    // Ensure dates are in correct format and valid
    if (!strtotime($startDate) || !strtotime($endDate)) {
        throw new Exception("Invalid date format for date range.");
    }
    if (strtotime($startDate) > strtotime($endDate)) {
        throw new Exception("Start date cannot be after end date for sales reports.");
    }

    // Build SQL query for all sales data, applying filters from both sections
    $sqlAllSales = "SELECT
                s.sale_id,
                s.sale_date,
                s.total_amount,        -- This is the subtotal AFTER discount, BEFORE tax from DB
                s.discount_amount,
                s.amount_paid,
                s.payment_status,
                s.notes,
                s.order_type,          -- Include order_type
                s.order_status,        -- Include order_status
                u.full_name AS salesperson_name,
                c.first_name AS customer_first_name,
                c.last_name AS customer_last_name,
                c.phone_number AS customer_phone
            FROM Sales s
            LEFT JOIN Users u ON s.user_id = u.user_id
            LEFT JOIN Customers c ON s.customer_id = c.customer_id
            WHERE s.sale_date BETWEEN ? AND ? "; // Date range filter

    $paramsAllSales = array($startDate . ' 00:00:00', $endDate . ' 23:59:59'); // PHP 6.9 compatible array()

    // Apply salesperson filter if set
    if ($selectedSalespersonId > 0) {
        $sqlAllSales .= " AND s.user_id = ? ";
        $paramsAllSales[] = $selectedSalespersonId;
    }
    // Apply customer filter if set
    if ($selectedCustomerId > 0) {
        $sqlAllSales .= " AND s.customer_id = ? ";
        $paramsAllSales[] = $selectedCustomerId;
    }
    // Apply payment method filter if set
    if (!empty($selectedPaymentMethod)) {
        $sqlAllSales .= " AND s.payment_status = ? ";
        $paramsAllSales[] = $selectedPaymentMethod;
    }
    // Apply order type filter if set
    if (!empty($selectedOrderType)) {
        $sqlAllSales .= " AND s.order_type = ? ";
        $paramsAllSales[] = $selectedOrderType;
    }


    $sqlAllSales .= " ORDER BY s.sale_date DESC";

    $stmtAllSales = $pdo->prepare($sqlAllSales);
    $stmtAllSales->execute($paramsAllSales);
    $detailedSales = $stmtAllSales->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summaries from the unified detailedSales results
    foreach ($detailedSales as $sale) {
        // Calculate tax and grand total for each sale
        $saleTotalBeforeTax = $sale['total_amount']; // This is already after discount
        $saleTaxAmount = $saleTotalBeforeTax * TAX_RATE;
        $saleGrandTotalWithTax = $saleTotalBeforeTax + $saleTaxAmount;

        // Populate detailed sales aggregate summary
        $detailedSalesAggregate['num_transactions']++;
        $detailedSalesAggregate['total_discount_amount'] += $sale['discount_amount'];
        $detailedSalesAggregate['total_amount_paid'] += $sale['amount_paid'];
        $detailedSalesAggregate['total_sales_amount'] += $saleGrandTotalWithTax;
        $detailedSalesAggregate['total_tax_amount'] += $saleTaxAmount;
        $detailedSalesAggregate['net_sales_amount'] += $saleTotalBeforeTax;

        // Count items sold for each sale
        $stmtItemsCount = $pdo->prepare("SELECT SUM(quantity_sold) FROM SaleItems WHERE sale_id = ?");
        $stmtItemsCount->execute(array($sale['sale_id'])); // PHP 6.9 compatible array()
        $num_items_in_sale = $stmtItemsCount->fetchColumn();
        if ($num_items_in_sale === false) { // Handle case where no items are found for sale_id
            $num_items_in_sale = 0;
        }
        $detailedSalesAggregate['num_items_sold'] += $num_items_in_sale;


        // Aggregate for daily summary (for the first report section)
        $saleDay = date('Y-m-d', strtotime($sale['sale_date']));
        if (!isset($salesSummary[$saleDay])) {
            $salesSummary[$saleDay] = array( // PHP 6.9 compatible array()
                'sale_day' => $saleDay,
                'daily_total_amount_with_tax' => 0,
                'daily_total_sales' => 0
            );
        }
        $salesSummary[$saleDay]['daily_total_amount_with_tax'] += $saleGrandTotalWithTax;
        $salesSummary[$saleDay]['daily_total_sales']++;

    }
    // Sort salesSummary by date
    ksort($salesSummary);

    // Calculate overall total sales amount for the top summary (from salesSummary)
    foreach ($salesSummary as $day) {
        $totalSalesAmount += $day['daily_total_amount_with_tax'];
    }

} catch (Exception $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading sales reports: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
}


// --- Fetch Low Stock Report Data ---
try {
    $stmt = $pdo->prepare("
        SELECT
            d.drug_id,
            d.drug_name,
            SUM(s.quantity) AS current_stock,
            m.manufacturer_name
        FROM
            Drugs d
        JOIN
            Stock s ON d.drug_id = s.drug_id
        LEFT JOIN
            Manufacturers m ON d.manufacturer_id = m.manufacturer_id
        WHERE
            s.expiry_date >= CURDATE() -- Only consider non-expired stock
        GROUP BY
            d.drug_id, d.drug_name, m.manufacturer_name
        HAVING
            current_stock <= ?
        ORDER BY
            current_stock ASC, d.drug_name ASC
    ");
    $stmt->execute(array($lowStockThreshold)); // PHP 6.9 compatible array()
    $lowStockDrugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading low stock report: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $feedbackStatus = 'error';
}

// --- Fetch Expired/Expiring Soon Stock Data ---
try {
    $stmt = $pdo->prepare("
        SELECT
            s.stock_id,
            d.drug_name,
            s.quantity,
            s.selling_price_per_unit,
            s.expiry_date,
            s.batch_number,
            m.manufacturer_name
        FROM
            Stock s
        JOIN
            Drugs d ON s.drug_id = d.drug_id
        LEFT JOIN
            Manufacturers m ON d.manufacturer_id = m.manufacturer_id
        WHERE
            s.expiry_date <= CURDATE() OR s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY
            s.expiry_date ASC, d.drug_name ASC
    ");
    $stmt->execute(array($expiringSoonDays)); // PHP 6.9 compatible array()
    $expiredAndExpiringStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $feedbackMessage .= '<p style="color: red;">Error loading expired/expiring stock report: ' . htmlspecialchars($e->getMessage()) . '</p>';
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
    <title>Reports - Woloma Pharmacy Staff</title>
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


        /* Main content area */
        main {
            flex-grow: 1; /* Allows main content to take up available space */
            padding: 25px;
            max-width: 1200px;
            margin: 25px auto;
            background-color: #fff;
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            padding-top: 80px; /* Account for fixed header */
        }
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

        .report-section {
            margin-bottom: 40px;
            padding: 25px; /* Increased padding */
            border: 1px solid #e0e0e0; /* Lighter, consistent border */
            border-radius: 10px; /* Consistent rounded corners */
            background-color: #fdfdfd; /* Light background */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
        }
        .report-section h2 {
            color: #205072; /* Darker blue for headings */
            margin-top: 0;
            border-bottom: 2px solid #e9ecef; /* Stronger border */
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.6em; /* Slightly larger heading */
        }

        .filter-form-grid { /* Renamed for clarity for sales summary filters */
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on small screens */
            gap: 15px;
            margin-bottom: 25px; /* Increased margin */
            align-items: flex-end;
            background-color: #e9f5ff;
            padding: 18px; /* Increased padding */
            border-radius: 10px; /* Consistent rounding */
            border: 1px solid #cceeff;
        }
        .filter-form-grid .form-group {
            display: flex;
            flex-direction: column;
            flex-grow: 1; /* Allow items to grow to fill space */
            min-width: 150px; /* Minimum width for form groups */
        }
        .filter-form-grid label {
            margin-bottom: 8px; /* Increased margin */
            font-weight: 600; /* Bolder label */
            color: #555;
            font-size: 0.95em;
        }
        .filter-form-grid input[type="date"],
        .filter-form-grid input[type="text"],
        .filter-form-grid input[type="number"], /* Added for low stock threshold */
        .filter-form-grid select { /* Apply styles to text input and select too */
            padding: 10px; /* Increased padding */
            border: 1px solid #c0c0c0; /* Consistent border */
            border-radius: 6px; /* Consistent rounding */
            height: 40px; /* Standard height */
            box-sizing: border-box; /* Ensures consistent height */
            font-size: 0.9em;
            width: 100%; /* Take full width of its flex item */
        }
        .filter-form-grid button {
            padding: 10px 18px; /* Adjusted padding */
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 6px; /* Consistent rounding */
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease, transform 0.1s ease, box-shadow 0.2s ease;
            height: 40px; /* Standard height */
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0; /* Prevent button from shrinking */
        }
        .filter-form-grid button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* General table styles for all tables in reports.php */
        table {
            width: 100%;
            border-collapse: separate; /* For rounded corners */
            border-spacing: 0;
            margin-top: 25px; /* Increased margin */
            border-radius: 8px; /* Rounded corners for the whole table */
            overflow: hidden; /* Ensures corners are respected */
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
        }
        th, td {
            border: none; /* Remove individual cell borders */
            padding: 12px; /* Increased padding */
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
        tbody tr:nth-child(even) { /* Changed to even for better contrast */
            background-color: #fcfcfc;
        }
        tbody tr:last-child td {
            border-bottom: none; /* No border on last row */
        }
        thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        tbody tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        tbody tr:last-child td:last-child { border-bottom-right-radius: 8px; }
        
        .report-summary-box {
            background-color: #e9f5ff;
            border: 1px solid #cceeff;
            padding: 20px; /* Increased padding */
            border-radius: 10px; /* Consistent rounding */
            text-align: center;
            font-size: 1.3em; /* Slightly larger */
            font-weight: bold;
            color: #205072; /* Darker blue */
            margin-top: 25px; /* Increased margin */
        }
        .report-summary-box span {
            font-size: 1.7em; /* Larger amount */
            color: #28a745; /* Green for total amount */
        }

        .summary-grid { /* For detailed sales summary grid */
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-top: 20px;
            margin-bottom: 20px;
        }
        .summary-item {
            background-color: #eaf5ff; /* Lighter blue */
            padding: 15px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #cceeff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .summary-item strong {
            display: block;
            font-size: 1.1em;
            color: #205072; /* Darker blue */
            margin-bottom: 5px;
        }
        .summary-item span {
            font-size: 1.6em;
            font-weight: bold;
            color: #007bff; /* Primary blue */
        }
        .summary-item.total-sales span {
            color: #28a745; /* Green for total sales */
        }
        .summary-item.total-discount span {
            color: #dc3545; /* Red for discount */
        }


        .low-stock-alert { background-color: #fff8e1; color: #a07a00; border-color: #ffe082; } /* Softer yellow */
        .expired-stock-alert { background-color: #fcebeb; color: #a72828; border-color: #f5c6cb; } /* Softer red */
        .expiring-soon-alert { background-color: #e0f2f7; color: #1565c0; border-color: #a7d9eb; } /* Softer blue */

        /* Specific styles for Detailed Sales table */
        .detailed-sales-table td.amount {
            text-align: right;
            font-weight: bold;
        }
        .detailed-sales-table .button { /* Style for view receipt button */
            background-color: #007bff;
            color: white;
            padding: 5px 10px;
            font-size: 0.8em;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block; /* Ensure button-like behavior */
            transition: background-color 0.2s ease, transform 0.1s ease;
        }
        .detailed-sales-table .button:hover {
            background-color: #0056b3;
            transform: translateY(-1px);
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
            .detailed-sales-table, .report-items-table { /* Apply to all tables that might overflow */
                display: block; /* Make table scrollable */
                overflow-x: auto; /* Enable horizontal scroll */
                white-space: nowrap; /* Prevent content wrapping */
            }
            .detailed-sales-table tbody, .detailed-sales-table thead, .detailed-sales-table tr, .detailed-sales-table th, .detailed-sales-table td {
                display: block; /* For better mobile table rendering, can remove if horizontal scroll is preferred */
            }
            .detailed-sales-table thead {
                display: none; /* Hide header on mobile if stacking rows is desired */
            }
            .detailed-sales-table tbody tr {
                margin-bottom: 15px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
            }
            .detailed-sales-table td {
                text-align: right;
                border-bottom: 1px dashed #e9ecef;
                position: relative;
                padding-left: 50%; /* Space for pseudo-element label */
            }
            .detailed-sales-table td::before {
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
            .detailed-sales-table td.amount::before {
                width: auto; /* For amount labels, allow natural width */
            }

            .summary-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); /* Allow smaller items on smaller screens */
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
            .filter-form-grid { /* Applies to all filter forms */
                flex-direction: column;
                align-items: stretch;
            }
            .filter-form-grid .form-group,
            .filter-form-grid input,
            .filter-form-grid select,
            .filter-form-grid button {
                width: 100%;
                min-width: unset; /* Remove min-width on small screens */
            }
            .report-section {
                padding: 15px;
            }
            th, td {
                padding: 8px; /* Reduce table padding on small screens */
                font-size: 0.8em;
            }
            .report-summary-box {
                font-size: 1em;
            }
            .report-summary-box span {
                font-size: 1.4em;
            }
            .summary-grid {
                grid-template-columns: 1fr; /* Stack summary items vertically on small screens */
            }
            .detailed-sales-table td {
                 padding-left: 10px; /* Reset padding for smaller screens if stacking */
            }
            .detailed-sales-table td::before {
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
            .detailed-sales-table td.amount::before {
                width: auto;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Pharmacy Reports</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?> (Role: <?php echo htmlspecialchars($currentUser['role']); ?>)!</span>
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

        <div class="report-section">
            <h2>Sales Summary Report</h2>
            <form action="reports.php" method="GET" class="filter-form-grid">
                <div class="form-group">
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" required>
                </div>
                <div class="form-group">
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" required>
                </div>
                <div class="form-group">
                    <label for="salesperson_id">Salesperson:</label>
                    <select id="salesperson_id" name="salesperson_id">
                        <option value="0">All Salespersons</option>
                        <?php foreach ($salespersons as $person): ?>
                            <option value="<?php echo htmlspecialchars($person['user_id']); ?>"
                                <?php echo ($selectedSalespersonId == $person['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($person['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="customer_id">Customer:</label>
                    <select id="customer_id" name="customer_id">
                        <option value="0">All Customers</option>
                        <?php foreach ($allCustomers as $customer): ?>
                            <option value="<?php echo htmlspecialchars($customer['customer_id']); ?>"
                                <?php echo ($selectedCustomerId == $customer['customer_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_method">Payment Method:</label>
                    <select id="payment_method" name="payment_method">
                        <option value="">All Methods</option>
                        <option value="Cash" <?php echo ($selectedPaymentMethod == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                        <option value="Mobile Transfer" <?php echo ($selectedPaymentMethod == 'Mobile Transfer') ? 'selected' : ''; ?>>Mobile Transfer</option>
                        <option value="Bank Deposit" <?php echo ($selectedPaymentMethod == 'Bank Deposit') ? 'selected' : ''; ?>>Bank Deposit</option>
                        <option value="Credit Card" <?php echo ($selectedPaymentMethod == 'Credit Card') ? 'selected' : ''; ?>>Credit Card</option>
                        <option value="Other" <?php echo ($selectedPaymentMethod == 'Other') ? 'selected' : ''; ?>>Other</option>
                        <option value="Verified" <?php echo ($selectedPaymentMethod == 'Verified') ? 'selected' : ''; ?>>Online (Verified)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="order_type">Order Type:</label>
                    <select id="order_type" name="order_type">
                        <option value="">All Types</option>
                        <option value="in-person" <?php echo ($selectedOrderType == 'in-person') ? 'selected' : ''; ?>>In-Person</option>
                        <option value="online" <?php echo ($selectedOrderType == 'online') ? 'selected' : ''; ?>>Online</option>
                    </select>
                </div>
                <button type="submit">Generate Reports</button>
            </form>

            <?php if (empty($salesSummary)): ?>
                <p class="message info">No sales data found for the selected date range and filters.</p>
            <?php else: ?>
                <div class="report-summary-box">
                    Total Sales (<?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?>): <span>ETB <?php echo number_format($totalSalesAmount, 2); ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Sales Amount (Incl. Tax)</th>
                            <th>Number of Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salesSummary as $day): ?>
                            <tr>
                                <td data-label="Date"><?php echo htmlspecialchars($day['sale_day']); ?></td>
                                <td data-label="Total Sales Amount" class="amount">ETB <?php echo number_format($day['daily_total_amount_with_tax'], 2); ?></td>
                                <td data-label="Number of Sales"><?php echo htmlspecialchars($day['daily_total_sales']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="report-section">
            <h2>Detailed Sales Transactions</h2>
            <?php if (empty($detailedSales)): ?>
                <p class="message info">No detailed sales transactions found for the selected filters.</p>
            <?php else: ?>
                <div class="summary-grid">
                    <div class="summary-item total-sales">
                        <strong>Overall Sales Amount (Incl. Tax):</strong>
                        <span>ETB <?php echo number_format($detailedSalesAggregate['total_sales_amount'], 2); ?></span>
                    </div>
                    <div class="summary-item total-discount">
                        <strong>Total Discount Given:</strong>
                        <span>ETB <?php echo number_format($detailedSalesAggregate['total_discount_amount'], 2); ?></span>
                    </div>
                    <div class="summary-item">
                        <strong>Total Tax Collected (<?php echo (TAX_RATE * 100); ?>%):</strong>
                        <span>ETB <?php echo number_format($detailedSalesAggregate['total_tax_amount'], 2); ?></span>
                    </div>
                     <div class="summary-item">
                        <strong>Net Sales Amount (Excl. Tax, After Discount):</strong>
                        <span>ETB <?php echo number_format($detailedSalesAggregate['net_sales_amount'], 2); ?></span>
                    </div>
                    <div class="summary-item">
                        <strong>Total Amount Received:</strong>
                        <span>ETB <?php echo number_format($detailedSalesAggregate['total_amount_paid'], 2); ?></span>
                    </div>
                    <div class="summary-item">
                        <strong>Number of Transactions:</strong>
                        <span><?php echo htmlspecialchars($detailedSalesAggregate['num_transactions']); ?></span>
                    </div>
                    <div class="summary-item">
                        <strong>Total Items Sold:</strong>
                        <span><?php echo htmlspecialchars($detailedSalesAggregate['num_items_sold']); ?></span>
                    </div>
                </div>

                <table class="detailed-sales-table">
                    <thead>
                        <tr>
                            <th>Sale ID</th>
                            <th>Date & Time</th>
                            <th>Salesperson</th>
                            <th>Customer</th>
                            <th style="text-align: right;">Subtotal (Excl. Tax)</th>
                            <th style="text-align: right;">Discount</th>
                            <th style="text-align: right;">Tax (<?php echo (TAX_RATE * 100); ?>%)</th>
                            <th style="text-align: right;">Grand Total (Incl. Tax)</th>
                            <th style="text-align: right;">Amount Paid</th>
                            <th>Payment Method</th>
                            <th>Order Type</th>
                            <th>Order Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailedSales as $sale):
                            $saleTotalBeforeTax = $sale['total_amount']; // This is already after discount
                            $saleTaxAmount = $saleTotalBeforeTax * TAX_RATE;
                            $saleGrandTotalWithTax = $saleTotalBeforeTax + $saleTaxAmount;
                        ?>
                            <tr>
                                <td data-label="Sale ID"><?php echo htmlspecialchars($sale['sale_id']); ?></td>
                                <td data-label="Date & Time"><?php echo date('Y-m-d H:i A', strtotime($sale['sale_date'])); ?></td>
                                <td data-label="Salesperson"><?php echo htmlspecialchars($sale['salesperson_name'] ? $sale['salesperson_name'] : 'Online System'); ?></td>
                                <td data-label="Customer"><?php echo htmlspecialchars($sale['customer_first_name'] . ' ' . $sale['customer_last_name'] . ($sale['customer_phone'] ? ' (' . $sale['customer_phone'] . ')' : '')); ?></td>
                                <td data-label="Subtotal (Excl. Tax)" class="amount">ETB <?php echo number_format($saleTotalBeforeTax, 2); ?></td>
                                <td data-label="Discount" class="amount">ETB <?php echo number_format($sale['discount_amount'], 2); ?></td>
                                <td data-label="Tax (<?php echo (TAX_RATE * 100); ?>%)" class="amount">ETB <?php echo number_format($saleTaxAmount, 2); ?></td>
                                <td data-label="Grand Total (Incl. Tax)" class="amount">ETB <?php echo number_format($saleGrandTotalWithTax, 2); ?></td>
                                <td data-label="Amount Paid" class="amount">ETB <?php echo number_format($sale['amount_paid'], 2); ?></td>
                                <td data-label="Payment Method"><?php echo htmlspecialchars($sale['payment_status']); ?></td>
                                <td data-label="Order Type"><?php echo htmlspecialchars(ucfirst($sale['order_type'])); ?></td>
                                <td data-label="Order Status"><?php echo htmlspecialchars($sale['order_status']); ?></td>
                                <td data-label="Actions">
                                    <a href="generate_receipt.php?sale_id=<?php echo htmlspecialchars($sale['sale_id']); ?>" target="_blank" class="button">View Receipt</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="report-section">
            <h2>Low Stock Alert</h2>
            <form action="reports.php" method="GET" class="filter-form-grid">
                <div class="form-group">
                    <label for="low_stock_threshold">Threshold (Quantity &le;):</label>
                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="1" value="<?php echo htmlspecialchars($lowStockThreshold); ?>" required>
                </div>
                 <!-- Preserve other filters when changing low stock threshold -->
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                <input type="hidden" name="salesperson_id" value="<?php echo htmlspecialchars($selectedSalespersonId); ?>">
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($selectedCustomerId); ?>">
                <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($selectedPaymentMethod); ?>">
                <input type="hidden" name="order_type" value="<?php echo htmlspecialchars($selectedOrderType); ?>">
                
                <button type="submit">Refresh Low Stock Report</button>
            </form>

            <?php if (empty($lowStockDrugs)): ?>
                <p class="message success">No drugs are currently below the low stock threshold of <?php echo htmlspecialchars($lowStockThreshold); ?> units.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Drug Name</th>
                            <th>Manufacturer</th>
                            <th>Current Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockDrugs as $drug): ?>
                            <tr class="low-stock-alert">
                                <td data-label="Drug Name"><?php echo htmlspecialchars($drug['drug_name']); ?></td>
                                <td data-label="Manufacturer"><?php echo htmlspecialchars($drug['manufacturer_name'] ? $drug['manufacturer_name'] : 'N/A'); ?></td>
                                <td data-label="Current Stock"><?php echo htmlspecialchars($drug['current_stock']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="report-section">
            <h2>Expired and Expiring Soon Stock</h2>
             <form action="reports.php" method="GET" class="filter-form-grid">
                <div class="form-group">
                    <label for="expiring_soon_days">Expiring Within (Days):</label>
                    <input type="number" id="expiring_soon_days" name="expiring_soon_days" min="0" value="<?php echo htmlspecialchars($expiringSoonDays); ?>" required>
                </div>
                 <!-- Preserve other filters when changing expiring soon days -->
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                <input type="hidden" name="salesperson_id" value="<?php echo htmlspecialchars($selectedSalespersonId); ?>">
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($selectedCustomerId); ?>">
                <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($selectedPaymentMethod); ?>">
                <input type="hidden" name="order_type" value="<?php echo htmlspecialchars($selectedOrderType); ?>">
                 <input type="hidden" name="low_stock_threshold" value="<?php echo htmlspecialchars($lowStockThreshold); ?>">

                <button type="submit">Refresh Expiring Stock Report</button>
            </form>
            <?php if (empty($expiredAndExpiringStock)): ?>
                <p class="message success">No drugs are expired or expiring within the next <?php echo htmlspecialchars($expiringSoonDays); ?> days.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Drug Name</th>
                            <th>Batch Number</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Expiry Date</th>
                            <th>Manufacturer</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiredAndExpiringStock as $stock):
                            $stockStatus = '';
                            $statusClass = '';
                            if (strtotime($stock['expiry_date']) < strtotime(date('Y-m-d'))) {
                                $stockStatus = 'Expired';
                                $statusClass = 'expired-stock-alert';
                            } else {
                                $stockStatus = 'Expiring Soon';
                                $statusClass = 'expiring-soon-alert';
                            }
                        ?>
                            <tr class="<?php echo htmlspecialchars($statusClass); ?>">
                                <td data-label="Drug Name"><?php echo htmlspecialchars($stock['drug_name']); ?></td>
                                <td data-label="Batch Number"><?php echo htmlspecialchars($stock['batch_number'] ? $stock['batch_number'] : 'N/A'); ?></td>
                                <td data-label="Quantity"><?php echo htmlspecialchars($stock['quantity']); ?></td>
                                <td data-label="Unit Price">ETB <?php echo number_format($stock['selling_price_per_unit'], 2); ?></td>
                                <td data-label="Expiry Date"><?php echo htmlspecialchars($stock['expiry_date']); ?></td>
                                <td data-label="Manufacturer"><?php echo htmlspecialchars($stock['manufacturer_name'] ? $stock['manufacturer_name'] : 'N/A'); ?></td>
                                <td data-label="Status"><?php echo htmlspecialchars($stockStatus); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
