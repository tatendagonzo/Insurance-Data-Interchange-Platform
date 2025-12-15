<?php
function checkAuth() {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        return ['success' => false, 'message' => 'Not authenticated'];
    }
    
    // Get user details from database
    require_once '../config/database.php';
    
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT u.*, c.name as company_name 
              FROM users u 
              LEFT JOIN companies c ON u.company_id = c.id 
              WHERE u.id = :id AND u.is_active = 1";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch();
        unset($user['password_hash']);
        
        return ['success' => true, 'user' => $user];
    }
    
    return ['success' => false, 'message' => 'User not found'];
}
?>