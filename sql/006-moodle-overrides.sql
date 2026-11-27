-- Envoi de l'agenda vers Moodle (dérogations de groupe des tests) : date du dernier envoi réussi. Idempotent.
ALTER TABLE quiz_schedules ADD COLUMN IF NOT EXISTS moodle_synced_at DATETIME NULL DEFAULT NULL;
