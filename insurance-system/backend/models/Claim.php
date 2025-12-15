<?php
class Claim {
    private $conn;
    private $table_name = "insurance_claims";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getByCompany($companyId) {
        $query = "SELECT c.*, 
                         COUNT(f.id) as fraud_flags_count,
                         GROUP_CONCAT(f.flag_type) as fraud_flag_types
                  FROM " . $this->table_name . " c 
                  LEFT JOIN fraud_flags f ON c.id = f.claim_id AND f.reviewed = 0
                  WHERE c.company_id = :company_id 
                  GROUP BY c.id
                  ORDER BY c.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':company_id', $companyId);
        $stmt->execute();
        
        $claims = [];
        while ($row = $stmt->fetch()) {
            $row['fraud_flags'] = [];
            if ($row['fraud_flags_count'] > 0) {
                $row['fraud_flags'] = $this->getFraudFlags($row['id']);
            }
            unset($row['fraud_flags_count'], $row['fraud_flag_types']);
            $claims[] = $row;
        }
        
        return $claims;
    }

    public function create($data) {
        // Enhanced security: Check for duplicate claims first
        $duplicateCheck = $this->checkForDuplicates($data);
        if ($duplicateCheck['isDuplicate']) {
            // Log potential fraud attempt
            $this->logSecurityEvent('DUPLICATE_CLAIM_ATTEMPT', $data, [
                'similar_claim' => $duplicateCheck['claimNumber'],
                'risk_level' => 'HIGH'
            ]);
            
            return [
                'success' => false, 
                'message' => 'Potential duplicate claim detected. Similar claim found: ' . $duplicateCheck['claimNumber'],
                'fraud_alert' => true
            ];
        }

        $query = "INSERT INTO " . $this->table_name . " 
                  (claim_number, policy_number, claimant_name, claimant_email, claimant_phone,
                   incident_date, reported_date, claim_type, status, description, estimated_amount,
                   company_id, company_name, created_at, updated_at, sync_status)
                  VALUES 
                  (:claim_number, :policy_number, :claimant_name, :claimant_email, :claimant_phone,
                   :incident_date, :reported_date, :claim_type, :status, :description, :estimated_amount,
                   :company_id, :company_name, NOW(), NOW(), 'synced')";

        try {
            $stmt = $this->conn->prepare($query);
            
            // Generate claim number if not provided
            if (!isset($data['claim_number'])) {
                $data['claim_number'] = 'CLM-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            
            $stmt->bindParam(':claim_number', $data['claim_number']);
            $stmt->bindParam(':policy_number', $data['policy_number']);
            $stmt->bindParam(':claimant_name', $data['claimant_name']);
            $stmt->bindParam(':claimant_email', $data['claimant_email']);
            $stmt->bindParam(':claimant_phone', $data['claimant_phone']);
            $stmt->bindParam(':incident_date', $data['incident_date']);
            $stmt->bindParam(':reported_date', $data['reported_date']);
            $stmt->bindParam(':claim_type', $data['claim_type']);
            $stmt->bindParam(':status', $data['status']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->bindParam(':estimated_amount', $data['estimated_amount']);
            $stmt->bindParam(':company_id', $data['company_id']);
            $stmt->bindParam(':company_name', $data['company_name']);
            
            if ($stmt->execute()) {
                $claimId = $this->conn->lastInsertId();
                
                // Run enhanced fraud detection
                $this->runFraudDetection($claimId, $data);
                
                // Log claim creation for audit
                $this->logSecurityEvent('CLAIM_CREATED', $data, [
                    'claim_id' => $claimId,
                    'claim_number' => $data['claim_number'],
                    'amount' => $data['estimated_amount'],
                    'type' => $data['claim_type']
                ]);
                
                return ['success' => true, 'id' => $claimId, 'claim_number' => $data['claim_number']];
            }
        } catch (PDOException $e) {
            // Log database errors for security monitoring
            $this->logSecurityEvent('CLAIM_CREATION_ERROR', $data, [
                'error' => $e->getMessage(),
                'risk_level' => 'MEDIUM'
            ]);
            
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        return ['success' => false, 'message' => 'Failed to create claim'];
    }

    private function checkForDuplicates($data) {
        // Enhanced duplicate detection with multiple criteria
        
        // Check for exact duplicate by claimant name and policy number
        $query = "SELECT claim_number FROM " . $this->table_name . " 
                  WHERE claimant_name = :claimant_name 
                  AND policy_number = :policy_number 
                  AND incident_date = :incident_date
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':claimant_name', $data['claimant_name']);
        $stmt->bindParam(':policy_number', $data['policy_number']);
        $stmt->bindParam(':incident_date', $data['incident_date']);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch();
            return ['isDuplicate' => true, 'claimNumber' => $result['claim_number']];
        }

        // Check for similar claims (same claimant, similar amount, recent date)
        $query = "SELECT claim_number FROM " . $this->table_name . " 
                  WHERE claimant_name = :claimant_name 
                  AND ABS(estimated_amount - :amount) < 1000 
                  AND incident_date >= DATE_SUB(:incident_date, INTERVAL 30 DAY)
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':claimant_name', $data['claimant_name']);
        $stmt->bindParam(':amount', $data['estimated_amount']);
        $stmt->bindParam(':incident_date', $data['incident_date']);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch();
            return ['isDuplicate' => true, 'claimNumber' => $result['claim_number']];
        }

        // Check for suspicious email patterns
        if (isset($data['claimant_email'])) {
            $query = "SELECT claim_number FROM " . $this->table_name . " 
                      WHERE claimant_email = :email 
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $data['claimant_email']);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch();
                return ['isDuplicate' => true, 'claimNumber' => $result['claim_number']];
            }
        }

        return ['isDuplicate' => false];
    }

    public function update($id, $data, $companyId) {
        // Verify claim belongs to company for security
        if (!$this->verifyOwnership($id, $companyId)) {
            $this->logSecurityEvent('UNAUTHORIZED_CLAIM_ACCESS', $data, [
                'claim_id' => $id,
                'attempted_company' => $companyId,
                'risk_level' => 'HIGH'
            ]);
            return ['success' => false, 'message' => 'Unauthorized'];
        }
        
        $query = "UPDATE " . $this->table_name . " SET ";
        $fields = [];
        $params = [':id' => $id];
        
        $allowedFields = ['status', 'description', 'estimated_amount', 'approved_amount', 'assigned_to'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return ['success' => false, 'message' => 'No valid fields to update'];
        }
        
        $query .= implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute($params)) {
                // Log the update for audit
                $this->logSecurityEvent('CLAIM_UPDATED', $data, [
                    'claim_id' => $id,
                    'updated_fields' => array_keys($data),
                    'company_id' => $companyId
                ]);
                
                return ['success' => true];
            }
        } catch (PDOException $e) {
            $this->logSecurityEvent('CLAIM_UPDATE_ERROR', $data, [
                'claim_id' => $id,
                'error' => $e->getMessage(),
                'risk_level' => 'MEDIUM'
            ]);
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        return ['success' => false, 'message' => 'Failed to update claim'];
    }

    public function delete($id, $companyId) {
        // Verify claim belongs to company for security
        if (!$this->verifyOwnership($id, $companyId)) {
            $this->logSecurityEvent('UNAUTHORIZED_CLAIM_DELETE', [], [
                'claim_id' => $id,
                'attempted_company' => $companyId,
                'risk_level' => 'CRITICAL'
            ]);
            return ['success' => false, 'message' => 'Unauthorized'];
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Delete related fraud flags
            $query = "DELETE FROM fraud_flags WHERE claim_id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            // Delete claim
            $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $this->conn->commit();
                
                // Log the deletion for audit
                $this->logSecurityEvent('CLAIM_DELETED', [], [
                    'claim_id' => $id,
                    'company_id' => $companyId,
                    'risk_level' => 'HIGH'
                ]);
                
                return ['success' => true];
            }
            
            $this->conn->rollback();
        } catch (PDOException $e) {
            $this->conn->rollback();
            $this->logSecurityEvent('CLAIM_DELETE_ERROR', [], [
                'claim_id' => $id,
                'error' => $e->getMessage(),
                'risk_level' => 'HIGH'
            ]);
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        return ['success' => false, 'message' => 'Failed to delete claim'];
    }
    
    private function verifyOwnership($claimId, $companyId) {
        $query = "SELECT id FROM " . $this->table_name . " WHERE id = :id AND company_id = :company_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $claimId);
        $stmt->bindParam(':company_id', $companyId);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    private function getFraudFlags($claimId) {
        $query = "SELECT * FROM fraud_flags WHERE claim_id = :claim_id ORDER BY flagged_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':claim_id', $claimId);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    private function runFraudDetection($claimId, $claimData) {
        // Enhanced fraud detection with more sophisticated rules
        $flags = [];
        
        // Check for similar claimant names in existing claims
        $query = "SELECT id, claimant_name FROM " . $this->table_name . " 
                 WHERE id != :id 
                 AND company_id = :company_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $claimId);
        $stmt->bindParam(':company_id', $claimData['company_id']);
        $stmt->execute();
        
        $similarClaims = [];
        $currentName = strtolower(trim($claimData['claimant_name']));
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingName = strtolower(trim($row['claimant_name']));
            similar_text($currentName, $existingName, $percent);
            
            if ($percent > 75) { // 75% similarity threshold
                $similarClaims[] = [
                    'claim_id' => $row['id'],
                    'name' => $row['claimant_name'],
                    'similarity' => round($percent, 2)
                ];
            }
        }
        
        if (!empty($similarClaims)) {
            $similarCount = count($similarClaims);
            $similarNames = array_column($similarClaims, 'name');
            $similarityScores = array_column($similarClaims, 'similarity');
            $maxSimilarity = max($similarityScores);
            
            $severity = $maxSimilarity > 90 ? 'high' : 'medium';
            $confidence = min(0.3 + ($maxSimilarity / 200), 0.9); // Scale confidence with similarity
            
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'similar_claimant',
                'severity' => $severity,
                'description' => "Found $similarCount claims with similar claimant names (up to $maxSimilarity% similar)",
                'confidence' => $confidence,
                'metadata' => json_encode([
                    'similar_claims' => $similarClaims,
                    'current_claimant' => $claimData['claimant_name']
                ])
            ];
        }
        
        // Check for high amount
        if ($claimData['estimated_amount'] > 50000) {
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'amount_anomaly',
                'severity' => 'high',
                'description' => 'Claim amount exceeds typical threshold ($50,000)',
                'confidence' => 0.8
            ];
        }
        
        // Check for very high amount (critical threshold)
        if ($claimData['estimated_amount'] > 100000) {
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'amount_anomaly',
                'severity' => 'critical',
                'description' => 'Claim amount exceeds critical threshold ($100,000)',
                'confidence' => 0.95
            ];
        }
        
        // Check for duplicate claims (same claimant, similar amount, recent date)
        $query = "SELECT COUNT(*) as count, claim_number FROM " . $this->table_name . " 
                  WHERE claimant_name = :name 
                  AND ABS(estimated_amount - :amount) < 1000 
                  AND incident_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND id != :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $claimData['claimant_name']);
        $stmt->bindParam(':amount', $claimData['estimated_amount']);
        $stmt->bindParam(':id', $claimId);
        $stmt->execute();
        
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'duplicate_claim',
                'severity' => 'critical',
                'description' => 'Similar claim found for same claimant within 30 days',
                'confidence' => 0.9
            ];
        }

        // Check for suspicious timing (claim reported too quickly after incident)
        $incidentDate = new DateTime($claimData['incident_date']);
        $reportedDate = new DateTime($claimData['reported_date']);
        $daysDiff = $reportedDate->diff($incidentDate)->days;
        
        if ($daysDiff == 0) {
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'timing_anomaly',
                'severity' => 'medium',
                'description' => 'Claim reported on same day as incident',
                'confidence' => 0.6
            ];
        }

        // Check for frequent claimant
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE claimant_name = :name 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
                  AND id != :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $claimData['claimant_name']);
        $stmt->bindParam(':id', $claimId);
        $stmt->execute();
        
        $result = $stmt->fetch();
        if ($result['count'] >= 3) {
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'suspicious_pattern',
                'severity' => 'high',
                'description' => 'Claimant has submitted multiple claims this year (' . $result['count'] . ' claims)',
                'confidence' => 0.85
            ];
        }

        // Check for weekend/holiday incidents (potentially suspicious)
        $incidentDayOfWeek = $incidentDate->format('N'); // 1 (Monday) to 7 (Sunday)
        if ($incidentDayOfWeek >= 6) { // Saturday or Sunday
            $flags[] = [
                'claim_id' => $claimId,
                'flag_type' => 'timing_anomaly',
                'severity' => 'low',
                'description' => 'Incident occurred on weekend',
                'confidence' => 0.4
            ];
        }

        // Check for suspicious email domains
        if (isset($claimData['claimant_email'])) {
            $suspiciousDomains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com'];
            $emailDomain = substr(strrchr($claimData['claimant_email'], "@"), 1);
            
            if (in_array($emailDomain, $suspiciousDomains)) {
                $flags[] = [
                    'claim_id' => $claimId,
                    'flag_type' => 'document_anomaly',
                    'severity' => 'medium',
                    'description' => 'Claimant using temporary email service',
                    'confidence' => 0.7
                ];
            }
        }
        
        // Insert fraud flags
        foreach ($flags as $flag) {
            $this->insertFraudFlag($flag);
        }

        // Log fraud detection results
        if (count($flags) > 0) {
            $this->logSecurityEvent('FRAUD_FLAGS_GENERATED', $claimData, [
                'claim_id' => $claimId,
                'flags_count' => count($flags),
                'highest_severity' => $this->getHighestSeverity($flags),
                'flag_types' => array_column($flags, 'flag_type')
            ]);
        }
    }
    
    private function getHighestSeverity($flags) {
        $severityLevels = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $highest = 0;
        $highestSeverity = 'low';
        
        foreach ($flags as $flag) {
            if ($severityLevels[$flag['severity']] > $highest) {
                $highest = $severityLevels[$flag['severity']];
                $highestSeverity = $flag['severity'];
            }
        }
        
        return $highestSeverity;
    }
    
    private function insertFraudFlag($flag) {
        $query = "INSERT INTO fraud_flags 
                  (claim_id, flag_type, severity, description, confidence, flagged_at, flagged_by, reviewed)
                  VALUES 
                  (:claim_id, :flag_type, :severity, :description, :confidence, NOW(), 'system', 0)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':claim_id', $flag['claim_id']);
        $stmt->bindParam(':flag_type', $flag['flag_type']);
        $stmt->bindParam(':severity', $flag['severity']);
        $stmt->bindParam(':description', $flag['description']);
        $stmt->bindParam(':confidence', $flag['confidence']);
        $stmt->execute();
    }
    
    private function logSecurityEvent($action, $data, $details) {
        $query = "INSERT INTO audit_logs 
                  (user_id, user_name, action, resource_type, resource_id, details, ip_address, user_agent, timestamp)
                  VALUES 
                  ('system', 'System', :action, 'claim', :resource_id, :details, :ip_address, :user_agent, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':resource_id', $details['claim_id'] ?? 'unknown');
            $stmt->bindParam(':details', json_encode($details));
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? 'Unknown');
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $stmt->execute();
        } catch (PDOException $e) {
            // Log error but don't fail the main operation
            error_log('Security audit log error: ' . $e->getMessage());
        }
    }
}
?>