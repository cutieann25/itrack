ALTER TABLE performance_evaluations
  ADD COLUMN partner_institution VARCHAR(180) NULL AFTER evaluation_date,
  ADD COLUMN appraisal_address VARCHAR(255) NULL AFTER partner_institution,
  ADD COLUMN contact_number VARCHAR(60) NULL AFTER appraisal_address,
  ADD COLUMN immersion_supervisor VARCHAR(150) NULL AFTER contact_number,
  ADD COLUMN supervisor_position VARCHAR(120) NULL AFTER immersion_supervisor,
  ADD COLUMN training_start_date DATE NULL AFTER supervisor_position,
  ADD COLUMN training_end_date DATE NULL AFTER training_start_date,
  ADD COLUMN total_hours_rendered DECIMAL(7,2) NULL AFTER training_end_date,
  ADD COLUMN strand VARCHAR(150) NULL AFTER total_hours_rendered;
