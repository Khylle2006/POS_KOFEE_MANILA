<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/application_tracking.php';
header('Content-Type: application/json');
echo json_encode(application_tracking_request(get_db(), 'job'), JSON_THROW_ON_ERROR);
