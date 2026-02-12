-- Fallback for DB engines that do not support STORED generated columns.
-- Use this only if 012_media_trash_hash.sql fails on GENERATED ALWAYS.

ALTER TABLE wa_media_trash
  ADD COLUMN rel_path_hash CHAR(64) NULL;

UPDATE wa_media_trash
SET rel_path_hash = SHA2(rel_path, 256)
WHERE rel_path_hash IS NULL OR rel_path_hash = '';

DROP TRIGGER IF EXISTS wa_media_trash_bi_hash;
DROP TRIGGER IF EXISTS wa_media_trash_bu_hash;

DELIMITER //
CREATE TRIGGER wa_media_trash_bi_hash
BEFORE INSERT ON wa_media_trash
FOR EACH ROW
BEGIN
  SET NEW.rel_path_hash = SHA2(NEW.rel_path, 256);
END//

CREATE TRIGGER wa_media_trash_bu_hash
BEFORE UPDATE ON wa_media_trash
FOR EACH ROW
BEGIN
  IF NEW.rel_path <> OLD.rel_path OR NEW.rel_path_hash IS NULL OR NEW.rel_path_hash = '' THEN
    SET NEW.rel_path_hash = SHA2(NEW.rel_path, 256);
  END IF;
END//
DELIMITER ;

ALTER TABLE wa_media_trash
  DROP INDEX uniq_relpath_active;

ALTER TABLE wa_media_trash
  ADD UNIQUE INDEX uniq_relpath_hash_status (rel_path_hash, status);
