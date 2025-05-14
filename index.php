<?php
session_start();
require_once 'menu.php';

// Get the POST parameters from Africa's Talking
$sessionId   = $_POST["sessionId"];
$serviceCode = $_POST["serviceCode"];
$phoneNumber = $_POST["phoneNumber"];
$text        = $_POST["text"];

// Initialize menu handler
$menu = new Menu($phoneNumber);

// Process the USSD request
header('Content-type: text/plain');
echo $menu->handleRequest($text);
?>
