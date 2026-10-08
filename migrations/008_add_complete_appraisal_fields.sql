ALTER TABLE performance_evaluations
  ADD COLUMN rubric_scores JSON NULL AFTER teamwork,
  ADD COLUMN weighted_average DECIMAL(4,2) NULL AFTER overall_performance_rating,
  ADD COLUMN equivalent_grade DECIMAL(5,2) NULL AFTER weighted_average,
  ADD COLUMN student_signature MEDIUMTEXT NULL AFTER comments,
  ADD COLUMN supervisor_signature MEDIUMTEXT NULL AFTER student_signature;
