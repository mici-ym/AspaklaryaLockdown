-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: AspaklaryaLockdown/dbPatches/remove_read_allowed_field.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TEMPORARY TABLE /*_*/__temp__aspaklarya_lockdown_pages AS
SELECT  al_id,  al_page_id,  al_level
FROM  /*_*/aspaklarya_lockdown_pages;
DROP  TABLE  /*_*/aspaklarya_lockdown_pages;
CREATE TABLE  /*_*/aspaklarya_lockdown_pages (    al_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,    al_page_id INTEGER UNSIGNED NOT NULL,    al_level SMALLINT UNSIGNED DEFAULT 0 NOT NULL  );
INSERT INTO  /*_*/aspaklarya_lockdown_pages (al_id, al_page_id, al_level)
SELECT  al_id,  al_page_id,  al_level
FROM  /*_*/__temp__aspaklarya_lockdown_pages;
DROP  TABLE /*_*/__temp__aspaklarya_lockdown_pages;
CREATE UNIQUE INDEX al_id ON  /*_*/aspaklarya_lockdown_pages (al_id);
CREATE UNIQUE INDEX al_page_id ON  /*_*/aspaklarya_lockdown_pages (al_page_id);