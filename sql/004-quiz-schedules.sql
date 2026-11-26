-- Agenda : programmation des QCM par cohorte (ouverture / fermeture), comme les dérogations de groupe des tests Moodle.
CREATE TABLE IF NOT EXISTS quiz_schedules (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id    INT UNSIGNED NOT NULL,                 -- QCM (course_plan_items, type « quiz »)
    cohort     VARCHAR(190) NOT NULL,
    opens_at   DATETIME NOT NULL,
    closes_at  DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_quiz_cohort (item_id, cohort),
    KEY idx_schedule_opens (opens_at),
    KEY idx_schedule_closes (closes_at),
    KEY idx_schedule_cohort (cohort),
    CONSTRAINT fk_schedule_item FOREIGN KEY (item_id) REFERENCES course_plan_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
