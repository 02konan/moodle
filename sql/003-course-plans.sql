-- Structure des cours Moodle (page « Cours ») : cours → sections → sous-sections / QCM.
-- Sert à produire la fiche Word « Programmation des évaluations » de chaque cours.

CREATE TABLE IF NOT EXISTS course_plans (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_course_plans_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Éléments d'un cours, en arbre :
--   section    : parent_id NULL
--   subsection : parent_id = une section
--   quiz       : parent_id = une section ou une sous-section
-- position : ordre d'affichage parmi les éléments de même parent.
CREATE TABLE IF NOT EXISTS course_plan_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id   INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    type      ENUM('section', 'subsection', 'quiz') NOT NULL,
    title     VARCHAR(255) NOT NULL,
    position  INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_items_tree (plan_id, parent_id, position),
    CONSTRAINT fk_items_plan   FOREIGN KEY (plan_id)   REFERENCES course_plans (id)      ON DELETE CASCADE,
    CONSTRAINT fk_items_parent FOREIGN KEY (parent_id) REFERENCES course_plan_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
