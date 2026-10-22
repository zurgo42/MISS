<?php
require_once __DIR__ . '/src/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: ' . md_url('login.php'));
