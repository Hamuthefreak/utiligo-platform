<?php
http_response_code(503);
$pageTitle = '503 — Maintenance — Utiligo';
$_err_code = '503';
$_err_title = 'Back Soon';
$_err_desc  = 'Utiligo is undergoing a quick maintenance. Check back in a few minutes.';
require_once __DIR__ . '/error_page.php';
