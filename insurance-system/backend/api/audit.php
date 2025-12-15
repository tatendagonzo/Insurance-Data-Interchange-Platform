<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true"); // Added credentials header
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../middleware/auth.php';

// Check authentication and admin/auditor role
$auth = checkAuth();
if (!$auth['success'] || !in_array($auth['user']['role'], ['admin', 'auditor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admin or Auditor role required.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        getAuditLogs($db, $auth['user']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getAuditLogs($db, $user) {
    // Get audit logs with pagination and filtering
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $action_filter = isset($_GET['action']) ? $_GET['action'] : '';
    $resource_filter = isset($_GET['resource']) ? $_GET['resource'] : '';
    $user_filter = isset($_GET['user']) ? $_GET['user'] : '';
    
    $query = "SELECT al.*, 
                     CASE 
                         WHEN al.action LIKE '%LOGIN%' THEN 'User Authentication'
                         WHEN al.action LIKE '%CLAIM%' THEN 'Claims Management'
                         WHEN al.action LIKE '%DOWNLOAD%' THEN 'Data Export'
                         WHEN al.action LIKE '%VIEW%' THEN 'Data Access'
                         ELSE 'System Activity'
                     END as activity_category
              FROM audit_logs al 
              WHERE 1=1";
    
    $params = [];
    
    if ($action_filter) {
        $query .= " AND al.action LIKE :action";
        $params[':action'] = "%$action_filter%";
    }
    
    if ($resource_filter) {
        $query .= " AND al.resource_type = :resource_type";
        $params[':resource_type'] = $resource_filter;
    }
    
    if ($user_filter) {
        $query .= " AND al.user_name LIKE :user_name";
        $params[':user_name'] = "%$user_filter%";
    }
    
    $query .= " ORDER BY al.timestamp DESC LIMIT :limit OFFSET :offset";
    
    try {
        $stmt = $db->prepare($query);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        // Bind limit and offset as integers
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC); // Added fetch mode
        
        // Enhance logs with additional security information
        foreach ($logs as &$log) {
            // Safely decode JSON details
            $details = json_decode($log['details'], true) ?: [];
            
            // Add security risk assessment
            $log['security_level'] = assessSecurityLevel($log['action'], $details);
            
            // Only add these for relevant actions to reduce DB load
            if (strpos($log['action'], 'LOGIN') !== false) {
                $log['login_summary'] = getUserLoginSummary($db, $log['user_id']);
            }
            
            if (strpos($log['action'], 'CREATE_CLAIM') !== false) {
                $log['user_claims_count'] = getUserClaimsCount($db, $log['user_id']);
            }
        }
        
        // Get total count for pagination (using same filters)
        $countQuery = "SELECT COUNT(*) as total FROM audit_logs al WHERE 1=1";
        if ($action_filter) {
            $countQuery .= " AND al.action LIKE :action";
        }
        if ($resource_filter) {
            $countQuery .= " AND al.resource_type = :resource_type";
        }
        if ($user_filter) {
            $countQuery .= " AND al.user_name LIKE :user_name";
        }
        
        $countStmt = $db->prepare($countQuery);
        
        // Bind parameters again for count query
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        
        $countStmt->execute();
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = $totalResult['total'] ?? 0;
        
        // Log this audit access for security monitoring
        logAuditAction($db, $user, 'VIEW_AUDIT_LOGS', 'audit_log', 'bulk', [
            'filters' => [
                'action' => $action_filter,
                'resource' => $resource_filter,
                'user' => $user_filter
            ],
            'results_count' => count($logs),
            'access_level' => 'full_audit_access'
        ]);
        
        echo json_encode([
            'success' => true, 
            'logs' => $logs,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'security_summary' => getSecuritySummary($logs)
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function assessSecurityLevel($action, $details) {
    // Assess security risk level based on action type
    $highRiskActions = ['DELETE', 'DOWNLOAD_ALL', 'VIEW_ALL_CLAIMS', 'CREATE_USER'];
    $mediumRiskActions = ['UPDATE', 'CREATE_CLAIM', 'DOWNLOAD_CLAIMS'];
    $lowRiskActions = ['VIEW', 'LOGIN'];
    
    foreach ($highRiskActions as $riskAction) {
        if (strpos($action, $riskAction) !== false) {
            return 'HIGH';
        }
    }
    
    foreach ($mediumRiskActions as $riskAction) {
        if (strpos($action, $riskAction) !== false) {
            return 'MEDIUM';
        }
    }
    
    return 'LOW';
}

function getUserLoginSummary($db, $userId) {
    $query = "SELECT COUNT(*) as login_count,
                     MAX(timestamp) as last_login,
                     MIN(timestamp) as first_login
              FROM audit_logs 
              WHERE user_id = :user_id AND action LIKE '%LOGIN%'
              AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $userId);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserClaimsCount($db, $userId) {
    $query = "SELECT COUNT(*) as claims_submitted
              FROM audit_logs 
              WHERE user_id = :user_id AND action = 'CREATE_CLAIM'
              AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $userId);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['claims_submitted'] ?? 0;
}

function getSecuritySummary($logs) {
    $summary = [
        'total_activities' => count($logs),
        'high_risk_activities' => 0,
        'unique_users' => [],
        'top_actions' => [],
        'suspicious_patterns' => []
    ];
    
    $actionCounts = [];
    $userIds = [];
    
    foreach ($logs as $log) {
        // Count unique users
        if (!in_array($log['user_id'], $userIds)) {
            $userIds[] = $log['user_id'];
        }
        
        // Count high-risk activities
        if (isset($log['security_level']) && $log['security_level'] === 'HIGH') {
            $summary['high_risk_activities']++;
        }
        
        // Count actions
        $action = $log['action'];
        if (!isset($actionCounts[$action])) {
            $actionCounts[$action] = 0;
        }
        $actionCounts[$action]++;
    }
    
    // Get top 5 actions
    arsort($actionCounts);
    $summary['top_actions'] = array_slice($actionCounts, 0, 5, true);
    $summary['unique_users_count'] = count($userIds);
    
    return $summary;
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
        
        // Use bindValue instead of bindParam
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':user_name', $user['name']);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId);
        $stmt->bindValue(':details', $detailsJson);
        $stmt->bindValue(':ip_address', $ipAddress);
        $stmt->bindValue(':user_agent', $userAgent);
        
        $stmt->execute();
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}
?>