<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
http_response_code(400);
$pageTitle = '400 — Bad Request — Utiligo';
$_err_code = '400';
$_err_title = 'Bad Request';
$_err_desc  = 'Something was wrong with that request. Please try again.';
require_once __DIR__ . '/error_page.php';
