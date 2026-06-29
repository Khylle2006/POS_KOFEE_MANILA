<?php
// logout.php - Logout Handler

session_start();

// Destroy the session
session_destroy();

// Redirect to login
header('Location: ../login.php');
exit;
?>