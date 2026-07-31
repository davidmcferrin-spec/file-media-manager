-- Allow UNEVALUATED confidence (default for unscored / empty signals).
-- Replaces HIGH/MEDIUM/LOW-only checks on files.confidence and classifier_confidence.

ALTER TABLE files DROP CONSTRAINT IF EXISTS files_confidence_check;
ALTER TABLE files
    ADD CONSTRAINT files_confidence_check
    CHECK (confidence IN ('HIGH', 'MEDIUM', 'LOW', 'UNEVALUATED'));

ALTER TABLE files ALTER COLUMN confidence SET DEFAULT 'UNEVALUATED';

ALTER TABLE files DROP CONSTRAINT IF EXISTS files_classifier_confidence_check;
ALTER TABLE files
    ADD CONSTRAINT files_classifier_confidence_check
    CHECK (
        classifier_confidence IS NULL
        OR classifier_confidence IN ('HIGH', 'MEDIUM', 'LOW', 'UNEVALUATED')
    );
