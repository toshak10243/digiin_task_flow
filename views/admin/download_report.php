<?php
require_once "../../app/middleware/AdminMiddleware.php";
require_once "../../app/config/database.php";
require_once "../../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();

$reportDate = $_GET['report_date'] ?? date('Y-m-d');
$start = $reportDate . " 00:00:00";
$end   = $reportDate . " 23:59:59";

/* TASK STATS */
$stmt = $db->prepare("
SELECT COUNT(*) total,
SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) progress,
SUM(CASE WHEN status='rework' THEN 1 ELSE 0 END) rework,
SUM(CASE WHEN urgent='yes' THEN 1 ELSE 0 END) urgent,
SUM(word_count) words
FROM tasks WHERE task_created_at BETWEEN :s AND :e
");
$stmt->execute([':s'=>$start, ':e'=>$end]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* BDE PERFORMANCE */
$bde = $db->query("
SELECT u.username,
COUNT(t.id) total,
SUM(t.word_count) words,
SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN t.status='rework' THEN 1 ELSE 0 END) rework
FROM users u
LEFT JOIN tasks t ON u.id=t.created_by
WHERE u.role='bde'
GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

/* TEAM PERFORMANCE */
$team = $db->query("
SELECT u.username,
COUNT(t.id) total,
SUM(t.word_count) words,
SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN t.status='rework' THEN 1 ELSE 0 END) rework
FROM users u
LEFT JOIN tasks t ON u.id=t.assigned_to
WHERE u.role='operations_team'
GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

/* QC */
$qc = $db->query("
SELECT COUNT(*) reviewed,
SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) approved,
SUM(CASE WHEN status='rework' THEN 1 ELSE 0 END) reworks
FROM tasks
")->fetch(PDO::FETCH_ASSOC);

/* ================= HTML FOR PDF (Optimized for Dompdf) ================= */
$html = "
<!DOCTYPE html>
<html>
<head>
<style>
    @page { margin: 40px; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color:#334155; line-height: 1.5; }
    
    .header { border-bottom: 3px solid #1e3a8a; padding-bottom: 15px; margin-bottom: 25px; }
    .header-table { width: 100%; border: none; }
    .company-name { font-size: 22px; font-weight: bold; color: #1e3a8a; text-transform: uppercase; letter-spacing: 1px; }
    .report-title { font-size: 14px; color: #64748b; font-weight: normal; }
    
    .summary-box { width: 100%; margin-bottom: 30px; }
    .stat-card { 
        width: 30%; 
        background: #f8fafc; 
        border: 1px solid #e2e8f0; 
        padding: 12px; 
        text-align: center; 
        float: left; 
        margin-right: 2.5%; 
        margin-bottom: 10px;
        border-radius: 8px;
    }
    .stat-label { font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px; }
    .stat-value { font-size: 16px; font-weight: bold; color: #1e293b; }
    .clear { clear: both; }

    .section-header { 
        background: #f1f5f9; 
        color: #1e3a8a; 
        padding: 8px 12px; 
        font-size: 12px; 
        font-weight: bold; 
        margin-top: 25px; 
        border-left: 4px solid #1e3a8a; 
    }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #1e293b; color: #ffffff; padding: 10px; font-size: 10px; text-transform: uppercase; text-align: left; }
    td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; text-align: left; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .font-bold { font-weight: bold; }
    
    .status-completed { color: #10b981; font-weight: bold; }
    .status-rework { color: #f43f5e; font-weight: bold; }

    .footer { position: fixed; bottom: -20px; left: 0; right: 0; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
</style>
</head>
<body>

<div class='header'>
    <table class='header-table'>
        <tr>
            <td>
                <div class='company-name'>Digiink Task Flow</div>
                <div class='report-title'>Operational Performance Intelligence Report</div>
            </td>
            <td class='text-right'>
                <div class='font-bold'>Report Date: " . date('d M, Y', strtotime($reportDate)) . "</div>
                <div style='color:#64748b;'>System ID: #AN-".date('Ymd')."</div>
            </td>
        </tr>
    </table>
</div>

<div class='summary-box'>
    <div class='stat-card'>
        <span class='stat-label'>Total Tasks</span>
        <span class='stat-value'>{$stats['total']}</span>
    </div>
    <div class='stat-card'>
        <span class='stat-label'>Completed</span>
        <span class='stat-value' style='color:#10b981;'>{$stats['completed']}</span>
    </div>
    <div class='stat-card' style='margin-right:0;'>
        <span class='stat-label'>Total Words</span>
        <span class='stat-value' style='color:#6366f1;'>" . number_format($stats['words']) . "</span>
    </div>
    <div class='clear'></div>
    <div class='stat-card'>
        <span class='stat-label'>In Progress</span>
        <span class='stat-value' style='color:#f59e0b;'>{$stats['progress']}</span>
    </div>
    <div class='stat-card'>
        <span class='stat-label'>Urgent</span>
        <span class='stat-value' style='color:#ef4444;'>{$stats['urgent']}</span>
    </div>
    <div class='stat-card' style='margin-right:0;'>
        <span class='stat-label'>Rework</span>
        <span class='stat-value' style='color:#f43f5e;'>{$stats['rework']}</span>
    </div>
    <div class='clear'></div>
</div>

<div class='section-header'>BDE PERFORMANCE MATRIX</div>
<table>
    <thead>
        <tr>
            <th>BDE Executive</th>
            <th class='text-center'>Total Tasks</th>
            <th class='text-center'>Completed</th>
            <th class='text-center'>Rework</th>
            <th class='text-right'>Total Words</th>
        </tr>
    </thead>
    <tbody>";
    foreach($bde as $r){
        $html .= "<tr>
            <td class='font-bold'>{$r['username']}</td>
            <td class='text-center'>{$r['total']}</td>
            <td class='text-center status-completed'>{$r['completed']}</td>
            <td class='text-center status-rework'>{$r['rework']}</td>
            <td class='text-right font-bold'>" . number_format($r['words']) . "</td>
        </tr>";
    }
$html .= "</tbody>
</table>

<div class='section-header'>OPERATIONS TEAM ANALYTICS</div>
<table>
    <thead>
        <tr>
            <th>Expert Name</th>
            <th class='text-center'>Assigned</th>
            <th class='text-center'>Finished</th>
            <th class='text-center'>Reworks</th>
            <th class='text-right'>Net Word Count</th>
        </tr>
    </thead>
    <tbody>";
    foreach($team as $r){
        $html .= "<tr>
            <td class='font-bold'>{$r['username']}</td>
            <td class='text-center'>{$r['total']}</td>
            <td class='text-center status-completed'>{$r['completed']}</td>
            <td class='text-center status-rework'>{$r['rework']}</td>
            <td class='text-right font-bold'>" . number_format($r['words']) . "</td>
        </tr>";
    }
$html .= "</tbody>
</table>

<div class='section-header'>QUALITY ASSURANCE SUMMARY</div>
<table>
    <thead>
        <tr>
            <th class='text-center'>Total Reviewed</th>
            <th class='text-center'>Approved (First Pass)</th>
            <th class='text-center'>Rework Requests</th>
            <th class='text-center'>Quality Score</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class='text-center font-bold'>{$qc['reviewed']}</td>
            <td class='text-center status-completed'>{$qc['approved']}</td>
            <td class='text-center status-rework'>{$qc['reworks']}</td>
            <td class='text-center font-bold'>";
            $score = ($qc['reviewed'] > 0) ? round(($qc['approved'] / $qc['reviewed']) * 100, 1) : 0;
            $html .= $score . "%</td>
        </tr>
    </tbody>
</table>

<div class='footer'>
    Report Security Class: Internal Confidential | Generated on ".date('d M Y h:i A')." | Digiink Task Flow ERP
</div>

</body>
</html>
";

/* ================= PDF GENERATION ================= */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Digiink_Performance_Report_$reportDate.pdf", ["Attachment"=>true]);
exit;