<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit();
}

require_once '../config/database.php';
require_once '../models/User.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'POST':
        if (isset($input['action'])) {
            switch($input['action']) {
                case 'login':
                    login($user, $input);
                    break;
                case 'logout':
                    logout();
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function login($user, $input) {
    if (!isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    $result = $user->login($input['email'], $input['password'], $input['twoFactorCode'] ?? null);
    
    if ($result['success']) {
        session_start();
        $_SESSION['user_id'] = $result['user']['id'];
        $_SESSION['user_role'] = $result['user']['role'];
        
        echo json_encode([
            'success' => true,
            'user' => $result['user'],
            'token' => session_id()
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => $result['message']]);
    }
}

function logout() {
    session_start();
    session_destroy();
    echo json_encode(['success' => true]);
}
?>