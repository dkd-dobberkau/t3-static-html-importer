--
-- tt_content: dedupe column for the static HTML importer.
--
-- Carries the SHA1-style block hash from BlockHasher so re-runs of
-- t3:static-html:import update the existing record instead of inserting
-- a duplicate. Read-only in the backend.
--
CREATE TABLE tt_content (
    tx_static_html_importer_block_id varchar(40) DEFAULT '' NOT NULL,
    KEY idx_static_html_importer_block_id (tx_static_html_importer_block_id)
);
