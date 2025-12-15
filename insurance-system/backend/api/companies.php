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
        getCompanies($db);
        break;
    case 'POST':
        createCompany($db, $input);
        break;
    case 'PUT':
        updateCompany($db, $input);
        break;
    case 'DELETE':
        deleteCompany($db, $input);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getCompanies($db) {
    $query = "SELECT c.*, 
                     COUNT(DISTINCT u.id) as user_count,
                     COUNT(DISTINCT ic.id) as claim_count
              FROM companies c 
              LEFT JOIN users u ON c.id = u.company_id AND u.is_active = 1
              LEFT JOIN insurance_claims ic ON c.id = ic.company_id
              GROUP BY c.id
              ORDER BY c.name";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $companies = $stmt->fetchAll();
    echo json_encode(['success' => true, 'companies' => $companies]);
}

function createCompany($db, $input) {
    if (!isset($input['name']) || !isset($input['license_number'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Company name and license number are required']);
        return;
    }

    $query = "INSERT INTO companies 
              (id, name, license_number, address, contact_email, contact_phone, created_at, updated_at)
              VALUES 
              (:id, :name, :license_number, :address, :contact_email, :contact_phone, NOW(), NOW())";

    try {
        $stmt = $db->prepare($query);
        
        $companyId = uniqid('comp_');
        
        $stmt->bindParam(':id', $companyId);
        $stmt->bindParam(':name', $input['name']);
        $stmt->bindParam(':license_number', $input['license_number']);
        $stmt->bindParam(':address', $input['address']);
        $stmt->bindParam(':contact_email', $input['contact_email']);
        $stmt->bindParam(':contact_phone', $input['contact_phone']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $companyId]);
        } else {
            throw new Exception('Failed to create company');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateCompany($db, $input) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Company ID required']);
        return;
    }
    
    $query = "UPDATE companies SET ";
    $fields = [];
    $params = [':id' => $input['id']];
    
    $allowedFields = ['name', 'license_number', 'address', 'contact_email', 'contact_phone', 'is_active'];
    
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
            throw new Exception('Failed to update company');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteCompany($db, $input) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Company ID required']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        // Check if company has active users or claims
        $checkQuery = "SELECT 
                          (SELECT COUNT(*) FROM users WHERE company_id = :id AND is_active = 1) as active_users,
                          (SELECT COUNT(*) FROM insurance_claims WHERE company_id = :id) as claims
                       FROM companies WHERE id = :id";
        
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $input['id']);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['active_users'] > 0 || $result['claims'] > 0) {
            $db->rollback();
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete company with active users or claims']);
            return;
        }
        
        // Delete company
        $query = "DELETE FROM companies WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $input['id']);
        
        if ($stmt->execute()) {
            $db->commit();
            echo json_encode(['success' => true]);
        } else {
            $db->rollback();
            throw new Exception('Failed to delete company');
        }
    } catch (PDOException $e) {
        $db->rollback();
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>