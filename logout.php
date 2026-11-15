<?php
require __DIR__ . '/inc/bootstrap.php';
logout();
header('Location: ' . url('login.php'));
