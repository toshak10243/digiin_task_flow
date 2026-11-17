<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/database.php';

class UserController {

    public function createUser($data){
        $userModel = new User();
        return $userModel->create($data);
    }

    public function getUsersByRole($role){
        $userModel = new User();
        return $userModel->getByRole($role);
    }

    public function deactivateUser($id){
        $userModel = new User();
        return $userModel->deactivate($id);
    }

    public function updateUserDetails($id, $name, $password){
        $db = (new Database())->connect();

        // Update name
        $stmt = $db->prepare("UPDATE users SET name=:name WHERE id=:id");
        $stmt->execute([':name'=>$name, ':id'=>$id]);

        // Update password if provided
        if(!empty($password)){
            $stmt = $db->prepare("UPDATE users SET password=:pass WHERE id=:id");
            $stmt->execute([
                ':pass'=>password_hash($password,PASSWORD_DEFAULT),
                ':id'=>$id
            ]);
        }
        return true;
    }
}
?>
