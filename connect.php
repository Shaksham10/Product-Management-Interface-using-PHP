<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "phpproducts";

$conn = new mysqli($servername, $username, $password, $database);

if (!$conn) {
    die("<p style='text-align: center; color: red;'>Connection failed </p>");
}
?>