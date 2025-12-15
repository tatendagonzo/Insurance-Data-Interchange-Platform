-- Sample data for Insurance Data Interchange System
-- Run this after creating the schema

USE insurance_interchange;

-- Insert Companies
INSERT INTO companies (id, name, license_number, address, contact_email, contact_phone) VALUES
('sys', 'InsureSync System', 'SYS-001', '123 Tech Street, Digital City', 'admin@insuresync.com', '+1-555-0100'),
('acme', 'ACME Insurance', 'INS-001', '456 Insurance Ave, Coverage City', 'contact@acmeinsurance.com', '+1-555-0200'),
('safeguard', 'SafeGuard Insurance', 'INS-002', '789 Protection Blvd, Safety Town', 'info@safeguard.com', '+1-555-0300'),
('metlife', 'MetLife Partners', 'INS-003', '321 Partner Street, Alliance City', 'support@metlifepartners.com', '+1-555-0400'),
('gov', 'Regulatory Authority', 'REG-001', '999 Government Plaza, Capital City', 'audit@compliance.gov', '+1-555-0500');

-- Insert Users
INSERT INTO users (id, email, password_hash, name, role, company_id, two_factor_enabled) VALUES
('1', 'admin@insuresync.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 'sys', TRUE),
('2', 'manager@acmeinsurance.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Manager', 'company_user', 'acme', FALSE),
('3', 'auditor@compliance.gov', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Auditor', 'auditor', 'gov', TRUE),
('4', 'investigator@acmeinsurance.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Investigator', 'company_user', 'acme', FALSE),
('5', 'admin@safeguard.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah Admin', 'company_user', 'safeguard', TRUE);

-- Insert Sample Claims
INSERT INTO insurance_claims (claim_number, policy_number, claimant_name, claimant_email, claimant_phone, incident_date, reported_date, claim_type, status, description, estimated_amount, company_id, company_name) VALUES
('CLM-2024-001', 'POL-ABC-123', 'Alice Johnson', 'alice@email.com', '+1-555-0101', '2024-01-15', '2024-01-16', 'auto', 'investigating', 'Rear-end collision at intersection', 15000.00, 'acme', 'ACME Insurance'),
('CLM-2024-002', 'POL-XYZ-456', 'Bob Smith', 'bob@email.com', '+1-555-0102', '2024-01-18', '2024-01-19', 'property', 'approved', 'Water damage from burst pipe', 8500.00, 'acme', 'ACME Insurance'),
('CLM-2024-003', 'POL-DEF-789', 'Carol Davis', 'carol@email.com', '+1-555-0103', '2024-01-20', '2024-01-21', 'auto', 'submitted', 'Vehicle theft from parking lot', 25000.00, 'safeguard', 'SafeGuard Insurance'),
('CLM-2024-004', 'POL-GHI-012', 'David Wilson', 'david@email.com', '+1-555-0104', '2024-01-22', '2024-01-23', 'property', 'investigating', 'Fire damage to residential property', 45000.00, 'safeguard', 'SafeGuard Insurance'),
('CLM-2024-005', 'POL-JKL-345', 'Eva Brown', 'eva@email.com', '+1-555-0105', '2024-01-25', '2024-01-26', 'liability', 'denied', 'Slip and fall incident', 12000.00, 'acme', 'ACME Insurance');

-- Insert Sample Fraud Flags
INSERT INTO fraud_flags (claim_id, flag_type, severity, description, confidence, flagged_by) VALUES
(2, 'suspicious_pattern', 'medium', 'Claimant has history of frequent claims in short time periods', 0.78, '1'),
(3, 'amount_anomaly', 'high', 'Claim amount significantly exceeds typical range for incident type', 0.85, '1'),
(4, 'duplicate_claim', 'critical', 'Similar claim pattern detected across multiple companies', 0.92, '1');

-- Insert Sample Data Interchange Requests
INSERT INTO data_interchange_requests (from_company_id, to_company_id, claim_id, request_type, requested_by, expires_at, notes) VALUES
('acme', 'safeguard', 1, 'verify', '2', DATE_ADD(NOW(), INTERVAL 7 DAY), 'Request verification of similar claim patterns'),
('safeguard', 'acme', 3, 'share', '5', DATE_ADD(NOW(), INTERVAL 3 DAY), 'Cross-reference for fraud detection analysis');

-- Insert Sample Audit Logs
INSERT INTO audit_logs (user_id, user_name, action, resource_type, resource_id, details, ip_address) VALUES
('2', 'John Manager', 'CREATE', 'claim', '1', '{"claim_number": "CLM-2024-001", "amount": 15000}', '192.168.1.100'),
('1', 'System Administrator', 'FLAG_FRAUD', 'fraud_flag', '1', '{"claim_id": 2, "flag_type": "suspicious_pattern"}', '192.168.1.101'),
('3', 'Jane Auditor', 'VIEW', 'claim', '2', '{"accessed_fields": ["claimant_name", "amount", "status"]}', '192.168.1.102');