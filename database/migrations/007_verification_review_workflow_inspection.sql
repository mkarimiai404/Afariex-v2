-- READ-ONLY: run in phpMyAdmin before deployment. These statements make no changes.

SELECT table_name, table_type, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('user_verification_levels', 'verification_upgrade_requests')
ORDER BY table_name;

SELECT table_name, ordinal_position, column_name, column_type, is_nullable, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('user_verification_levels', 'verification_upgrade_requests')
ORDER BY table_name, ordinal_position;

SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('user_verification_levels', 'verification_upgrade_requests')
ORDER BY table_name, index_name, seq_in_index;

WITH required_columns AS (
  SELECT 'user_verification_levels' table_name, 'user_id' column_name UNION ALL
  SELECT 'user_verification_levels', 'level' UNION ALL
  SELECT 'user_verification_levels', 'phone_verified' UNION ALL
  SELECT 'user_verification_levels', 'phone_verified_at' UNION ALL
  SELECT 'user_verification_levels', 'withdrawal_limit' UNION ALL
  SELECT 'user_verification_levels', 'updated_at' UNION ALL
  SELECT 'verification_upgrade_requests', 'id' UNION ALL
  SELECT 'verification_upgrade_requests', 'user_id' UNION ALL
  SELECT 'verification_upgrade_requests', 'request_type' UNION ALL
  SELECT 'verification_upgrade_requests', 'requested_level' UNION ALL
  SELECT 'verification_upgrade_requests', 'status' UNION ALL
  SELECT 'verification_upgrade_requests', 'identity_document_path' UNION ALL
  SELECT 'verification_upgrade_requests', 'selfie_path' UNION ALL
  SELECT 'verification_upgrade_requests', 'video_path' UNION ALL
  SELECT 'verification_upgrade_requests', 'admin_id' UNION ALL
  SELECT 'verification_upgrade_requests', 'admin_note' UNION ALL
  SELECT 'verification_upgrade_requests', 'rejection_reason' UNION ALL
  SELECT 'verification_upgrade_requests', 'created_at' UNION ALL
  SELECT 'verification_upgrade_requests', 'reviewed_at'
)
SELECT required_columns.table_name, required_columns.column_name,
       CASE WHEN c.column_name IS NULL THEN 'MISSING' ELSE 'PRESENT' END inspection_status
FROM required_columns
LEFT JOIN information_schema.columns c
  ON c.table_schema = DATABASE()
 AND c.table_name = required_columns.table_name
 AND c.column_name = required_columns.column_name
ORDER BY required_columns.table_name, required_columns.column_name;

SELECT 'verification_upgrade_requests' table_name,
       '(user_id,status)' required_index_columns,
       CASE WHEN COUNT(*) > 0 THEN 'PRESENT' ELSE 'MISSING' END inspection_status
FROM (
  SELECT index_name
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'verification_upgrade_requests'
  GROUP BY index_name
  HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'user_id,status'
) exact_indexes;
