-- PDF cards have no raster pixel dimensions. Preserve existing image dimensions.
ALTER TABLE emcore_business_card_files
    MODIFY COLUMN width INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN height INT UNSIGNED NULL DEFAULT NULL;
