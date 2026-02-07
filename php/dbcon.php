<?php

class DB extends SQLite3
{
    function __construct()
    {
        $this->open('../expenseManagerDatabase.db');
    }
}

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new DB();
    }
    return $db;
}
?>
