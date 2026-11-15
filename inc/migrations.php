<?php
declare(strict_types=1);

/**
 * Migrimet e bazës së të dhënave. Çdo migrim kontrollon vetë nëse është zbatuar,
 * kështu që mund të ekzekutohen sa herë të doni pa dëmtuar të dhënat.
 *  - 'table'  => krijohet tabela nëse mungon
 *  - 'column' => [tabela, kolona] shtohet kolona nëse mungon
 */
function db_migrations(): array {
    return [
        '002_learning_sessions' => [
            'title' => 'Seancat e leximit (sa shpesh e përdorin nxënësit librin)',
            'table' => 'learning_sessions',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS learning_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seconds INT UNSIGNED NOT NULL DEFAULT 0,
  pages INT UNSIGNED NOT NULL DEFAULT 0,
  device VARCHAR(20) NULL,
  KEY idx_sb (student_id, book_id, started_at),
  KEY idx_started (started_at),
  CONSTRAINT fk_ls_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ls_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '002_interaction_events' => [
            'title' => 'Klikimet & përdorimi i veçorive të qasshmërisë',
            'table' => 'interaction_events',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS interaction_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NULL,
  session_id INT UNSIGNED NULL,
  section_id VARCHAR(80) NULL,
  feature VARCHAR(30) NOT NULL,
  action VARCHAR(20) NOT NULL,
  label VARCHAR(120) NULL,
  value VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_student (student_id, created_at),
  KEY idx_feature (feature, created_at),
  KEY idx_book (book_id, created_at),
  KEY idx_created (created_at),
  CONSTRAINT fk_ie_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ie_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '003_users_municipality' => [
            'title' => 'Komuna e mësuesit',
            'column' => ['users', 'municipality'],
            'sql' => 'ALTER TABLE users ADD COLUMN municipality VARCHAR(60) NULL AFTER email',
        ],
        '003_users_phone' => [
            'title' => 'Telefoni i mësuesit',
            'column' => ['users', 'phone'],
            'sql' => 'ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER email',
        ],
        '004_users_gender' => [
            'title' => 'Gjinia e nxënësit (vajzë/djalë)',
            'column' => ['users', 'gender'],
            'sql' => "ALTER TABLE users ADD COLUMN gender CHAR(1) NULL AFTER grade",
        ],
        '004_users_special_needs' => [
            'title' => 'Nxënës me nevoja të veçanta',
            'column' => ['users', 'special_needs'],
            'sql' => 'ALTER TABLE users ADD COLUMN special_needs TINYINT(1) NULL AFTER gender',
        ],
        '004_users_special_needs_type' => [
            'title' => 'Lloji i nevojës së veçantë',
            'column' => ['users', 'special_needs_type'],
            'sql' => 'ALTER TABLE users ADD COLUMN special_needs_type VARCHAR(60) NULL AFTER special_needs',
        ],
        '004_users_mother_tongue' => [
            'title' => 'Gjuha amtare e nxënësit',
            'column' => ['users', 'mother_tongue'],
            'sql' => 'ALTER TABLE users ADD COLUMN mother_tongue VARCHAR(30) NULL AFTER special_needs_type',
        ],
        '005_alerts' => [
            'title' => 'Njoftimet automatike për mësuesit',
            'table' => 'alerts',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NULL,
  kind VARCHAR(20) NOT NULL,
  message VARCHAR(255) NOT NULL,
  link VARCHAR(255) NULL,
  dedupe_key VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  UNIQUE KEY uq_dedupe (teacher_id, dedupe_key),
  KEY idx_teacher (teacher_id, read_at),
  CONSTRAINT fk_al_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_al_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '005_assignments' => [
            'title' => 'Detyrat me afat',
            'table' => 'assignments',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  instructions TEXT NULL,
  pos_from INT UNSIGNED NOT NULL,
  pos_to INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  due_date DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_class (class_id, due_date),
  CONSTRAINT fk_as_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '005_badges' => [
            'title' => 'Distinktivët e nxënësve',
            'table' => 'badges_earned',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS badges_earned (
  student_id INT UNSIGNED NOT NULL,
  badge VARCHAR(40) NOT NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seen TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (student_id, badge),
  CONSTRAINT fk_be_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '005_audit' => [
            'title' => 'Regjistri i veprimeve (auditimi)',
            'table' => 'audit_log',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  role VARCHAR(10) NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(160) NULL,
  details VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_user (user_id, created_at),
  KEY idx_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '005_settings' => [
            'title' => 'Cilësimet e sistemit',
            'table' => 'settings',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '005_graded_by' => [
            'title' => 'Vlerësimi i përgjigjeve të hapura (mësuesi)',
            'column' => ['quiz_attempts', 'graded_by'],
            'sql' => 'ALTER TABLE quiz_attempts ADD COLUMN graded_by INT UNSIGNED NULL AFTER teacher_note',
        ],
        '005_graded_at' => [
            'title' => 'Data e vlerësimit',
            'column' => ['quiz_attempts', 'graded_at'],
            'sql' => 'ALTER TABLE quiz_attempts ADD COLUMN graded_at DATETIME NULL AFTER graded_by',
        ],
        '005_parent_token' => [
            'title' => 'Linku i prindit',
            'column' => ['users', 'parent_token'],
            'sql' => 'ALTER TABLE users ADD COLUMN parent_token CHAR(32) NULL AFTER mother_tongue, ADD UNIQUE KEY uq_parent_token (parent_token)',
        ],
        '005_sen_consent' => [
            'title' => 'Pëlqimi i prindit për të dhënat e nevojave të veçanta',
            'column' => ['users', 'sen_consent_at'],
            'sql' => 'ALTER TABLE users ADD COLUMN sen_consent_at DATETIME NULL AFTER parent_token, ADD COLUMN sen_consent_by VARCHAR(120) NULL AFTER sen_consent_at',
        ],
        '006_books_uploader_name' => [
            'title' => 'Libri: kush e ngarkoi (emri ruhet edhe nëse llogaria fshihet)',
            'column' => ['books', 'uploaded_by_name'],
            'sql' => [
                'ALTER TABLE books ADD COLUMN uploaded_by_name VARCHAR(160) NULL AFTER uploaded_by',
                "UPDATE books b JOIN users u ON u.id=b.uploaded_by SET b.uploaded_by_name = CONCAT(u.full_name, ' (', IF(u.role='admin','Administrator','Mësues'), ')') WHERE b.uploaded_by_name IS NULL",
            ],
        ],
        '006_books_updated' => [
            'title' => 'Libri: kush e ndryshoi dhe kur',
            'column' => ['books', 'updated_at'],
            'sql' => 'ALTER TABLE books ADD COLUMN updated_by_name VARCHAR(160) NULL AFTER uploaded_by_name, ADD COLUMN updated_at DATETIME NULL AFTER created_at',
        ],
        '008_users_name_hash' => [
            'title' => 'Anonimizimi i nxënësve: gjurmë e emrit (kundër dyfishimeve)',
            'column' => ['users', 'name_hash'],
            'sql' => 'ALTER TABLE users ADD COLUMN name_hash CHAR(64) NULL AFTER full_name',
        ],
        '008_schools_code' => [
            'title' => 'Anonimizimi i nxënësve: akronimi i shkollës',
            'column' => ['schools', 'code'],
            'sql' => 'ALTER TABLE schools ADD COLUMN code VARCHAR(4) NULL AFTER name',
        ],
        '008_classes_anon_no' => [
            'title' => 'Anonimizimi i nxënësve: numri i klasës (01, 02…)',
            'column' => ['classes', 'anon_no'],
            'sql' => 'ALTER TABLE classes ADD COLUMN anon_no SMALLINT UNSIGNED NULL AFTER grade',
        ],
        '009_hints_length' => [
            'title' => 'Kodimi i fjalëkalimeve fillestare të nxënësve (kolona më të gjata)',
            'check' => fn() => (int)qv("SELECT MIN(CHARACTER_MAXIMUM_LENGTH) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('plain_hint','picture_hint')") >= 255,
            'target' => 'kolonat users.plain_hint, users.picture_hint',
            'sql' => 'ALTER TABLE users MODIFY plain_hint VARCHAR(255) NULL, MODIFY picture_hint VARCHAR(255) NULL',
        ],
        '010_journey_status' => [
            'title' => 'Rrugëtimi i mësuesve: statuset e hapave',
            'table' => 'journey_status',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS journey_status (
  teacher_id INT UNSIGNED NOT NULL,
  step_key VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  admin_comment TEXT NULL,
  qty SMALLINT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id, step_key),
  CONSTRAINT fk_js_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '010_journey_files' => [
            'title' => 'Rrugëtimi i mësuesve: dokumentet e ngarkuara',
            'table' => 'journey_files',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS journey_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NOT NULL,
  step_key VARCHAR(40) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  stored_name VARCHAR(120) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ts (teacher_id, step_key),
  CONSTRAINT fk_jf_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '010_journey_teacher' => [
            'title' => 'Rrugëtimi i mësuesve: numri i cikleve',
            'table' => 'journey_teacher',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS journey_teacher (
  teacher_id INT UNSIGNED NOT NULL PRIMARY KEY,
  cycles TINYINT UNSIGNED NOT NULL DEFAULT 10,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_jt_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '010_journey_cycle' => [
            'title' => 'Rrugëtimi i mësuesve: plani mësimor i secilit cikël',
            'table' => 'journey_cycle',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS journey_cycle (
  teacher_id INT UNSIGNED NOT NULL,
  cycle TINYINT UNSIGNED NOT NULL,
  plan_title VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (teacher_id, cycle),
  CONSTRAINT fk_jc_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '011_journey_qty' => [
            'title' => 'Rrugëtimi: numri i pëlqimeve të pranuara',
            'column' => ['journey_status', 'qty'],
            'sql' => 'ALTER TABLE journey_status ADD COLUMN qty SMALLINT UNSIGNED NULL AFTER admin_comment',
        ],
        '012_student_work' => [
            'title' => 'Puna e nxënësve: tekstet e shkruara dhe shënimet në libër',
            'table' => 'student_work',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS student_work (
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  item_key VARCHAR(190) NOT NULL,
  section_id VARCHAR(80) NULL,
  value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, book_id, item_key),
  KEY idx_book_sec (book_id, section_id),
  CONSTRAINT fk_sw_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sw_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '012_attempt_verify' => [
            'title' => 'Verifikimi nga mësuesi i çdo tentative (human-in-the-loop)',
            'column' => ['quiz_attempts', 'verified_at'],
            'sql' => [
                'ALTER TABLE quiz_attempts ADD COLUMN auto_score TINYINT UNSIGNED NULL AFTER score_pct',
                'ALTER TABLE quiz_attempts ADD COLUMN verified_by INT UNSIGNED NULL AFTER graded_at',
                'ALTER TABLE quiz_attempts ADD COLUMN verified_at DATETIME NULL AFTER verified_by',
                'UPDATE quiz_attempts SET auto_score = score_pct WHERE graded_by IS NULL',
                'UPDATE quiz_attempts SET verified_by = graded_by, verified_at = graded_at WHERE graded_by IS NOT NULL',
                'ALTER TABLE quiz_attempts ADD KEY idx_verify (verified_at, student_id)',
            ],
        ],
        '007_trainings' => [
            'title' => 'Teacher trainings (sessions)',
            'table' => 'trainings',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS trainings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  topic VARCHAR(200) NULL,
  trainer VARCHAR(160) NULL,
  location VARCHAR(160) NULL,
  municipality VARCHAR(60) NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'in_person',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  hours DECIMAL(5,1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_start (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '007_training_attendance' => [
            'title' => 'Teacher training attendance & scores',
            'table' => 'training_attendance',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS training_attendance (
  training_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'registered',
  pre_score TINYINT UNSIGNED NULL,
  post_score TINYINT UNSIGNED NULL,
  satisfaction TINYINT UNSIGNED NULL,
  certificate TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (training_id, teacher_id),
  KEY idx_teacher (teacher_id),
  CONSTRAINT fk_ta_training FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '013_training_materials' => [
            'title' => 'Materialet e trajnimeve (PDF, Word, PowerPoint…)',
            'table' => 'training_materials',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS training_materials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  training_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NULL,
  original_name VARCHAR(190) NOT NULL,
  stored_name VARCHAR(120) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  all_teachers TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_training (training_id),
  CONSTRAINT fk_tm_training FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '014_attempt_student_seen' => [
            'title' => 'Vlerësimet te nxënësi: shënimi «e pashë»',
            'column' => ['quiz_attempts', 'student_seen'],
            'sql' => 'ALTER TABLE quiz_attempts ADD COLUMN student_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_at',
        ],
        '015_work_verify' => [
            'title' => 'Vlerësimi nga mësuesi i teksteve të shkruara nga nxënësit',
            'column' => ['student_work', 'verified_at'],
            'sql' => [
                'ALTER TABLE student_work ADD COLUMN score TINYINT UNSIGNED NULL AFTER value',
                'ALTER TABLE student_work ADD COLUMN teacher_note VARCHAR(500) NULL AFTER score',
                'ALTER TABLE student_work ADD COLUMN verified_by INT UNSIGNED NULL AFTER teacher_note',
                'ALTER TABLE student_work ADD COLUMN student_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_by',
                'ALTER TABLE student_work ADD COLUMN verified_at DATETIME NULL AFTER student_seen',
            ],
        ],
        '016_i18n_languages' => [
            'title' => 'Shumëgjuhësia: gjuhët e ndërfaqes',
            'table' => 'i18n_languages',
            'sql' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS i18n_languages (
  code VARCHAR(12) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  flag VARCHAR(16) NOT NULL DEFAULT '🌐',
  dir VARCHAR(3) NOT NULL DEFAULT 'ltr',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
                "INSERT IGNORE INTO i18n_languages (code, name, flag) VALUES ('sq', 'Shqip', '🇦🇱'), ('en', 'English', '🇬🇧')",
            ],
        ],
        '016_i18n_strings' => [
            'title' => 'Shumëgjuhësia: tekstet e ndërfaqes',
            'table' => 'i18n_strings',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS i18n_strings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  src_hash CHAR(40) NOT NULL,
  src TEXT NOT NULL,
  origin VARCHAR(8) NOT NULL DEFAULT 'php',
  ignored TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hash (src_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '016_i18n_translations' => [
            'title' => 'Shumëgjuhësia: përkthimet',
            'table' => 'i18n_translations',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS i18n_translations (
  lang VARCHAR(12) NOT NULL,
  string_id INT UNSIGNED NOT NULL,
  dst TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lang, string_id),
  CONSTRAINT fk_it_string FOREIGN KEY (string_id) REFERENCES i18n_strings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ],
        '016_users_lang' => [
            'title' => 'Shumëgjuhësia: gjuha e secilit përdorues',
            'column' => ['users', 'lang'],
            'sql' => 'ALTER TABLE users ADD COLUMN lang VARCHAR(12) NULL',
        ],
    ];
}

/** Ekzekuton SQL-në e një migrimi (një komandë ose listë komandash) */
function db_run_migration(array $m): void {
    foreach ((array)$m['sql'] as $stmt) db()->exec($stmt);
}

function db_table_exists(string $table): bool {
    return (bool)qv('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}
function db_column_exists(string $table, string $column): bool {
    return (bool)qv('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}
function db_migration_done(array $m): bool {
    if (isset($m['check'])) return (bool)($m['check'])();
    if (isset($m['table'])) return db_table_exists($m['table']);
    if (isset($m['column'])) return db_column_exists($m['column'][0], $m['column'][1]);
    return true;
}
function db_migration_target(array $m): string {
    if (isset($m['target'])) return $m['target'];
    return isset($m['table']) ? 'tabela ' . $m['table'] : 'kolona ' . $m['column'][0] . '.' . $m['column'][1];
}

/** @return string[] çelësat e migrimeve që mungojnë */
function db_pending_migrations(): array {
    static $pending = null;
    if ($pending === null) {
        $pending = [];
        try {
            foreach (db_migrations() as $key => $m) if (!db_migration_done($m)) $pending[] = $key;
        } catch (Throwable) {
            $pending = [];
        }
    }
    return $pending;
}
