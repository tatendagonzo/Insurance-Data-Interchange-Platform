

<?php
// Get the origin of the request
$allowedOrigins = [
    'http://localhost:5173',  // Vite dev server
    'http://localhost',       // Direct access
    'http://localhost/insurance-system'  // XAMPP with subdirectory
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../models/Claim.php';
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
$claim = new Claim($db);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'GET':
        getClaims($claim, $auth['user']);
        break;
    case 'POST':
        createClaim($claim, $input, $auth['user']);
        break;
    case 'PUT':
        updateClaim($claim, $input, $auth['user']);
        break;
    case 'DELETE':
        deleteClaim($claim, $input, $auth['user']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getClaims($claim, $user) {
    $claims = $claim->getByCompany($user['company_id']);
    echo json_encode(['success' => true, 'claims' => $claims]);
}

function createClaim($claim, $input, $user) {
    $input['company_id'] = $user['company_id'];
    $input['company_name'] = $user['company_name'];
    
    $result = $claim->create($input);
    
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['message']]);
    }
}

function updateClaim($claim, $input, $user) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Claim ID required']);
        return;
    }
    
    $result = $claim->update($input['id'], $input, $user['company_id']);
    
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['message']]);
    }
}

function deleteClaim($claim, $input, $user) {
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Claim ID required']);
        return;
    }
    
    $result = $claim->delete($input['id'], $user['company_id']);
    
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['message']]);
    }
}
?>