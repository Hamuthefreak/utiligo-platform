<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
http_response_code(401);
$pageTitle = '401 — Unauthorised — Utiligo';
$_err_code = '401';
$_err_title = 'Unauthorised';
$_err_desc  = 'You need to be logged in to view this page.';
require_once __DIR__ . '/error_page.php';
