-- Catalogue des cours Moodle (noms abrégés) et alias de filières
-- pour remplir les colonnes course1/group1… de l'export CSV.

CREATE TABLE IF NOT EXISTS moodle_courses (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shortname  VARCHAR(255) COLLATE utf8mb4_bin NOT NULL, -- exact, comme dans Moodle
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moodle_courses_shortname (shortname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ex. : la classe « BTS 2 FC 03 » suit les cours « BTS 2 FCGE: … » → filiere='FC', course_filiere='FCGE'
CREATE TABLE IF NOT EXISTS filiere_aliases (
    filiere        VARCHAR(50) NOT NULL PRIMARY KEY,
    course_filiere VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
