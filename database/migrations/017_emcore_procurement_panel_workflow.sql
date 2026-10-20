-- Apply after 012. Existing native case references are never cleared or fabricated.
-- New in-panel workflows have no ProcessMaker case association.
ALTER TABLE emcore_procurement_workflows
    MODIFY COLUMN app_uid CHAR(32) NULL DEFAULT NULL;
