-- Migration: add strand column to attendance_logs
-- Run this SQL against your `attendance_db` database.

ALTER TABLE attendance_logs
  ADD COLUMN strand VARCHAR(16) DEFAULT NULL;

-- Optional: if you prefer codes, use 'ABM','ICT','HUMSS','HE' when inserting.
