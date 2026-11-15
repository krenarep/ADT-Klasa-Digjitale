<?php
require __DIR__ . '/inc/bootstrap.php';
$u = current_user();
redirect($u ? home_for($u['role']) : 'login.php');  // url() përdor nën-dosjen e zbuluar automatikisht
