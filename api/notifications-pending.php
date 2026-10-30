<?php
require_once __DIR__ . '/../includes/tahsin_reminder_api.php';
if (!tahsinReminderAuthorize()) exit;
require_once __DIR__ . '/../config/database.php';
tahsinReminderResponse($pdo, false);
