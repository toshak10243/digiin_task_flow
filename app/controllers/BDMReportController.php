<?php
date_default_timezone_set('Asia/Kolkata');
require_once "../config/database.php";

$db = (new Database())->connect();

$date = $_GET['date'] ?? date("Y-m-d");

$stmt = $db->prepare("
    SELECT t.*, 
           u.name AS team_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE DATE(t.task_created_at)=:date
    ORDER BY t.id ASC
");
$stmt->execute([':date'=>$date]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Digiink_Report_'.$date.'.csv"');

$output = fopen("php://output", "w");

fputcsv($output, ['DATE','TASK KEY','ASSIGNED TO','WORD COUNT','STATUS']);

foreach($tasks as $t){
    fputcsv($output, [
        date("d/m/Y", strtotime($t['task_created_at'])),
        $t['task_key'],
        $t['team_name'] ?? 'Not Assigned',
        $t['word_count'],
        strtoupper($t['status'])
    ]);
}

fclose($output);
exit;
?>
