<?php
// pharmacy_management_system/edit_drug.php
session_start();

require_once 'includes/db.php';
require_once 'includes/auth_check.php';

// --- Authorization Check ---
// Only allow 'Admin' or 'Pharmacist' roles to access this page
$currentUser = getCurrentUser();
if (!isset($currentUser) || !in_array($currentUser['role'], ['Admin', 'Pharmacist'])) {
    header('Location: staff_login.php'); // Redirect to login, not dashboard, for unauthorized access
    exit();
}

$pdo = getDbConnection();
$feedbackMessage = '';
$feedbackStatus = ''; // Added for styling messages (success/error)
$drug = null; // Initialize drug data

// Get drug ID from URL (e.g., edit_drug.php?id=123)
$drugId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no drug ID provided or it's invalid, redirect back to drugs list
if ($drugId === 0) {
    $_SESSION['feedback_message'] = "Invalid drug ID provided.";
    $_SESSION['feedback_status'] = "error";
    header('Location: drugs.php'); // Redirect back to the main management page
    exit();
}

// --- Handle Form Submission for Updating Drug ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updatedDrugName = trim(isset($_POST['drug_name']) ? $_POST['drug_name'] : '');
    // Using explicit null for empty strings as per your original code's logic for optional fields
    $updatedBrandName = trim(isset($_POST['brand_name']) ? $_POST['brand_name'] : '');
    $updatedBrandName = ($updatedBrandName === '') ? null : $updatedBrandName;

    $updatedDescription = trim(isset($_POST['description']) ? $_POST['description'] : '');
    $updatedDescription = ($updatedDescription === '') ? null : $updatedDescription;

    $updatedCategoryId = (int)(isset($_POST['category_id']) ? $_POST['category_id'] : 0);
    $updatedManufacturerId = (int)(isset($_POST['manufacturer_id']) ? $_POST['manufacturer_id'] : 0);
    
    $updatedUnitOfMeasure = trim(isset($_POST['unit_of_measure']) ? $_POST['unit_of_measure'] : '');
    $updatedUnitOfMeasure = ($updatedUnitOfMeasure === '') ? null : $updatedUnitOfMeasure;
    
    $updatedDosage = trim(isset($_POST['dosage']) ? $_POST['dosage'] : '');
    $updatedDosage = ($updatedDosage === '') ? null : $updatedDosage;

    // --- Server-side Validation ---
    $errors = [];

    if (empty($updatedDrugName)) {
        $errors[] = "Drug Name is required.";
    } elseif (strlen($updatedDrugName) > 255) {
        $errors[] = "Drug Name cannot exceed 255 characters.";
    }

    if ($updatedBrandName !== null && strlen($updatedBrandName) > 100) {
        $errors[] = "Brand Name cannot exceed 100 characters.";
    }

    if ($updatedDescription !== null && strlen($updatedDescription) > 1000) {
        $errors[] = "Description cannot exceed 1000 characters.";
    }

    if ($updatedCategoryId === 0) {
        $errors[] = "Category is required.";
    }
    if ($updatedManufacturerId === 0) {
        $errors[] = "Manufacturer is required.";
    }

    if ($updatedUnitOfMeasure !== null && strlen($updatedUnitOfMeasure) > 50) {
        $errors[] = "Unit of Measure cannot exceed 50 characters.";
    }

    if ($updatedDosage !== null && strlen($updatedDosage) > 50) {
        $errors[] = "Dosage cannot exceed 50 characters.";
    }

    if (!empty($errors)) {
        $feedbackMessage = "Error: " . implode('<br>', $errors);
        $feedbackStatus = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // Verify category_id exists
            $stmt = $pdo->prepare("SELECT category_id FROM Categories WHERE category_id = ?");
            $stmt->execute([$updatedCategoryId]);
            if (!$stmt->fetch()) {
                throw new Exception("Selected category does not exist. Please select a valid category.");
            }

            // Verify manufacturer_id exists
            $stmt = $pdo->prepare("SELECT manufacturer_id FROM Manufacturers WHERE manufacturer_id = ?");
            $stmt->execute([$updatedManufacturerId]);
            if (!$stmt->fetch()) {
                throw new Exception("Selected manufacturer does not exist. Please select a valid manufacturer.");
            }

            $sql = "UPDATE Drugs SET
                        drug_name = ?,
                        brand_name = ?,
                        description = ?,
                        category_id = ?,
                        manufacturer_id = ?,
                        unit_of_measure = ?,
                        dosage = ?
                    WHERE drug_id = ?";

            $params = [
                $updatedDrugName,
                $updatedBrandName,
                $updatedDescription,
                $updatedCategoryId,
                $updatedManufacturerId,
                $updatedUnitOfMeasure,
                $updatedDosage,
                $drugId
            ];

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                $feedbackMessage = "Drug '" . htmlspecialchars($updatedDrugName) . "' updated successfully!";
                $feedbackStatus = 'success';
            } else {
                $feedbackMessage = "No changes made to drug '" . htmlspecialchars($updatedDrugName) . "'.";
                $feedbackStatus = 'info'; // Use 'info' for no changes feedback
            }

            $pdo->commit();

            // After update, re-fetch drug data to display latest info in form
            // This ensures the form reflects any changes immediately
            $stmt = $pdo->prepare("SELECT drug_id, drug_name, brand_name, description, category_id, manufacturer_id, unit_of_measure, dosage FROM Drugs WHERE drug_id = ?");
            $stmt->execute([$drugId]);
            $drug = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $feedbackMessage = "Error updating drug: " . htmlspecialchars($e->getMessage());
            $feedbackStatus = 'error';
        }
    }
}

