-- =============================================
-- EduVault: Reset / seed user accounts
-- Paste ALL of this in phpMyAdmin > eduvault > SQL tab
-- =============================================

DELETE FROM `similarity_matches`;
DELETE FROM `activity_log`;
DELETE FROM `submissions`;
DELETE FROM `users`;
ALTER TABLE `users` AUTO_INCREMENT = 1;

-- Admin  (email: admin@eduvault.io | password: Admin@123)
INSERT INTO `users` (`first_name`,`last_name`,`email`,`password_hash`,`role`,`institution`,`department`,`is_active`)
VALUES ('Admin','EduVault','admin@eduvault.io','$2y$10$5ksmM/viZghAJaJiBL1rc.Co8HR/cVkeUAEGwBhS7yvWcEsOTBVmW','admin','EduVault HQ','Administration',1);

-- Students  (password for all: Student@123)
INSERT INTO `users` (`first_name`,`last_name`,`email`,`password_hash`,`role`,`student_id`,`institution`,`department`,`is_active`)
VALUES
  ('John', 'Smith',  'john@university.edu',   '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-042','University of Excellence','Computer Science',1),
  ('Sarah','Johnson','sarah@university.edu',  '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-031','University of Excellence','Computer Science',1),
  ('Mike', 'Chen',   'mike@university.edu',   '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-018','University of Excellence','Engineering',1),
  ('Aisha','Patel',  'aisha@university.edu',  '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-055','University of Excellence','Business',1),
  ('Emma', 'Wilson', 'emma@university.edu',   '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-067','University of Excellence','Engineering',1),
  ('Carlos','Ruiz',  'carlos@university.edu', '$2y$10$weyxNh4941LNR/CR242vVufh87PRWQO66u.OTTx5L.q8RdSqdJe7C','student','STU-2024-073','University of Excellence','Business',1);
