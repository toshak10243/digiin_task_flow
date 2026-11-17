<?php
require_once __DIR__ . '/../core/Session.php';

function allowRole($roles){

    if(!isset($_SESSION['role'])){
        header("Location: ../../index.php");
        exit;
    }

    // single role support
    if(!is_array($roles)){
        $roles = [$roles];
    }

    if(!in_array($_SESSION['role'],$roles)){

        header("Location: ../../index.php");
        exit;
    }
}
?>
