CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  display_name VARCHAR(150) NOT NULL,
  role ENUM('coordinator', 'supervisor') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS performance_evaluations (
  id INT NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  evaluator_id INT NOT NULL,
  evaluation_date DATE NOT NULL,
  punctuality TINYINT UNSIGNED NOT NULL,
  work_quality TINYINT UNSIGNED NOT NULL,
  attitude TINYINT UNSIGNED NOT NULL,
  teamwork TINYINT UNSIGNED NOT NULL,
  comments TEXT NULL,
  status ENUM('draft', 'submitted') NOT NULL DEFAULT 'submitted',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_evaluations_student (student_id),
  KEY idx_evaluations_evaluator (evaluator_id),
  CONSTRAINT fk_evaluations_evaluator FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO users (username, display_name, role, password_hash) VALUES
('coordinator', 'Attendance Coordinator', 'coordinator', '$2y$10$oDW1gn8K4O7K3r1Pwg2EQ.0k8WFl1yqReo19StNV26sSBHe7vVEgq'),
('supervisor', 'Work Immersion Supervisor', 'supervisor', '$2y$10$fGtPGsReNniThsnoMRLyteZWbFk7XpnbrQ/DdAQgJ46dL7HFoRfWS');
