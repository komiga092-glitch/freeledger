<?php

$host =  "localhost";
$user = "root";
$pass = "";
$dbname = "freeledger";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    die("Database connection failed. Please contact administrator.");
}

?>