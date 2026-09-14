<?php
require_once __DIR__ . '/config.php';

clear_auth_user();
session_destroy();
header('Location: /index.php');
exit;
