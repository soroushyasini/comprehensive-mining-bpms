-- Compatibility upgrade for installations that applied the original 014 schema.
-- Original files, cards and existing metadata are preserved. New files have no thumbnail.
ALTER TABLE emcore_business_card_files
 MODIFY thumbnail_filename VARCHAR(80) DEFAULT NULL,
 MODIFY thumbnail_sha256 CHAR(64) DEFAULT NULL;
