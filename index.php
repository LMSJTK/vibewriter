<?php
require_once 'config/config.php';

// Redirect to dashboard if logged in, otherwise to login
if (isLoggedIn()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
?>
