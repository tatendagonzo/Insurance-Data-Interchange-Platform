<?php
class User {
    private $conn;
    private $table_name = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function login($email, $password, $twoFactorCode = null) {
        $query = "SELECT u.*, c.name as company_name 
                  FROM " . $this->table_name . " u 
                  LEFT JOIN companies c ON u.company_id = c.id 
                  WHERE u.email = :email AND u.is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':email', $email);  // Changed to bindValue
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch();
            
            // For demo purposes, accept any password with length >= 6
            if (strlen($password) >= 6) {
                // Check 2FA if enabled
                if ($user['two_factor_enabled'] && !$twoFactorCode) {
                    return ['success' => false, 'message' => 'Two-factor authentication code required'];
                }
                
                if ($user['two_factor_enabled'] && $twoFactorCode && $twoFactorCode !== '123456') {
                    return ['success' => false, 'message' => 'Invalid two-factor authentication code'];
                }
                
                // Update last login
                $this->updateLastLogin($user['id']);
                
                // Log the login for security audit
                $this->logAuditAction($user, 'USER_LOGIN', 'session', 'login', [
                    'login_method' => $user['two_factor_enabled'] ? '2FA' : 'password',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    'company_id' => $user['company_id'],
                    'role' => $user['role']
                ]);
                
                // Remove sensitive data
                unset($user['password_hash']);
                
                return [
                    'success' => true,
                    'user' => $user
                ];
            }
        }
        
        // Log failed login attempt for security monitoring
        $this->logFailedLogin($email);
        
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    
    private function updateLastLogin($userId) {
        $query = "UPDATE " . $this->table_name . " SET last_login = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $userId);  // Changed to bindValue
        $stmt->execute();
    }
    
    private function logAuditAction($user, $action, $resourceType, $resourceId, $details) {
        $query = "INSERT INTO audit_logs 
                  (user_id, user_name, action, resource_type, resource_id, details, ip_address, user_agent, timestamp)
                  VALUES 
                  (:user_id, :user_name, :action, :resource_type, :resource_id, :details, :ip_address, :user_agent, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            // Convert details to JSON string
            $detailsJson = json_encode($details);
            
            // Get server values
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Use bindValue instead of bindParam
            $stmt->bindValue(':user_id', $user['id']);
            $stmt->bindValue(':user_name', $user['name']);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':resource_type', $resourceType);
            $stmt->bindValue(':resource_id', $resourceId);
            $stmt->bindValue(':details', $detailsJson);  // Bind JSON string
            $stmt->bindValue(':ip_address', $ipAddress);
            $stmt->bindValue(':user_agent', $userAgent);
            
            $stmt->execute();
        } catch (PDOException $e) {
            // Log error but don't fail the main operation
            error_log('Audit log error: ' . $e->getMessage());
        }
    }
    
    private function logFailedLogin($email) {
        $query = "INSERT INTO audit_logs 
                  (user_id, user_name, action, resource_type, resource_id, details, ip_address, user_agent, timestamp)
                  VALUES 
                  ('unknown', :email, 'FAILED_LOGIN', 'session', 'failed_login', :details, :ip_address, :user_agent, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            // Prepare details as JSON
            $detailsArray = [
                'attempted_email' => $email,
                'failure_reason' => 'invalid_credentials',
                'security_alert' => true
            ];
            $detailsJson = json_encode($detailsArray);
            
            // Get server values
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Use bindValue instead of bindParam
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':details', $detailsJson);  // Bind JSON string
            $stmt->bindValue(':ip_address', $ipAddress);
            $stmt->bindValue(':user_agent', $userAgent);
            
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('Failed login audit error: ' . $e->getMessage());
        }
    }
}
?>