// --- Fetch Drug Data for Display (Initial Load or After Failed Update) ---
// Only fetch if $drug is not already populated by a successful POST and re-fetch
if ($drug === null) {
    try {
        $stmt = $pdo->prepare("SELECT drug_id, drug_name, brand_name, description, category_id, manufacturer_id, unit_of_measure, dosage FROM Drugs WHERE drug_id = ?");
        $stmt->execute([$drugId]);
        $drug = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$drug) {
            // If drug not found, redirect back to drugs list
            $_SESSION['feedback_message'] = "Drug not found or does not exist.";
            $_SESSION['feedback_status'] = "error";
            header('Location: drugs.php'); // Redirect back to the main management page
            exit();
        }
    } catch (PDOException $e) {
        $feedbackMessage = "Error loading drug data: " . htmlspecialchars($e->getMessage());
        $feedbackStatus = 'error';
        $drug = null; // Ensure no partial data is displayed
    }
}

// --- Fetch Categories for Dropdown ---
$categories = [];
try {
    $stmt = $pdo->query("SELECT category_id, category_name FROM Categories ORDER BY category_name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<br>Error loading categories: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error'; // Propagate error status
}

// --- Fetch Manufacturers for Dropdown ---
$manufacturers = [];
try {
    $stmt = $pdo->query("SELECT manufacturer_id, manufacturer_name FROM Manufacturers ORDER BY manufacturer_name");
    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbackMessage .= '<br>Error loading manufacturers: ' . htmlspecialchars($e->getMessage());
    $feedbackStatus = 'error'; // Propagate error status
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Drug - Woloma Pharmacy</title>
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
        <h1>Edit Drug</h1>
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
            <h3>Edit Drug: <?php echo htmlspecialchars(isset($drug['drug_name']) ? $drug['drug_name'] : 'N/A'); ?></h3>
            <?php if (!empty($feedbackMessage)): ?>
                <p class="message <?php echo $feedbackStatus; ?>"><?php echo $feedbackMessage; ?></p>
            <?php endif; ?>

            <?php if ($drug): ?>
                <form action="edit_drug.php?id=<?php echo htmlspecialchars($drug['drug_id']); ?>" method="POST">
                    <input type="hidden" name="drug_id" value="<?php echo htmlspecialchars($drug['drug_id']); ?>">

                    <div class="form-group">
                        <label for="drug_name">Drug Name:</label>
                        <input type="text" id="drug_name" name="drug_name" value="<?php echo htmlspecialchars(isset($_POST['drug_name']) ? $_POST['drug_name'] : (isset($drug['drug_name']) ? $drug['drug_name'] : '')); ?>" required maxlength="255">
                    </div>

                    <div class="form-group">
                        <label for="brand_name">Brand Name (Optional):</label>
                        <input type="text" id="brand_name" name="brand_name" value="<?php echo htmlspecialchars(isset($_POST['brand_name']) ? $_POST['brand_name'] : (isset($drug['brand_name']) ? $drug['brand_name'] : '')); ?>" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="description">Description (Optional):</label>
                        <textarea id="description" name="description" rows="3" maxlength="1000"><?php echo htmlspecialchars(isset($_POST['description']) ? $_POST['description'] : (isset($drug['description']) ? $drug['description'] : '')); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category:</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['category_id']); ?>"
                                    <?php echo ((isset($_POST['category_id']) ? $_POST['category_id'] : (isset($drug['category_id']) ? $drug['category_id'] : '')) == $category['category_id']) ? 'selected' : ''; ?>>
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
                                    <?php echo ((isset($_POST['manufacturer_id']) ? $_POST['manufacturer_id'] : (isset($drug['manufacturer_id']) ? $drug['manufacturer_id'] : '')) == $manufacturer['manufacturer_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($manufacturer['manufacturer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="unit_of_measure">Unit of Measure (e.g., mg, ml):</label>
                        <input type="text" id="unit_of_measure" name="unit_of_measure" value="<?php echo htmlspecialchars(isset($_POST['unit_of_measure']) ? $_POST['unit_of_measure'] : (isset($drug['unit_of_measure']) ? $drug['unit_of_measure'] : '')); ?>" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="dosage">Dosage (e.g., 100mg, 5ml):</label>
                        <input type="text" id="dosage" name="dosage" value="<?php echo htmlspecialchars(isset($_POST['dosage']) ? $_POST['dosage'] : (isset($drug['dosage']) ? $drug['dosage'] : '')); ?>" maxlength="50">
                    </div>

                    <button type="submit">Save Changes</button>
                </form>
            <?php else: ?>
                <p>Drug data could not be loaded or drug does not exist.</p>
            <?php endif; ?>
        </div>
        <p class="back-link"><a href="manage_drugs_stock.php">Back to Drug & Stock Management</a></p>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Woloma Pharmacy. All rights reserved. Developed by [Musa H. 0920715314].</p>
    </footer>
</body>
</html>
