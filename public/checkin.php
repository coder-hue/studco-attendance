<?php
declare(strict_types=1);

// Legacy entry point for older QR codes. New codes use /checkin?event=…&token=….
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /checkin' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
