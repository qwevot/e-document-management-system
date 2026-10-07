-- ============================================================
-- EDMS DATABASE SCHEMA: edms_db
-- Unified Node / Virtual File System Tree Architecture
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('super admin', 'admin', 'engineer', 'technician', 'viewer') NOT NULL DEFAULT 'viewer',
    `department` VARCHAR(50) NULL,
    `avatar` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_nodes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `node_type` ENUM('project', 'doctype', 'folder', 'document') NOT NULL DEFAULT 'folder',
    `name` VARCHAR(255) NOT NULL,
    `doc_type` VARCHAR(50) NULL DEFAULT NULL,
    `status` ENUM('active', 'hold', 'suspend', 'published', 'obsolete') NOT NULL DEFAULT 'active',
    `version` VARCHAR(20) NOT NULL DEFAULT 'v0.1',
    `file_path` VARCHAR(255) NULL DEFAULT NULL,
    `file_original_name` VARCHAR(255) NULL DEFAULT NULL,
    `file_size` BIGINT UNSIGNED NULL DEFAULT NULL,
    `file_extension` VARCHAR(20) NULL DEFAULT NULL,
    `mime_type` VARCHAR(100) NULL DEFAULT NULL,
    `file_hash` CHAR(64) NULL DEFAULT NULL,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `locked_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_node_type` (`node_type`),
    INDEX `idx_doc_type` (`doc_type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_created_by` (`created_by`),
    CONSTRAINT `fk_dms_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INITIAL SEED DATA
-- ============================================================

-- 1. Default Super Admin User
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `department`)
VALUES (1, 'admin', '$2y$10$e8k8R7i31i7qC0Z1QvYqE.M7yK2E07g3eZ2K7Z1Y1Z1Y1Z1Y1Z1Y1', 'Super Admin', 'super admin', 'Engineering')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- 2. Sample Project Roots & DocType Roots
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `status`, `version`, `created_by`) VALUES
(1, NULL, 'project', 'Project Alpha - WMS Upgrade', 'active', 'v0.1', 1),
(2, NULL, 'project', 'Project Beta - Cloud Migration', 'active', 'v0.1', 1),
(3, NULL, 'doctype', 'Standard Operating Procedures', 'active', 'v0.1', 1),
(4, NULL, 'doctype', 'Technical Drawings', 'active', 'v0.1', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Sample Subfolders inside Project Alpha
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `status`, `version`, `created_by`) VALUES
(5, 1, 'folder', 'Architecture & Design', 'active', 'v0.1', 1),
(6, 1, 'folder', 'Specifications & Requirements', 'active', 'v0.1', 1),
(7, 5, 'folder', 'Database Schemas', 'active', 'v0.1', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. Sample Documents
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `doc_type`, `status`, `version`, `file_extension`, `mime_type`, `created_by`) VALUES
(8, 7, 'document', 'EDMS_Relational_Schema_v0.1.pdf', 'Specification', 'active', 'v0.1', 'pdf', 'application/pdf', 1),
(9, 6, 'document', 'System_Requirement_Specification.docx', 'Specification', 'published', 'v0.2', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 1),
(10, 3, 'document', 'SOP_Document_Control_Standard.pdf', 'SOP', 'published', 'v1.0', 'pdf', 'application/pdf', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
