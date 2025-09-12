<?php
// Cron entry point to run due processes based on Asia/Tehran timezone
$_POST['action']='run_due_processes';
require __DIR__.'/ajax.php';
?>
