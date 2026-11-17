<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('admin');
include __DIR__ . "/../partials/notifications_history_shared.php";
