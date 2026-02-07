<?php
require 'dbcon.php';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $db->prepare("
        INSERT INTO transactions
        (date,type,amount,category_id,account_id,note,payment_mode)
        VALUES (?,?,?,?,?,?,?)
    ");

    $stmt->bindValue(1, $_POST['date']);
    $stmt->bindValue(2, $_POST['type']);
    $stmt->bindValue(3, $_POST['amount']);
    $stmt->bindValue(4, $_POST['category']);
    $stmt->bindValue(5, $_POST['account']);
    $stmt->bindValue(6, $_POST['note']);
    $stmt->bindValue(7, 'cash');

    $stmt->execute();

    header("Location: index.php");
    exit;
}

$cats = $db->query("SELECT * FROM categories");
$accs = $db->query("SELECT * FROM accounts");
?>

<html>
<head>
<link rel="stylesheet" href="../resource/css/style.css">
</head>
<body>

<h2>Add Transaction</h2>

<form method="post">

Date:
<input type="date" name="date" required><br>

Type:
<select name="type">
    <option value="expense">Expense</option>
    <option value="income">Income</option>
</select><br>

Amount:
<input type="number" step="0.01" name="amount"><br>

Category:
<select name="category">
<?php while($c = $cats->fetchArray(SQLITE3_ASSOC)) { ?>
<option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
<?php } ?>
</select><br>

Account:
<select name="account">
<?php while($a = $accs->fetchArray(SQLITE3_ASSOC)) { ?>
<option value="<?= $a['id'] ?>"><?= $a['name'] ?></option>
<?php } ?>
</select><br>

Note:
<input type="text" name="note"><br><br>

<button type="submit">Save</button>

</form>

<a href="index.php">⬅ Back</a>

</body>
</html>
