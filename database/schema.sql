-- =============================================================================
-- SISTEM EVALUASI KEGIATAN MAHASISWA TERHADAP PPKMB BERBASIS ANALISIS SENTIMEN
-- File: database/schema.sql
-- Kompatibel dengan: MySQL 8.0+ / MariaDB 10.6+ (Laragon Windows)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `ppkmb_sentiment_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ppkmb_sentiment_db`;

-- -----------------------------------------------------------------------------
-- 1. Tabel users: Menyimpan akun Admin, Panitia, dan Mahasiswa
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `role` ENUM('admin', 'panitia', 'mahasiswa') NOT NULL DEFAULT 'mahasiswa',
  `identifier_masked` VARCHAR(40) DEFAULT NULL COMMENT 'NIM/NIP disamarkan untuk privasi',
  `faculty` VARCHAR(100) DEFAULT NULL,
  `study_program` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role_active` (`role`, `is_active`),
  KEY `idx_users_faculty` (`faculty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Tabel periods: Periode / Tahun Pelaksanaan PPKMB
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `periods`;
CREATE TABLE `periods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year` VARCHAR(20) NOT NULL COMMENT 'Contoh: 2026/2027',
  `period_name` VARCHAR(120) NOT NULL COMMENT 'Contoh: PPKMB Universitas 2026',
  `theme` VARCHAR(255) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `target_participants` INT UNSIGNED NOT NULL DEFAULT 1000,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Periode aktif untuk pengisian form',
  `allow_anonymous` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Izinkan evaluasi tanpa identitas',
  `allow_student_summary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Mahasiswa dapat melihat ringkasan umum',
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periods_year_name` (`academic_year`, `period_name`),
  KEY `idx_periods_active` (`is_active`, `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Tabel activities: Jenis Kegiatan dan Sesi PPKMB
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `activities`;
CREATE TABLE `activities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `activity_code` VARCHAR(30) NOT NULL,
  `activity_name` VARCHAR(160) NOT NULL,
  `category` VARCHAR(80) NOT NULL COMMENT 'Kategori kegiatan PPKMB',
  `speaker_name` VARCHAR(140) DEFAULT NULL,
  `location` VARCHAR(140) DEFAULT NULL,
  `activity_date` DATE NOT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time` TIME DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activities_code_period` (`period_id`, `activity_code`),
  KEY `idx_activities_category` (`category`),
  KEY `idx_activities_date` (`activity_date`),
  CONSTRAINT `fk_activities_period` FOREIGN KEY (`period_id`)
    REFERENCES `periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Tabel evaluations: Data Formulir Evaluasi Mahasiswa (Terpisah dari Identitas!)
-- Catatan Privasi: Tabel ini TIDAK menyimpan user_id atau NIM mahasiswa agar
-- dashboard analisis sentimen 100% bebas dari identitas pribadi mahasiswa.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE `evaluations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_code` VARCHAR(32) NOT NULL COMMENT 'Kode bukti publik acak, misal EV-2026-X9A1',
  `period_id` INT UNSIGNED NOT NULL,
  `activity_id` INT UNSIGNED NOT NULL,
  `faculty` VARCHAR(100) NOT NULL DEFAULT 'Umum / Tidak Disebutkan',
  `study_program` VARCHAR(100) NOT NULL DEFAULT 'Umum / Tidak Disebutkan',
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 1,
  `rating_materi` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_pemateri` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_panitia` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_fasilitas` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_konsumsi` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_jadwal` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `rating_keseluruhan` TINYINT UNSIGNED NOT NULL COMMENT 'Skala 1-5',
  `average_rating` DECIMAL(3,2) GENERATED ALWAYS AS (
    ROUND((`rating_materi` + `rating_pemateri` + `rating_panitia` + `rating_fasilitas` + `rating_konsumsi` + `rating_jadwal` + `rating_keseluruhan`) / 7.0, 2)
  ) STORED,
  `comment_experience` TEXT NOT NULL COMMENT 'Komentar pengalaman mahasiswa (telah disanitasi PII)',
  `comment_critique` TEXT DEFAULT NULL COMMENT 'Kritik dan saran perbaikan (telah disanitasi PII)',
  `is_dummy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Data dummy khusus pengujian/demonstrasi, 0 = Data evaluasi nyata',
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_evaluations_code` (`evaluation_code`),
  KEY `idx_evaluations_period_activity` (`period_id`, `activity_id`),
  KEY `idx_evaluations_faculty_prodi` (`faculty`, `study_program`),
  KEY `idx_evaluations_submitted_dummy` (`submitted_at`, `is_dummy`),
  KEY `idx_evaluations_rating` (`rating_keseluruhan`),
  CONSTRAINT `fk_evaluations_period` FOREIGN KEY (`period_id`)
    REFERENCES `periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluations_activity` FOREIGN KEY (`activity_id`)
    REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Tabel user_participation_logs: Pencatatan Partisipasi Terpisah dari Komentar
-- Digunakan hanya apabila mahasiswa login dengan akun untuk mencatat bahwa
-- mahasiswa telah berpartisipasi pada suatu sesi tanpa menautkan ke evaluations.id
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_participation_logs`;
CREATE TABLE `user_participation_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `period_id` INT UNSIGNED NOT NULL,
  `activity_id` INT UNSIGNED NOT NULL,
  `receipt_token_hash` CHAR(64) NOT NULL COMMENT 'Hash SHA-256 satu arah dari kode evaluasi',
  `participated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_activity_participation` (`user_id`, `activity_id`),
  KEY `idx_participation_period` (`period_id`),
  CONSTRAINT `fk_participation_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_participation_period` FOREIGN KEY (`period_id`)
    REFERENCES `periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_participation_activity` FOREIGN KEY (`activity_id`)
    REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Tabel sentiment_results: Hasil Klasifikasi Sentimen Komentar Evaluasi
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sentiment_results`;
CREATE TABLE `sentiment_results` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `sentiment_label` ENUM('positif', 'negatif', 'netral') NOT NULL DEFAULT 'netral',
  `confidence_score` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  `prob_positif` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  `prob_negatif` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  `prob_netral` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  `analysis_status` ENUM('analyzed', 'low_confidence', 'too_short', 'empty', 'fallback_rule', 'pending') NOT NULL DEFAULT 'analyzed',
  `is_ml` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Model Machine Learning, 0 = Fallback Lexicon / Rule',
  `model_version` VARCHAR(60) NOT NULL DEFAULT 'tfidf-logreg-nb-v1.180',
  `cleaned_text` TEXT DEFAULT NULL,
  `rating_sentiment_discrepancy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Rating angka bertolak belakang dengan sentimen teks',
  `analyzed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sentiment_evaluation` (`evaluation_id`),
  KEY `idx_sentiment_label_status` (`sentiment_label`, `analysis_status`),
  KEY `idx_sentiment_analyzed_at` (`analyzed_at`),
  CONSTRAINT `fk_sentiment_evaluation` FOREIGN KEY (`evaluation_id`)
    REFERENCES `evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Tabel sentiment_datasets: Dataset Berlabel untuk Pelatihan Model Sentimen
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sentiment_datasets`;
CREATE TABLE `sentiment_datasets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `text_content` TEXT NOT NULL COMMENT 'Teks komentar berlabel yang sudah bebas PII',
  `sentiment_label` ENUM('positif', 'negatif', 'netral') NOT NULL,
  `aspect_category` VARCHAR(50) NOT NULL DEFAULT 'keseluruhan',
  `source` VARCHAR(60) NOT NULL DEFAULT 'manual_admin',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_datasets_label` (`sentiment_label`),
  KEY `idx_datasets_aspect` (`aspect_category`),
  CONSTRAINT `fk_datasets_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Tabel model_metrics: Hasil Pengujian Performa Model (Accuracy, Precision, Recall, F1)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `model_metrics`;
CREATE TABLE `model_metrics` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `model_version` VARCHAR(60) NOT NULL,
  `evaluation_method` VARCHAR(120) NOT NULL,
  `dataset_size` INT UNSIGNED NOT NULL,
  `vocabulary_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `accuracy` DECIMAL(6,4) NOT NULL,
  `precision_score` DECIMAL(6,4) NOT NULL,
  `recall_score` DECIMAL(6,4) NOT NULL,
  `f1_score` DECIMAL(6,4) NOT NULL,
  `metrics_detail_json` LONGTEXT DEFAULT NULL COMMENT 'Detail per-class & confusion matrix dalam JSON',
  `trained_by` INT UNSIGNED DEFAULT NULL,
  `evaluated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_metrics_version` (`model_version`),
  KEY `idx_metrics_evaluated_at` (`evaluated_at`),
  CONSTRAINT `fk_metrics_user` FOREIGN KEY (`trained_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Tabel audit_logs: Catatan Aktivitas Penting Administrator & Panitia
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `role_snapshot` VARCHAR(30) DEFAULT NULL,
  `action_type` VARCHAR(60) NOT NULL,
  `target_entity` VARCHAR(60) NOT NULL,
  `target_id` VARCHAR(60) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_action` (`user_id`, `action_type`),
  KEY `idx_audit_created_at` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
