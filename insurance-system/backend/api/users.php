<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../middleware/auth.php';

// Check authentication and admin role
$auth = checkAuth();
if (!$auth['success'] || $auth['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admin role required.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'GET':
        getUsers($db);
        break;
    case 'POST':
        createUser($db, $input);
        break;
    case 'PUT':
        updateUser($db, $input);
        break;
    case 'DELETE':
        deleteUser($db, $input);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getUsers($db) {
    $query = "SELECT u.*, c.name as company_name 
              FROM users u 
              LEFT JOIN companies c ON u.company_id = c.id 
              WHERE u.is_active = 1 
              ORDER BY u.name";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $users = $stmt->fetchAll();
    
    // Remove password hashes from response
    foreach ($users as &$user) {
        unset($user['password_hash']);
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
}

function createUser($db, $input) {
    if (!isset($input['email']) || !isset($input['name']) || !isset($input['role'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email, name, and role are required']);
        return;
    }

    $query = "INSERT INTO users 
              (id, email, password_hash, name, role, company_id, created_at, updated_at)
              VALUES 
              (:id, :email, :password_hash, :name, :role, :company_id, NOW(), NOW())";

    try {
        $stmt = $db->prepare($query);
        
        $userId = uniqid('user_');
        $defaultPassword = password_hash('demo123', PASSWORD_DEFAULT); // Default password
        
        $stmt->bindParam(':id', $userId);
        $stmt->bindParam(':email', $input['email']);
        $stmt->bindParam(':password_hash', $defaultPassword);
        $stmt->bindParam(':name', $input['name']);
        $stmt->bindParam(':role', $input['role']);
        $stmt->bindParam(':company_id', $input['company_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $userId, 'default_password' => 'demo123']);
        } else {
            throw new Exception('Failed to create user');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            echo json_encode(['error' => 'Email already exists']);
        } else {
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
}

function updateUser($db, $input) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    $query = "UPDATE users SET ";
    $fields = [];
    $params = [':id' => $input['id']];
    
    $allowedFields = ['name', 'email', 'role', 'company_id', 'is_active', 'two_factor_enabled'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }
    
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }
    
    $query .= implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
    
    try {
        $stmt = $db->prepare($query);
        
        if ($stmt->execute($params)) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to update user');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteUser($db, $input) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    try {
        // Soft delete - set is_active to false instead of deleting
        $query = "UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $input['id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to delete user');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>