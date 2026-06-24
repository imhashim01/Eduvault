-- =====================================================
--  EduVault – database/eduvault.sql
--  Complete schema + sample data
-- =====================================================

CREATE DATABASE IF NOT EXISTS `eduvault`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `eduvault`;

-- =====================================================
--  users
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `first_name`     VARCHAR(80)     NOT NULL,
  `last_name`      VARCHAR(80)     NOT NULL,
  `email`          VARCHAR(255)    NOT NULL,
  `password_hash`  VARCHAR(255)    NOT NULL,
  `role`           ENUM('student','admin') NOT NULL DEFAULT 'student',
  `student_id`     VARCHAR(50)     DEFAULT NULL,
  `institution`    VARCHAR(255)    DEFAULT NULL,
  `department`     VARCHAR(100)    DEFAULT NULL,
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(64)     DEFAULT NULL,
  `token_expires`  DATETIME        DEFAULT NULL,
  `last_login`     DATETIME        DEFAULT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE  KEY `uq_email`      (`email`),
  UNIQUE  KEY `uq_student_id` (`student_id`),
  INDEX   `idx_role`          (`role`),
  INDEX   `idx_department`    (`department`),
  INDEX   `idx_active`        (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  submissions
-- =====================================================
CREATE TABLE IF NOT EXISTS `submissions` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `title`             VARCHAR(255) NOT NULL,
  `course_code`       VARCHAR(30)  NOT NULL,
  `assignment_type`   ENUM('Lab Report','Research Paper','Project','Thesis Chapter','Essay','Other')
                                   NOT NULL DEFAULT 'Other',
  `description`       TEXT         DEFAULT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename`   VARCHAR(255) NOT NULL,
  `file_size`         INT UNSIGNED NOT NULL DEFAULT 0,
  `mime_type`         VARCHAR(100) DEFAULT NULL,
  `extracted_text`    LONGTEXT     DEFAULT NULL,
  `similarity_score`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `ai_probability`    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `risk_level`        ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  `status`            ENUM('pending','approved','flagged','rejected') NOT NULL DEFAULT 'pending',
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user`   (`user_id`),
  INDEX `idx_course` (`course_code`),
  INDEX `idx_risk`   (`risk_level`),
  INDEX `idx_status` (`status`),
  INDEX `idx_score`  (`similarity_score`),
  CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  similarity_matches
-- =====================================================
CREATE TABLE IF NOT EXISTS `similarity_matches` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`         INT UNSIGNED NOT NULL,
  `matched_submission_id` INT UNSIGNED DEFAULT NULL,
  `matched_source`        VARCHAR(500) DEFAULT NULL,
  `score`                 DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `matched_text_snippet`  TEXT         DEFAULT NULL,
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sm_sub`   (`submission_id`),
  INDEX `idx_sm_match` (`matched_submission_id`),
  INDEX `idx_sm_score` (`score`),
  CONSTRAINT `fk_sm_sub`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sm_match`
    FOREIGN KEY (`matched_submission_id`) REFERENCES `submissions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  activity_log
-- =====================================================
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED DEFAULT NULL,
  `action`       VARCHAR(100) NOT NULL,
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_log_user`   (`user_id`),
  INDEX `idx_log_action` (`action`),
  INDEX `idx_log_date`   (`created_at`),
  CONSTRAINT `fk_log_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  courses
-- =====================================================
CREATE TABLE IF NOT EXISTS `courses` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `department` VARCHAR(100) DEFAULT NULL,
  `instructor` VARCHAR(200) DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  settings
-- =====================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 7: ai_detection_results
-- =====================================================
CREATE TABLE IF NOT EXISTS `ai_detection_results` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`   INT UNSIGNED NOT NULL,
  `ai_probability`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `verdict`         VARCHAR(30)  NOT NULL DEFAULT 'unknown',
  `confidence`      VARCHAR(10)  NOT NULL DEFAULT 'Low',
  `detectors_json`  LONGTEXT,
  `flagged_json`    TEXT,
  `analysis_note`   TEXT,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ai_sub` (`submission_id`),
  CONSTRAINT `fk_ai_sub`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
--  SAMPLE DATA
-- =====================================================

-- Admin account  (password: Admin@123)
INSERT INTO `users`
  (`first_name`,`last_name`,`email`,`password_hash`,`role`,`institution`,`department`,`is_active`)
VALUES
  ('Admin','EduVault','admin@eduvault.io',
   '$2y$10$b1lGHGUpx43/ZhLxVV092us5vtteXXT4VdHfOAYKUsIZwickHhSH2',
   'admin','EduVault HQ','Administration',1);

-- Student accounts  (password: Student@123)
INSERT INTO `users`
  (`first_name`,`last_name`,`email`,`password_hash`,`role`,`student_id`,`institution`,`department`,`is_active`)
VALUES
  ('John',  'Smith',  'john@university.edu',   '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-042','University of Excellence','Computer Science',1),
  ('Sarah', 'Johnson','sarah@university.edu',  '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-031','University of Excellence','Computer Science',1),
  ('Mike',  'Chen',   'mike@university.edu',   '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-018','University of Excellence','Engineering',1),
  ('Aisha', 'Patel',  'aisha@university.edu',  '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-055','University of Excellence','Business',1),
  ('Emma',  'Wilson', 'emma@university.edu',   '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-067','University of Excellence','Engineering',1),
  ('Carlos','Ruiz',   'carlos@university.edu', '$2y$10$JZvFO42sEbxgOdGvDHTSmO4kDrv.SDJAcIFKFWPG/XW6TuQpYGr3i','student','STU-2024-073','University of Excellence','Business',1);

-- Courses
INSERT INTO `courses` (`code`,`name`,`department`,`instructor`) VALUES
  ('CSC 201','Data Structures & Algorithms', 'Computer Science','Dr. Ahmed Khan'),
  ('CSC 310','Database Systems',             'Computer Science','Dr. Lisa Park'),
  ('CSC 401','Operating Systems',            'Computer Science','Prof. Mark Evans'),
  ('CSC 420','Compiler Design',              'Computer Science','Dr. Nina Ross'),
  ('CSC 460','Cloud Computing',              'Computer Science','Prof. James Wu'),
  ('CSC 480','Artificial Intelligence',      'Computer Science','Dr. Sara Noor'),
  ('NET 210','Computer Networks',            'Computer Science','Prof. Ali Hassan'),
  ('ENG 301','Structural Analysis',          'Engineering',     'Dr. Bob Miller');

-- Sample submissions
INSERT INTO `submissions`
  (`user_id`,`title`,`course_code`,`assignment_type`,
   `similarity_score`,`risk_level`,`status`,
   `original_filename`,`stored_filename`,`file_size`,`mime_type`)
VALUES
  (2,'OS Lab Report',         'CSC 401','Lab Report',    87.00,'high',  'flagged', 'OS_Lab_Report.pdf',        '2_20250507_a1.pdf', 524288,'application/pdf'),
  (2,'DB Design Project',     'CSC 310','Project',       31.00,'medium','approved','DB_Design_Project.docx',   '2_20250504_a2.docx',362144,'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  (2,'Algo Analysis',         'CSC 201','Research Paper', 8.00,'low',   'approved','Algo_Analysis.pdf',        '2_20250501_a3.pdf', 285000,'application/pdf'),
  (2,'Network Essay',         'NET 210','Essay',         14.00,'low',   'approved','Network_Essay.txt',        '2_20250428_a4.txt',  48000,'text/plain'),
  (3,'Compiler Final Report', 'CSC 420','Lab Report',    62.00,'high',  'flagged', 'Compiler_Report.pdf',      '3_20250506_a5.pdf', 612000,'application/pdf'),
  (4,'Marketing Analysis',    'CSC 310','Research Paper',28.00,'medium','approved','Marketing_Analysis.docx',  '4_20250505_a6.docx',410000,'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  (5,'Bridge Design Report',  'ENG 301','Lab Report',   15.00,'low',   'approved','Bridge_Design.pdf',        '5_20250422_a7.pdf', 520000,'application/pdf'),
  (6,'Economics Essay',       'CSC 310','Essay',         45.00,'medium','flagged', 'Economics_Essay.docx',     '6_20250428_a8.docx',310000,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

-- Similarity matches
INSERT INTO `similarity_matches`
  (`submission_id`,`matched_submission_id`,`score`,`matched_text_snippet`)
VALUES
  (1,3,94.00,'"The operating system kernel is responsible for managing hardware resources and providing services to application programs..."'),
  (1,NULL,62.00,'"Process scheduling algorithms determine the order in which processes are executed by the CPU..."'),
  (5,1,71.00,'"Compiler construction involves lexical analysis, syntax parsing, and semantic analysis phases..."');

-- Default settings
INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('similarity_threshold_high',   '50'),
  ('similarity_threshold_medium', '20'),
  ('max_upload_size_mb',          '25'),
  ('allowed_extensions',          'pdf,doc,docx,txt'),
  ('auto_flag_high_risk',         '1'),
  ('email_notifications',         '1'),
  ('site_name',                   'EduVault'),
  ('maintenance_mode',            '0');