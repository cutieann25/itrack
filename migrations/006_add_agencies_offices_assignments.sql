CREATE TABLE IF NOT EXISTS agencies (
  agency_id INT NOT NULL AUTO_INCREMENT,
  agency_name VARCHAR(150) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  contact_info VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agency_id),
  UNIQUE KEY uq_agency_name (agency_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS offices (
  office_id INT NOT NULL AUTO_INCREMENT,
  agency_id INT NOT NULL,
  office_name VARCHAR(150) NOT NULL,
  latitude DOUBLE DEFAULT NULL,
  longitude DOUBLE DEFAULT NULL,
  radius_meters INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (office_id),
  UNIQUE KEY uq_office_per_agency (agency_id, office_name),
  CONSTRAINT fk_offices_agency FOREIGN KEY (agency_id) REFERENCES agencies(agency_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS student_supervisor_assignments (
  assignment_id INT NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  supervisor_id INT NOT NULL,
  agency_id INT DEFAULT NULL,
  office_id INT DEFAULT NULL,
  assigned_by INT NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  UNIQUE KEY uq_student_assignment (student_id),
  KEY idx_assignment_supervisor (supervisor_id),
  CONSTRAINT fk_assignment_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_agency FOREIGN KEY (agency_id) REFERENCES agencies(agency_id) ON DELETE SET NULL,
  CONSTRAINT fk_assignment_office FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE SET NULL,
  CONSTRAINT fk_assignment_coordinator FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
