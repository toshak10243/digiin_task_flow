<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';

class Mailer {

    public static function sendUrgentTaskMail($task, $opManagerEmail) {

        $mail = new PHPMailer(true);

        try {

            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'hostingdigiink@gmail.com'; // your email
            $mail->Password   = 'vpukndjbzeashdrt';    // app password
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('hostingdigiink@gmail.com', 'Digiink Task Flow');
            $mail->addAddress($opManagerEmail);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Urgent Task Assigned: ' . $task['task_name'];

            $mail->Body = "
                <h3>Urgent Task Alert</h3>
                <p><strong>Task:</strong> {$task['task_name']}</p>
                <p><strong>Deadline:</strong> {$task['deadline']}</p>
                <p><strong>Word Count:</strong> {$task['word_count']}</p>
                <p><strong>Instructions:</strong> {$task['instructions']}</p>
                <br>
                <p>Please review and assign this task immediately.</p>
            ";

            $mail->send();
            return true;

        } catch (Exception $e) {
            error_log("Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}