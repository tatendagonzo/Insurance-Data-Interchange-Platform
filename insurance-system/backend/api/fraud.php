<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true"); // Added credentials header
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
        if (isset($_GET['action']) && $_GET['action'] === 'check_similar' && !empty($_GET['name'])) {
            checkSimilarClaims($db, $_GET['name'], $auth['user']);
        } else {
            getFraudFlags($db, $auth['user']);
        }
        break;
    case 'PUT':
        updateFraudFlag($db, $input, $auth['user']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getFraudFlags($db, $user) {
    try {
        // Build query based on user role
        if ($user['role'] === 'admin' || $user['role'] === 'auditor') {
            // Admin and auditors can see all fraud flags
            $query = "SELECT f.*, c.claim_number, c.claimant_name, c.estimated_amount, c.claim_type,
                             comp.name as company_name
                      FROM fraud_flags f 
                      LEFT JOIN insurance_claims c ON f.claim_id = c.id
                      LEFT JOIN companies comp ON c.company_id = comp.id
                      ORDER BY f.flagged_at DESC";
            $stmt = $db->prepare($query);
        } else {
            // Company users can only see flags for their company's claims
            $query = "SELECT f.*, c.claim_number, c.claimant_name, c.estimated_amount, c.claim_type,
                             comp.name as company_name
                      FROM fraud_flags f 
                      LEFT JOIN insurance_claims c ON f.claim_id = c.id
                      LEFT JOIN companies comp ON c.company_id = comp.id
                      WHERE c.company_id = :company_id
                      ORDER BY f.flagged_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':company_id', $user['company_id']);
        }
        
        $stmt->execute();
        $flags = $stmt->fetchAll(PDO::FETCH_ASSOC); // Added fetch mode
        
        // Log the access for security audit
        logAuditAction($db, $user, 'VIEW_FRAUD_FLAGS', 'fraud', 'all_flags', [
            'flags_accessed' => count($flags),
            'user_role' => $user['role'],
            'company_id' => $user['company_id']
        ]);
        
        echo json_encode(['success' => true, 'flags' => $flags]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateFraudFlag($db, $input, $user) {
    if (!isset($input['id']) || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Flag ID and action required']);
        return;
    }
    
    if ($input['action'] === 'review') {
        // Mark fraud flag as reviewed
        $query = "UPDATE fraud_flags SET 
                  reviewed = 1, 
                  reviewed_by = :reviewed_by, 
                  reviewed_at = NOW(),
                  notes = :notes
                  WHERE id = :id";
        
        try {
            $stmt = $db->prepare($query);
            
            // Changed to bindValue and handle optional notes
            $notes = $input['notes'] ?? '';
            
            $stmt->bindValue(':id', $input['id']);
            $stmt->bindValue(':reviewed_by', $user['name']);
            $stmt->bindValue(':notes', $notes);
            
            if ($stmt->execute()) {
                // Log the audit trail
                logAuditAction($db, $user, 'REVIEW_FRAUD_FLAG', 'fraud_flag', $input['id'], [
                    'notes' => $notes
                ]);
                
                echo json_encode(['success' => true]);
            } else {
                throw new Exception('Failed to update fraud flag');
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
}

function checkSimilarClaims($db, $name, $user) {
    try {
        // First, clean and prepare the search name
        $searchName = trim($name);
        $searchNameParts = array_filter(explode(' ', $searchName));
        $searchName = implode(' ', $searchNameParts); // Rebuild with single spaces
        
        // Calculate minimum similarity threshold (adjust as needed)
        $minSimilarity = 60; // 60% similarity threshold
        
        // Build the query with multiple matching techniques
        $query = "SELECT 
                    c.id, 
                    c.claim_number, 
                    c.claimant_name, 
                    c.incident_date, 
                    c.estimated_amount, 
                    comp.name as company_name,
                    ROUND((
                        (LENGTH(c.claimant_name) + LENGTH(:search_name) - 
                         LEVENSHTEIN(c.claimant_name, :search_name)) * 100 / 
                        (LENGTH(c.claimant_name) + LENGTH(:search_name))
                    ), 2) as similarity
                 FROM insurance_claims c
                 LEFT JOIN companies comp ON c.company_id = comp.id
                 WHERE 
                    -- Check if the search name is contained within the claimant name
                    c.claimant_name LIKE CONCAT('%', :search_name, '%')";
        
        // Add OR conditions for each name part
        foreach ($searchNameParts as $index => $part) {
            if (strlen($part) > 2) { // Only add parts longer than 2 characters
                $param = ':part' . $index;
                $query .= " OR c.claimant_name LIKE CONCAT('%', $param, '%')";
            }
        }
        
        // Add soundex matching for phonetic similarity
        $query .= " OR SOUNDEX(c.claimant_name) = SOUNDEX(:search_name)";
        
        // Filter by minimum similarity and order by best matches first
        $query .= " HAVING similarity >= :min_similarity
                  ORDER BY similarity DESC
                  LIMIT 20";
        
        $stmt = $db->prepare($query);
        
        // Bind the main search parameter
        $stmt->bindValue(':search_name', $searchName);
        
        // Bind each name part parameter
        foreach ($searchNameParts as $index => $part) {
            if (strlen($part) > 2) {
                $stmt->bindValue(':part' . $index, $part);
            }
        }
        
        // Bind the minimum similarity threshold
        $stmt->bindValue(':min_similarity', $minSimilarity);
        
        $stmt->execute();
        $similarClaims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log the search for auditing
        logAuditAction($db, $user, 'SEARCH_SIMILAR_CLAIMS', 'fraud', 'similar_claims', [
            'search_name' => $searchName,
            'results_count' => count($similarClaims),
            'min_similarity' => $minSimilarity
        ]);
        
        echo json_encode([
            'success' => true, 
            'similar_claims' => $similarClaims,
            'search_term' => $searchName
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
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
        $detailsJson = is_array($details) ? json_encode($details) : $details;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':user_name', $user['name']);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId);
        $stmt->bindValue(':details', $detailsJson);
        $stmt->bindValue(':ip_address', $ipAddress);
        $stmt->bindValue(':user_agent', $userAgent);
        
        return $stmt->execute();
    } catch (PDOException $e) {
        // Fail silently for audit logging
        error_log('Audit log error: ' . $e->getMessage());
        return false;
    }
}
?>