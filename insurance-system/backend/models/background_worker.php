<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Claim.php';

// Get claim ID from command line
$claimId = $argv[1] ?? null;

if ($claimId) {
    $database = new Database();
    $db = $database->getConnection();
    $claimModel = new Claim($db);
    
    // Fetch claim data
    $claimData = $claimModel->getById($claimId);
    
    if ($claimData) {
        $claimModel->runFraudDetection($claimId, $claimData);
    }
}
?>