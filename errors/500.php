<?php
http_response_code(500);
// Avoid loading config here — a 500 may mean config itself is broken.
$pageTitle = '500 — Server Error — Utiligo';
$_err_code = '500';
$_err_title = 'Something Went Wrong';
$_err_desc  = 'Our server hit an unexpected error. We\u2019ve been notified — please try again in a moment.';
require_once __DIR__ . '/error_page.php';
