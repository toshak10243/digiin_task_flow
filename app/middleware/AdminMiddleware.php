<?php
// Pehle login check hoga
require_once __DIR__ . '/AuthMiddleware.php';

// Fir role check
require_once __DIR__ . '/RoleMiddleware.php';

// Sirf admin allowed
allowRole('admin');
?>
