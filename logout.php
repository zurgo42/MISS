<?php
require_once __DIR__ . '/src/bootstrap.php';
md_logout();
header('Location: ' . md_url('login.php'));
