-- Klasa Digjitale — skema e bazës së të dhënave (MySQL 5.7+/MariaDB 10.3+)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(4) NULL,
  city VARCHAR(100) NULL,
  address VARCHAR(200) NULL,
  phone VARCHAR(40) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('admin','teacher','student') NOT NULL,
  school_id INT UNSIGNED NULL,
  full_name VARCHAR(120) NOT NULL,
  name_hash CHAR(64) NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(160) NULL,
  phone VARCHAR(30) NULL,
  municipality VARCHAR(60) NULL,
  password_hash VARCHAR(255) NOT NULL,
  picture_hash VARCHAR(255) NULL,
  picture_hint VARCHAR(255) NULL,         -- sekuenca e fotove (për kartelat e printueshme të mësuesit)
  plain_hint VARCHAR(255) NULL,            -- fjalëkalimi fillestar i nxënësit (vetëm për kartelat; fshihet kur nxënësi e ndryshon)
  avatar VARCHAR(16) NULL,
  grade TINYINT UNSIGNED NULL,
  gender CHAR(1) NULL,                    -- F = vajzë, M = djalë
  special_needs TINYINT(1) NULL,          -- 1 = me nevoja të veçanta
  special_needs_type VARCHAR(60) NULL,
  mother_tongue VARCHAR(30) NULL,
  lang VARCHAR(12) NULL,                  -- gjuha e ndërfaqes e zgjedhur nga përdoruesi
  parent_token CHAR(32) NULL UNIQUE,
  sen_consent_at DATETIME NULL,
  sen_consent_by VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_role_school (role, school_id),
  CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  grade TINYINT UNSIGNED NOT NULL,
  anon_no SMALLINT UNSIGNED NULL,
  class_code CHAR(6) NOT NULL UNIQUE,
  picture_login TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_classes_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_students (
  class_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_id, student_id),
  KEY idx_student (student_id),
  CONSTRAINT fk_cs_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS books (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  grade TINYINT UNSIGNED NULL,
  package_type ENUM('adt','scorm12','scorm2004','html') NOT NULL DEFAULT 'scorm12',
  folder VARCHAR(80) NOT NULL,
  entry_href VARCHAR(255) NOT NULL,
  cover VARCHAR(255) NULL,
  page_count INT UNSIGNED NOT NULL DEFAULT 0,
  activity_count INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  uploaded_by_name VARCHAR(160) NULL,    -- emri i ruajtur edhe nëse llogaria fshihet
  updated_by_name VARCHAR(160) NULL,
  school_id INT UNSIGNED NULL,           -- NULL = libër global (i ngarkuar nga admini)
  is_global TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_books_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_pages (
  book_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  section_id VARCHAR(80) NOT NULL,
  href VARCHAR(255) NOT NULL,
  page_number INT UNSIGNED NULL,
  title VARCHAR(255) NULL,
  section_type VARCHAR(60) NULL,
  is_activity TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (book_id, section_id),
  KEY idx_pos (book_id, position),
  CONSTRAINT fk_bp_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_books (
  class_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_id, book_id),
  CONSTRAINT fk_cb_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cb_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Koha e kaluar sipas faqes
CREATE TABLE IF NOT EXISTS page_views (
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  section_id VARCHAR(80) NOT NULL,
  seconds INT UNSIGNED NOT NULL DEFAULT 0,
  visits INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, book_id, section_id),
  KEY idx_book (book_id),
  CONSTRAINT fk_pv_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Koha ditore (për statistikat)
CREATE TABLE IF NOT EXISTS usage_daily (
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  seconds INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (student_id, book_id, day),
  KEY idx_day (day),
  CONSTRAINT fk_ud_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ud_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativat e ushtrimeve / kuizeve
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  section_id VARCHAR(80) NOT NULL,
  activity_type VARCHAR(60) NULL,
  total_items INT UNSIGNED NOT NULL DEFAULT 0,
  correct_items INT UNSIGNED NOT NULL DEFAULT 0,
  wrong_items INT UNSIGNED NOT NULL DEFAULT 0,
  empty_items INT UNSIGNED NOT NULL DEFAULT 0,
  score_pct TINYINT UNSIGNED NULL,       -- NULL = përgjigje e hapur (vlerëson mësuesi)
  auto_score TINYINT UNSIGNED NULL,      -- rezultati automatik i librit (para verifikimit)
  answers_json MEDIUMTEXT NULL,
  feedback VARCHAR(255) NULL,
  teacher_note VARCHAR(500) NULL,
  graded_by INT UNSIGNED NULL,
  graded_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  verified_at DATETIME NULL,
  student_seen TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sb (student_id, book_id),
  KEY idx_verify (verified_at, student_id),
  KEY idx_created (created_at),
  CONSTRAINT fk_qa_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Të dhënat SCORM 1.2 (cmi.*) për çdo nxënës/libër
CREATE TABLE IF NOT EXISTS scorm_data (
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  lesson_location VARCHAR(255) NULL,
  lesson_status VARCHAR(20) NULL,
  score_raw VARCHAR(10) NULL,
  suspend_data MEDIUMTEXT NULL,
  total_time_sec INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, book_id),
  CONSTRAINT fk_sd_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sd_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tiketat: nxënës → mësues, mësues → admin
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_by INT UNSIGNED NOT NULL,
  recipient_role ENUM('teacher','admin') NOT NULL,
  assigned_to INT UNSIGNED NULL,          -- mësuesi (kur recipient_role = teacher)
  school_id INT UNSIGNED NULL,
  kind ENUM('problem','question','comment') NOT NULL DEFAULT 'problem',
  category VARCHAR(40) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  book_id INT UNSIGNED NULL,
  section_id VARCHAR(80) NULL,
  status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
  escalated_from INT UNSIGNED NULL,
  unread_creator TINYINT(1) NOT NULL DEFAULT 0,
  unread_recipient TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assigned (recipient_role, assigned_to, status),
  CONSTRAINT fk_t_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_t_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id),
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ident VARCHAR(120) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ident (ident, created_at),
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seancat e leximit (sa shpesh e përdor nxënësi librin)
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

-- Çdo klikim / përdorim i veçorive të qasshmërisë (vetëm për administratorin)
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

SET FOREIGN_KEY_CHECKS = 1;

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

-- Puna e nxënësve dhe verifikimi nga mësuesi (përditësimi 012)
CREATE TABLE IF NOT EXISTS student_work (
  student_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  item_key VARCHAR(190) NOT NULL,
  section_id VARCHAR(80) NULL,
  value MEDIUMTEXT NULL,
  score TINYINT UNSIGNED NULL,
  teacher_note VARCHAR(500) NULL,
  verified_by INT UNSIGNED NULL,
  student_seen TINYINT(1) NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, book_id, item_key),
  KEY idx_book_sec (book_id, section_id),
  CONSTRAINT fk_sw_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sw_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
