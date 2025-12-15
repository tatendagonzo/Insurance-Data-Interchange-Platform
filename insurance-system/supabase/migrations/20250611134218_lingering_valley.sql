-- Insurance Data Interchange System Database Schema
-- Run this in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS insurance_interchange;
USE insurance_interchange;

-- Companies table
CREATE TABLE companies (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    license_number VARCHAR(100) UNIQUE NOT NULL,
    address TEXT,
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    api_key VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users table
CREATE TABLE users (
    id VARCHAR(50) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'company_user', 'auditor') NOT NULL,
    company_id VARCHAR(50),
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

-- Insurance Claims table
CREATE TABLE insurance_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_number VARCHAR(50) UNIQUE NOT NULL,
    policy_number VARCHAR(100) NOT NULL,
    claimant_name VARCHAR(255) NOT NULL,
    claimant_email VARCHAR(255),
    claimant_phone VARCHAR(50),
    incident_date DATE NOT NULL,
    reported_date DATE NOT NULL,
    claim_type ENUM('auto', 'property', 'health', 'life', 'liability') NOT NULL,
    status ENUM('submitted', 'investigating', 'approved', 'denied', 'closed') DEFAULT 'submitted',
    description TEXT NOT NULL,
    estimated_amount DECIMAL(12,2) NOT NULL,
    approved_amount DECIMAL(12,2) NULL,
    company_id VARCHAR(50) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    assigned_to VARCHAR(255),
    sync_status ENUM('synced', 'pending', 'conflict', 'offline') DEFAULT 'synced',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_claims (company_id),
    INDEX idx_claim_number (claim_number),
    INDEX idx_claimant_name (claimant_name),
    INDEX idx_incident_date (incident_date)
);

-- Claim Attachments table
CREATE TABLE claim_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by VARCHAR(50) NOT NULL,
    encrypted BOOLEAN DEFAULT FALSE,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (claim_id) REFERENCES insurance_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Fraud Flags table
CREATE TABLE fraud_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    flag_type ENUM('duplicate_claim', 'suspicious_pattern', 'document_anomaly', 'amount_anomaly', 'timing_anomaly') NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    description TEXT NOT NULL,
    confidence DECIMAL(3,2) NOT NULL, -- 0.00 to 1.00
    flagged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    flagged_by VARCHAR(50) NOT NULL,
    reviewed BOOLEAN DEFAULT FALSE,
    reviewed_by VARCHAR(50) NULL,
    reviewed_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (claim_id) REFERENCES insurance_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (flagged_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_claim_flags (claim_id),
    INDEX idx_flag_type (flag_type),
    INDEX idx_severity (severity)
);

-- Data Interchange Requests table
CREATE TABLE data_interchange_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_company_id VARCHAR(50) NOT NULL,
    to_company_id VARCHAR(50) NOT NULL,
    claim_id INT NOT NULL,
    request_type ENUM('view', 'share', 'verify') NOT NULL,
    status ENUM('pending', 'approved', 'denied', 'expired') DEFAULT 'pending',
    requested_by VARCHAR(50) NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by VARCHAR(50) NULL,
    approved_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL,
    notes TEXT,
    FOREIGN KEY (from_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (to_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (claim_id) REFERENCES insurance_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Audit Logs table
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type ENUM('claim', 'user', 'company', 'attachment', 'fraud_flag') NOT NULL,
    resource_id VARCHAR(50) NOT NULL,
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_logs (user_id),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_timestamp (timestamp)
);

-- Sync Queue table (for offline functionality)
CREATE TABLE sync_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation ENUM('create', 'update', 'delete') NOT NULL,
    resource_type ENUM('claim', 'attachment', 'fraud_flag') NOT NULL,
    resource_id VARCHAR(50) NOT NULL,
    data JSON NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    retry_count INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    INDEX idx_status (status),
    INDEX idx_timestamp (timestamp)
);