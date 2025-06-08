<?php
// pharmacy_management_system/view_order_details.php
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
$customer_id = $_SESSION['customer_id'];
$order_id = null;
$order = null;
$order_items = [];
$feedbackMessage = '';

if (isset($_GET['sale_id']) && is_numeric($_GET['sale_id'])) {
    $order_id = (int)$_GET['sale_id'];

    try {
        // Fetch the specific order details for the logged-in customer
        $stmt = $pdo->prepare("
            SELECT
                sale_id,
                sale_date,
                total_amount,
                order_status,
                payment_status,
                order_type,
                delivery_address,
                notes,
                discount_amount,
                amount_paid,
                proof_of_payment_path
            FROM
                Sales
            WHERE
                sale_id = ? AND customer_id = ?
        ");
        $stmt->execute([$order_id, $customer_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Fetch the items for this order
            $stmtItems = $pdo->prepare("
                SELECT
                    si.quantity_sold,
                    si.price_per_unit_at_sale,
                    si.subtotal,
                    d.drug_name,
                    d.description
                FROM
                    SaleItems si
                JOIN
                    Drugs d ON si.drug_id = d.drug_id
                WHERE
                    si.sale_id = ?
            ");
            $stmtItems->execute([$order_id]);
            $order_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $feedbackMessage = '<p style="color: red;">Error: Order not found or you do not have permission to view it.</p>';
        }

    } catch (PDOException $e) {
        $feedbackMessage = '<p style="color: red;">Database error loading order details: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    $feedbackMessage = '<p style="color: red;">Error: No order ID provided.</p>';
}

// Redirect back to my_orders.php if no order is found or an error occurs
if ($order === null && !empty($feedbackMessage)) {
    header('Location: customer_orders.php?message=' . urlencode(strip_tags($feedbackMessage)) . '&status=error');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - Woloma Pharmacy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; }
        header { background-color: #007bff; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 24px; }
        .auth-status { display: flex; align-items: center; }
        .auth-status span { margin-right: 15px; }
        .auth-status a {
            background-color: #6c757d; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;
            text-decoration: none; margin-left: 10px; display: inline-block;
        }
        .auth-status a:hover { background-color: #5a6268; }

        main { padding: 20px; max-width: 900px; margin: 20px auto; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 5px; text-align: center; font-weight: bold; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .order-details-section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 5px;
            background-color: #fcfcfc;
        }
        .order-details-section h3 {
            color: #007bff;
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-row span:first-child {
            font-weight: bold;
            color: #555;
        }
        .total-amount-display {
            font-size: 1.4em;
            font-weight: bold;
            margin-top: 20px;
            text-align: right;
            color: #28a745;
        }
        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .order-items-table th, .order-items-table td {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
        }
        .order-items-table th {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .order-items-table tbody tr:nth-child(odd) {
            background-color: #f8f9fa;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
            text-align: center;
            min-width: 90px;
        }
        .status-pending { background-color: #ffc107; color: #343a40; } /* yellow */
        .status-processing { background-color: #007bff; } /* blue */
        .status-ready { background-color: #17a2b8; } /* teal */
        .status-completed { background-color: #28a745; } /* green */
        .status-cancelled { background-color: #dc3545; } /* red */
        .status-default { background-color: #6c757d; } /* grey */

        .proof-of-payment-link {
            margin-top: 15px;
            text-align: center;
            padding: 10px;
            border: 1px dashed #007bff;
            border-radius: 5px;
            background-color: #eaf5ff;
        }
        .proof-of-payment-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
        .proof-of-payment-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <h1>Order Details</h1>
        <div class="auth-status">
            <span>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</span>
            <a href="customer_orders.php">Back to My Orders</a>
            <form action="logout.php" method="POST">
                <button type="submit">Logout</button>
            </form>
        </div>
    </header>
    <main>
        <?php if ($order): ?>
            <h2>Details for Order #<?php echo htmlspecialchars($order['sale_id']); ?></h2>

            <div class="order-details-section">
                <h3>Order Summary</h3>
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
                        <span class="status-badge <?php echo $statusClass; ?>">
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
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="detail-row">
                        <span>Discount:</span>
                        <span>- ETB <?php echo number_format($order['discount_amount'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span>Amount Paid:</span>
                    <span>ETB <?php echo number_format($order['amount_paid'], 2); ?></span>
                </div>
                <?php if (!empty($order['notes'])): ?>
                    <div class="detail-row">
                        <span>Notes:</span>
                        <span><?php echo nl2br(htmlspecialchars($order['notes'])); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['proof_of_payment_path'])): ?>
                    <div class="proof-of-payment-link">
                        <p>
                            <a href="<?php echo htmlspecialchars($order['proof_of_payment_path']); ?>" target="_blank">
                                View Proof of Payment
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="order-details-section">
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <p class="message error"><?php echo $feedbackMessage; ?></p>
        <?php endif; ?>
    </main>
</body>
</html>