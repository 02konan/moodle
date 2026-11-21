-- Noms de cours Moodle personnalisés : remplace le nom généré (« BTS 2 FC: MATIÈRE »)
-- par le nom abrégé exact du cours dans Moodle.
CREATE TABLE IF NOT EXISTS course_overrides (
    auto_name   VARCHAR(255) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    moodle_name VARCHAR(255) NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
