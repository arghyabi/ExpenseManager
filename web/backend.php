<?php
require 'dbcon.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $tx_id = isset($_POST['tx_id']) ? intval($_POST['tx_id']) : 0;

    if ($action === 'edit' && $tx_id > 0) {
        $date = isset($_POST['date']) ? $_POST['date'] : '';
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $category = isset($_POST['category']) ? intval($_POST['category']) : 0;
        $note = isset($_POST['note']) ? $_POST['note'] : '';

        $stmt = $db->prepare(
            "UPDATE transactions
             SET date=?, type=?, amount=?, category_id=?, note=?
             WHERE id=?"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $category, SQLITE3_INTEGER);
        $stmt->bindValue(5, $note, SQLITE3_TEXT);
        $stmt->bindValue(6, $tx_id, SQLITE3_INTEGER);
        $stmt->execute();
    }
    elseif ($action === 'delete' && $tx_id > 0) {
        $stmt = $db->prepare("DELETE FROM transactions WHERE id=?");
        $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    header('Location: index.php');
    exit;
}
?>
