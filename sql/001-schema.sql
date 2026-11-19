-- Schéma initial (idempotent). Appliqué automatiquement au démarrage du conteneur (bin/migrate.php).

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les noms des clés uniques contiennent « email » / « username » :
-- api/register.php s'en sert pour distinguer les doublons.
CREATE TABLE IF NOT EXISTS students (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lastname      VARCHAR(100) NOT NULL,
    firstname     VARCHAR(100) NOT NULL,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password      VARCHAR(100) NOT NULL,
    city          VARCHAR(100) NOT NULL,
    section_id    INT UNSIGNED NOT NULL,
    section_code  VARCHAR(100) NOT NULL,
    section_label VARCHAR(190) NOT NULL,
    cycle_id      INT UNSIGNED NOT NULL,
    cycle_label   VARCHAR(190) NOT NULL,
    level_id      INT UNSIGNED NOT NULL,
    level_label   VARCHAR(190) NOT NULL,
    class_id      INT UNSIGNED NOT NULL,
    class_label   VARCHAR(190) NOT NULL,
    cohort        VARCHAR(190) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exported_at   DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_students_email (email),
    UNIQUE KEY uq_students_username (username),
    KEY idx_students_cohort (cohort),
    KEY idx_students_exported (exported_at),
    KEY idx_students_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_subjects_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teachers (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(150) NOT NULL,
    phone    VARCHAR(40)  NOT NULL DEFAULT '',
    UNIQUE KEY uq_teachers_fullname (fullname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cohort_subjects (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cohort     VARCHAR(190) NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    hours      SMALLINT UNSIGNED NULL,
    UNIQUE KEY uq_cohort_subject (cohort, subject_id),
    CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT fk_cs_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
