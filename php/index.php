<?php
require 'dbcon.php';
$db = getDB();

# -----------------------
# Balance
# -----------------------
$balance = $db->querySingle("
    SELECT IFNULL(SUM(
        CASE WHEN type='income' THEN amount ELSE -amount END
    ),0)
    FROM transactions
");

# -----------------------
# Monthly summary
# -----------------------
$monthly = $db->query("
    SELECT * FROM monthly_summary ORDER BY year DESC, month DESC
");

# -----------------------
# Transactions
# -----------------------
$tx = $db->query("
    SELECT t.*, c.name as category, a.name as account
    FROM transactions t
    LEFT JOIN categories c ON t.category_id=c.id
    LEFT JOIN accounts a ON t.account_id=a.id
    ORDER BY date DESC
");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Expense Manager</title>
    <link rel="stylesheet" href="../resource/css/style.css">
</head>
<body>

<h1>💰 Expense Manager</h1>

<h2>Current Balance: ₹ <?= number_format($balance,2) ?></h2>

<a href="add.php">Add Transaction</a>

<hr>

<h2>Monthly Summary</h2>
<table>
<tr>
    <th>Year</th><th>Month</th><th>Income</th><th>Expense</th><th>Net</th>
</tr>

<?php while($r = $monthly->fetchArray(SQLITE3_ASSOC)) { ?>
<tr>
    <td><?= $r['year'] ?></td>
    <td><?= $r['month'] ?></td>
    <td><?= $r['income'] ?></td>
    <td><?= $r['expense'] ?></td>
    <td><?= $r['net'] ?></td>
</tr>
<?php } ?>
</table>

<hr>

<h2>Transactions</h2>

<table>
<tr>
<th>Date</th><th>Type</th><th>Amount</th><th>Category</th><th>Account</th><th>Note</th>
</tr>

<?php while($r = $tx->fetchArray(SQLITE3_ASSOC)) { ?>
<tr>
<td><?= $r['date'] ?></td>
<td><?= $r['type'] ?></td>
<td><?= $r['amount'] ?></td>
<td><?= $r['category'] ?></td>
<td><?= $r['account'] ?></td>
<td><?= $r['note'] ?></td>
</tr>
<?php } ?>

</table>

</body>
</html>
