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
    `node_type` ENUM('folder', 'document') NOT NULL DEFAULT 'folder',
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('active', 'hold', 'suspend', 'published', 'obsolete', 'rejected') NOT NULL DEFAULT 'active',
    `access_mode` ENUM('restricted', 'public') NOT NULL DEFAULT 'restricted',
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
    INDEX `idx_status` (`status`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_parent_deleted_status` (`parent_id`, `is_deleted`, `status`),
    CONSTRAINT `fk_dms_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_document_versions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_number` VARCHAR(20) NOT NULL DEFAULT 'v1.0',
    `file_path` VARCHAR(255) NOT NULL,
    `file_original_name` VARCHAR(255) NULL,
    `file_size` BIGINT UNSIGNED NULL,
    `file_extension` VARCHAR(20) NULL,
    `mime_type` VARCHAR(100) NULL,
    `file_hash` CHAR(64) NULL,
    `change_summary` TEXT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 1,
    `review_status` ENUM('draft', 'pending', 'approved', 'rejected', 'published', 'suspended') NOT NULL DEFAULT 'draft',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_doc_id` (`document_id`),
    INDEX `idx_doc_current` (`document_id`, `is_current`),
    CONSTRAINT `fk_doc_versions_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_doc_versions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_document_reviews` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version` VARCHAR(20) NOT NULL DEFAULT 'v0.1',
    `requested_by` BIGINT UNSIGNED NOT NULL,
    `reviewer_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `comments` TEXT NULL,
    `reviewed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_review_doc` (`document_id`, `status`),
    INDEX `idx_review_reviewer` (`reviewer_id`, `status`),
    KEY `fk_reviews_req_user` (`requested_by`),
    CONSTRAINT `fk_reviews_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_req_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_rev_user` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_document_contributors` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_doc_contributor` (`document_id`, `user_id`),
    INDEX `idx_contrib_user` (`user_id`),
    CONSTRAINT `fk_contrib_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_contrib_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_annotation_layers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED DEFAULT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `layer_name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) NOT NULL DEFAULT '#16a34a',
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `fk_layer_user` (`user_id`),
    INDEX `idx_doc_user` (`document_id`, `user_id`),
    INDEX `idx_layer_doc_ver` (`document_id`, `version_id`),
    CONSTRAINT `fk_layer_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_layer_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_annotations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `layer_id` BIGINT UNSIGNED NOT NULL,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED DEFAULT NULL,
    `page_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `tool_type` VARCHAR(50) NOT NULL DEFAULT 'pen',
    `payload_json` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_doc_page` (`document_id`, `page_number`),
    INDEX `fk_anno_layer` (`layer_id`),
    INDEX `idx_anno_doc_ver` (`document_id`, `version_id`),
    CONSTRAINT `fk_anno_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_anno_layer` FOREIGN KEY (`layer_id`) REFERENCES `dms_annotation_layers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_annotation_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED DEFAULT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `action_type` ENUM('create', 'update', 'delete', 'clear') NOT NULL,
    `tool_type` VARCHAR(50) DEFAULT NULL,
    `page_number` INT UNSIGNED DEFAULT 1,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hist_doc` (`document_id`),
    INDEX `idx_hist_user` (`user_id`),
    INDEX `idx_hist_doc_ver` (`document_id`, `version_id`),
    CONSTRAINT `fk_hist_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dms_document_comments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `page_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `content` TEXT NOT NULL,
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `resolved_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_comment_doc_page` (`document_id`, `page_number`),
    INDEX `idx_comment_parent` (`parent_id`),
    CONSTRAINT `fk_comment_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comment_parent` FOREIGN KEY (`parent_id`) REFERENCES `dms_document_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `username` VARCHAR(50) NULL,
    `role` VARCHAR(50) NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `details` LONGTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sys_action` (`action`),
    INDEX `idx_sys_user` (`user_id`, `username`),
    INDEX `idx_sys_created_at` (`created_at`),
    INDEX `idx_sys_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_sys_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INITIAL SEED DATA
-- ============================================================

-- 1. Default Super Admin User
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `department`)
VALUES (1, 'admin', '$2y$10$e8k8R7i31i7qC0Z1QvYqE.M7yK2E07g3eZ2K7Z1Y1Z1Y1Z1Y1Z1Y1', 'Super Admin', 'super admin', 'Engineering')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- 2. Sample Root Folders
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `status`, `version`, `created_by`) VALUES
(1, NULL, 'folder', 'Project Alpha - WMS Upgrade', 'active', 'v0.1', 1),
(2, NULL, 'folder', 'Project Beta - Cloud Migration', 'active', 'v0.1', 1),
(3, NULL, 'folder', 'Standard Operating Procedures', 'active', 'v0.1', 1),
(4, NULL, 'folder', 'Technical Drawings', 'active', 'v0.1', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Sample Subfolders inside Project Alpha
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `status`, `version`, `created_by`) VALUES
(5, 1, 'folder', 'Architecture & Design', 'active', 'v0.1', 1),
(6, 1, 'folder', 'Specifications & Requirements', 'active', 'v0.1', 1),
(7, 5, 'folder', 'Database Schemas', 'active', 'v0.1', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. Sample Documents
INSERT INTO `dms_nodes` (`id`, `parent_id`, `node_type`, `name`, `status`, `version`, `file_extension`, `mime_type`, `created_by`) VALUES
(8, 7, 'document', 'EDMS_Relational_Schema_v0.1.pdf', 'active', 'v0.1', 'pdf', 'application/pdf', 1),
(9, 6, 'document', 'System_Requirement_Specification.docx', 'published', 'v0.2', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 1),
(10, 3, 'document', 'SOP_Document_Control_Standard.pdf', 'published', 'v1.0', 'pdf', 'application/pdf', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
