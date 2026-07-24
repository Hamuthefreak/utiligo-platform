<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
http_response_code(403);
$pageTitle = '403 — Access Denied — Utiligo';
$_err_code = '403';
$_err_title = "Access Denied";
$_err_desc  = "You don\u2019t have permission to view this page.";
require_once __DIR__ . '/error_page.php';
