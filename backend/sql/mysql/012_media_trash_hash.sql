-- Convert existing wa_media_trash tables to hash-based uniqueness.
-- Preferred path: generated STORED column rel_path_hash.

DROP PROCEDURE IF EXISTS wa_migrate_media_trash_hash;
DELIMITER //
CREATE PROCEDURE wa_migrate_media_trash_hash()
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  DECLARE idx_old_exists INT DEFAULT 0;
  DECLARE idx_new_exists INT DEFAULT 0;
  DECLARE is_generated INT DEFAULT 0;

  SELECT COUNT(*) INTO col_exists
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'wa_media_trash'
    AND column_name = 'rel_path_hash';

  IF col_exists = 0 THEN
    SET @sql := 'ALTER TABLE wa_media_trash ADD COLUMN rel_path_hash CHAR(64) GENERATED ALWAYS AS (SHA2(rel_path, 256)) STORED';
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  ELSE
    SELECT COUNT(*) INTO is_generated
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'wa_media_trash'
      AND column_name = 'rel_path_hash'
      AND extra LIKE '%GENERATED%';

    IF is_generated = 0 THEN
      UPDATE wa_media_trash
      SET rel_path_hash = SHA2(rel_path, 256)
      WHERE rel_path_hash IS NULL OR rel_path_hash = '';
    END IF;
  END IF;

  SELECT COUNT(*) INTO idx_old_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'wa_media_trash'
    AND index_name = 'uniq_relpath_active';

  IF idx_old_exists > 0 THEN
    SET @sql := 'ALTER TABLE wa_media_trash DROP INDEX uniq_relpath_active';
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;

  SELECT COUNT(*) INTO idx_new_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'wa_media_trash'
    AND index_name = 'uniq_relpath_hash_status';

  IF idx_new_exists = 0 THEN
    SET @sql := 'ALTER TABLE wa_media_trash ADD UNIQUE INDEX uniq_relpath_hash_status (rel_path_hash, status)';
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;
END//
DELIMITER ;

CALL wa_migrate_media_trash_hash();
DROP PROCEDURE wa_migrate_media_trash_hash;
