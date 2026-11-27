-- Dates écrites par l'application (fuseau Africa/Abidjan, voir src/bootstrap.php) et non plus par la base :
-- DATETIME sans valeur par défaut ni ON UPDATE. Les TIMESTAMP existants sont convertis dans le fuseau de la
-- session (aligné sur Abidjan par db()), donc les dates déjà enregistrées restent justes. Idempotent.
ALTER TABLE admins           MODIFY created_at DATETIME NOT NULL;
ALTER TABLE students         MODIFY created_at DATETIME NOT NULL;
ALTER TABLE course_overrides MODIFY updated_at DATETIME NOT NULL;
ALTER TABLE course_plans     MODIFY created_at DATETIME NOT NULL, MODIFY updated_at DATETIME NOT NULL;
ALTER TABLE quiz_schedules   MODIFY created_at DATETIME NOT NULL, MODIFY updated_at DATETIME NOT NULL;
