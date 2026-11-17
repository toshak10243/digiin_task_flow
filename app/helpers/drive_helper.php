<?php

if (!function_exists('getDriveClientLink')) {
    function getDriveClientLink($task) {
        if (!empty($task['drive_client_link'])) {
            return $task['drive_client_link'];
        }
        if (!empty($task['drive_client_folder_id'])) {
            return "https://drive.google.com/drive/folders/" . $task['drive_client_folder_id'];
        }
        return !empty($task['drive_folder_link']) ? $task['drive_folder_link'] : null;
    }
}

if (!function_exists('getDriveOperationsLink')) {
    function getDriveOperationsLink($task) {
        if (!empty($task['drive_operations_link'])) {
            return $task['drive_operations_link'];
        }
        if (!empty($task['drive_operations_folder_id'])) {
            return "https://drive.google.com/drive/folders/" . $task['drive_operations_folder_id'];
        }
        return !empty($task['drive_folder_link']) ? $task['drive_folder_link'] : null;
    }
}

if (!function_exists('getDriveAdditionalLink')) {
    function getDriveAdditionalLink($task) {
        if (!empty($task['drive_additional_link'])) {
            return $task['drive_additional_link'];
        }
        if (!empty($task['drive_additional_folder_id'])) {
            return "https://drive.google.com/drive/folders/" . $task['drive_additional_folder_id'];
        }
        if (!empty($task['additional_files']) && strpos($task['additional_files'], 'http') === 0) {
            return $task['additional_files'];
        }
        return !empty($task['drive_folder_link']) ? $task['drive_folder_link'] : null;
    }
}
