<?php
require_once 'config/config.php';
require_once 'includes/auth.php';

logoutUser();
redirect('login.php');
?>
