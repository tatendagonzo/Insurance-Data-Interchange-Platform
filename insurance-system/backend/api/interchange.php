<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../middleware/auth.php';

// Check authentication
$auth = checkAuth();
if (!$auth['success']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'GET':
        getAllClaims($db, $auth['user']);
        break;
    case 'POST':
        createInterchangeRequest($db, $input, $auth['user']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getAllClaims($db, $user) {
    // Build query based on user role
    if ($user['role'] === 'admin' || $user['role'] === 'auditor') {
        $query = "SELECT c.*, comp.name as company_name
                  FROM insurance_claims c 
                  LEFT JOIN companies comp ON c.company_id = comp.id
                  ORDER BY c.created_at DESC";
        $stmt = $db->prepare($query);
    } else {
        $query = "SELECT c.id, c.claim_number, c.policy_number, c.claimant_name, 
                         c.incident_date, c.reported_date, c.claim_type, c.status, 
                         c.estimated_amount, c.description, c.company_id, 
                         comp.name as company_name, c.created_at
                  FROM insurance_claims c 
                  LEFT JOIN companies comp ON c.company_id = comp.id
                  ORDER BY c.created_at DESC";
        $stmt = $db->prepare($query);
    }
    
    try {
        $stmt->execute();
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC); // Added fetch mode
        
        // Log the access for security audit
        logAuditAction($db, $user, 'VIEW_ALL_CLAIMS', 'interchange', 'all_claims', [
            'claims_accessed' => count($claims),
            'access_type' => 'data_interchange',
            'user_role' => $user['role'],
            'company_id' => $user['company_id']
        ]);
        
        echo json_encode(['success' => true, 'claims' => $claims]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function createInterchangeRequest($db, $input, $user) {
    if (!isset($input['to_company']) || !isset($input['request_type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Company and request type are required']);
        return;
    }

    // Get the target company ID
    $query = "SELECT id FROM companies WHERE name = :company_name";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':company_name', $input['to_company']); // Changed to bindValue
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Company not found']);
        return;
    }
    
    $toCompany = $stmt->fetch(PDO::FETCH_ASSOC); // Added fetch mode

    $query = "INSERT INTO data_interchange_requests 
              (from_company_id, to_company_id, request_type, requested_by, expires_at, notes, requested_at)
              VALUES 
              (:from_company_id, :to_company_id, :request_type, :requested_by, :expires_at, :notes, NOW())";

    try {
        $stmt = $db->prepare($query);
        
        // Set expiration to 7 days from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Changed all bindParam to bindValue
        $stmt->bindValue(':from_company_id', $user['company_id']);
        $stmt->bindValue(':to_company_id', $toCompany['id']);
        $stmt->bindValue(':request_type', $input['request_type']);
        $stmt->bindValue(':requested_by', $user['id']);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->bindValue(':notes', $input['notes'] ?? null); // Handle optional notes
        
        if ($stmt->execute()) {
            $requestId = $db->lastInsertId();
            
            // Log the request for security audit
            logAuditAction($db, $user, 'CREATE_INTERCHANGE_REQUEST', 'interchange_request', $requestId, [
                'to_company' => $input['to_company'],
                'request_type' => $input['request_type'],
                'purpose' => $input['purpose'] ?? '',
                'notes' => $input['notes'] ?? '',
                'expires_at' => $expiresAt
            ]);
            
            echo json_encode(['success' => true, 'request_id' => $requestId]);
        } else {
            throw new Exception('Failed to create interchange request');
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function logAuditAction($db, $user, $action, $resourceType, $resourceId, $details) {
    $query = "INSERT INTO audit_logs 
              (user_id, user_name, action, resource_type, resource_id, details, ip_address, user_agent, timestamp)
              VALUES 
              (:user_id, :user_name, :action, :resource_type, :resource_id, :details, :ip_address, :user_agent, NOW())";
    
    try {
        $stmt = $db->prepare($query);
        
        // Precompute values
        $detailsJson = json_encode($details);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Changed all bindParam to bindValue
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':user_name', $user['name']);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId);
        $stmt->bindValue(':details', $detailsJson); // Bind precomputed JSON
        $stmt->bindValue(':ip_address', $ipAddress);
        $stmt->bindValue(':user_agent', $userAgent);
        
        $stmt->execute();
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}
?>