ALTER TABLE performance_evaluations
  MODIFY COLUMN punctuality DECIMAL(2,1) NOT NULL,
  MODIFY COLUMN work_quality DECIMAL(2,1) NOT NULL,
  MODIFY COLUMN attitude DECIMAL(2,1) NOT NULL,
  MODIFY COLUMN teamwork DECIMAL(2,1) NOT NULL,
  MODIFY COLUMN overall_performance_rating DECIMAL(2,1) NOT NULL;
