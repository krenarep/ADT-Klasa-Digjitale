-- Klasa Digjitale — përditësimi i bazës (versioni 007)
-- phpMyAdmin → zgjidhni bazën → skeda "SQL" (ose "Import") → ekzekuto.
-- I sigurt për t'u ekzekutuar disa herë: krijon vetëm atë që mungon.
SET NAMES utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS municipality VARCHAR(60) NULL AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS gender CHAR(1) NULL AFTER grade;
ALTER TABLE users ADD COLUMN IF NOT EXISTS special_needs TINYINT(1) NULL AFTER gender;
ALTER TABLE users ADD COLUMN IF NOT EXISTS special_needs_type VARCHAR(60) NULL AFTER special_needs;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mother_tongue VARCHAR(30) NULL AFTER special_needs_type;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS badges_earned (
  student_id INT UNSIGNED NOT NULL,
  badge VARCHAR(40) NOT NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seen TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (student_id, badge),
  CONSTRAINT fk_be_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS graded_by INT UNSIGNED NULL AFTER teacher_note;
ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS graded_at DATETIME NULL AFTER graded_by;
ALTER TABLE users ADD COLUMN IF NOT EXISTS parent_token CHAR(32) NULL AFTER mother_tongue;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sen_consent_at DATETIME NULL AFTER parent_token;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sen_consent_by VARCHAR(120) NULL AFTER sen_consent_at;
CREATE UNIQUE INDEX IF NOT EXISTS uq_parent_token ON users (parent_token);
ALTER TABLE books ADD COLUMN IF NOT EXISTS uploaded_by_name VARCHAR(160) NULL AFTER uploaded_by;
ALTER TABLE books ADD COLUMN IF NOT EXISTS updated_by_name VARCHAR(160) NULL AFTER uploaded_by_name;
ALTER TABLE books ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;
UPDATE books b JOIN users u ON u.id=b.uploaded_by SET b.uploaded_by_name = CONCAT(u.full_name, ' (', IF(u.role='admin','Administrator','Mësues'), ')') WHERE b.uploaded_by_name IS NULL;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 008 Anonimizimi i nxënësve (nëse kolonat ekzistojnë, MariaDB 10.3+ i anashkalon me IF NOT EXISTS)
ALTER TABLE users ADD COLUMN IF NOT EXISTS name_hash CHAR(64) NULL AFTER full_name;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS code VARCHAR(4) NULL AFTER name;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS anon_no SMALLINT UNSIGNED NULL AFTER grade;

-- 009 Kodimi i fjalëkalimeve fillestare të nxënësve
ALTER TABLE users MODIFY plain_hint VARCHAR(255) NULL, MODIFY picture_hint VARCHAR(255) NULL;

-- 010 Rrugëtimi i mësuesve
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_teacher (
  teacher_id INT UNSIGNED NOT NULL PRIMARY KEY,
  cycles TINYINT UNSIGNED NOT NULL DEFAULT 10,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_jt_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_cycle (
  teacher_id INT UNSIGNED NOT NULL,
  cycle TINYINT UNSIGNED NOT NULL,
  plan_title VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (teacher_id, cycle),
  CONSTRAINT fk_jc_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE journey_status ADD COLUMN IF NOT EXISTS qty SMALLINT UNSIGNED NULL AFTER admin_comment;

-- Puna e nxënësve dhe verifikimi nga mësuesi (përditësimi 012)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS auto_score TINYINT UNSIGNED NULL AFTER score_pct;
ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS verified_by INT UNSIGNED NULL AFTER graded_at;
ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER verified_by;

-- Materialet e trajnimeve (përditësimi 013)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quiz_attempts ADD COLUMN IF NOT EXISTS student_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_at;

ALTER TABLE student_work ADD COLUMN IF NOT EXISTS score TINYINT UNSIGNED NULL AFTER value;
ALTER TABLE student_work ADD COLUMN IF NOT EXISTS teacher_note VARCHAR(500) NULL AFTER score;
ALTER TABLE student_work ADD COLUMN IF NOT EXISTS verified_by INT UNSIGNED NULL AFTER teacher_note;
ALTER TABLE student_work ADD COLUMN IF NOT EXISTS student_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_by;
ALTER TABLE student_work ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER student_seen;

-- Shumëgjuhësia (përditësimi 016)
CREATE TABLE IF NOT EXISTS i18n_languages (
  code VARCHAR(12) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  flag VARCHAR(16) NOT NULL DEFAULT '🌐',
  dir VARCHAR(3) NOT NULL DEFAULT 'ltr',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO i18n_languages (code, name, flag) VALUES ('sq', 'Shqip', '🇦🇱'), ('en', 'English', '🇬🇧');
CREATE TABLE IF NOT EXISTS i18n_strings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  src_hash CHAR(40) NOT NULL,
  src TEXT NOT NULL,
  origin VARCHAR(8) NOT NULL DEFAULT 'php',
  ignored TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hash (src_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS i18n_translations (
  lang VARCHAR(12) NOT NULL,
  string_id INT UNSIGNED NOT NULL,
  dst TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lang, string_id),
  CONSTRAINT fk_it_string FOREIGN KEY (string_id) REFERENCES i18n_strings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lang VARCHAR(12) NULL;
