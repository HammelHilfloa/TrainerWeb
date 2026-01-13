-- Datenbankschema für das KSV Homberg Trainertool (MySQL)
-- Wird später vom Installer importiert.

SET NAMES utf8mb4;

CREATE TABLE `trainer` (
  `trainer_id` VARCHAR(32) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NULL,
  `aktiv` TINYINT(1) NOT NULL DEFAULT 1,
  `stundensatz_eur` DECIMAL(10,2) NULL,
  `rolle_standard` VARCHAR(100) NULL,
  `notizen` TEXT NULL,
  `pin` VARCHAR(255) NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login` DATE NULL,
  PRIMARY KEY (`trainer_id`),
  KEY `idx_trainer_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trainings` (
  `training_id` VARCHAR(32) NOT NULL,
  `datum` DATE NULL,
  `start` TIME NULL,
  `ende` TIME NULL,
  `dauer_stunden` TIME NULL,
  `gruppe` VARCHAR(100) NULL,
  `ort` VARCHAR(255) NULL,
  `benoetigt_trainer` INT NULL,
  `status` VARCHAR(50) NULL,
  `ausfall_grund` VARCHAR(255) NULL,
  `bemerkung` TEXT NULL,
  `eingeteilt` INT NULL,
  `offen` INT NULL,
  PRIMARY KEY (`training_id`),
  KEY `idx_trainings_datum` (`datum`),
  KEY `idx_trainings_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `einteilungen` (
  `einteilung_id` VARCHAR(32) NOT NULL,
  `training_id` VARCHAR(32) NOT NULL,
  `trainer_id` VARCHAR(32) NOT NULL,
  `rolle` VARCHAR(100) NULL,
  `eingetragen_am` DATETIME NULL,
  `ausgetragen_am` DATETIME NULL,
  `attendance` VARCHAR(20) NULL,
  `checkin_am` DATETIME NULL,
  `kommentar` TEXT NULL,
  `training_datum` DATE NULL,
  `training_status` VARCHAR(50) NULL,
  `training_dauer_stunden` TIME NULL,
  `satz_eur` DECIMAL(10,2) NULL,
  `betrag_eur` DECIMAL(10,2) NULL,
  PRIMARY KEY (`einteilung_id`),
  KEY `idx_einteilungen_training` (`training_id`),
  KEY `idx_einteilungen_trainer` (`trainer_id`),
  CONSTRAINT `fk_einteilungen_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`),
  CONSTRAINT `fk_einteilungen_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `abmeldungen` (
  `abmeldung_id` VARCHAR(32) NOT NULL,
  `training_id` VARCHAR(32) NOT NULL,
  `trainer_id` VARCHAR(32) NOT NULL,
  `grund` TEXT NULL,
  `created_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`abmeldung_id`),
  KEY `idx_abmeldungen_training` (`training_id`),
  KEY `idx_abmeldungen_trainer` (`trainer_id`),
  CONSTRAINT `fk_abmeldungen_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`),
  CONSTRAINT `fk_abmeldungen_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rollen_saetze` (
  `rolle` VARCHAR(100) NOT NULL,
  `stundensatz_eur` DECIMAL(10,2) NULL,
  `abrechenbar` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`rolle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NULL,
  `description` TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trainingsplan` (
  `plan_id` VARCHAR(32) NOT NULL,
  `training_id` VARCHAR(32) NOT NULL,
  `titel` VARCHAR(255) NULL,
  `inhalt` TEXT NULL,
  `link` TEXT NULL,
  `created_at` DATE NULL,
  `created_by` VARCHAR(32) NULL,
  `updated_at` DATE NULL,
  `updated_by` VARCHAR(32) NULL,
  `deleted_at` DATE NULL,
  PRIMARY KEY (`plan_id`),
  KEY `idx_trainingsplan_training` (`training_id`),
  KEY `idx_trainingsplan_created_by` (`created_by`),
  KEY `idx_trainingsplan_updated_by` (`updated_by`),
  CONSTRAINT `fk_trainingsplan_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`),
  CONSTRAINT `fk_trainingsplan_created_by` FOREIGN KEY (`created_by`) REFERENCES `trainer` (`trainer_id`),
  CONSTRAINT `fk_trainingsplan_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `turniere` (
  `turnier_id` VARCHAR(32) NOT NULL,
  `name` VARCHAR(255) NULL,
  `datum_von` DATE NULL,
  `datum_bis` DATE NULL,
  `ort` VARCHAR(255) NULL,
  `pauschale_tag_eur` DECIMAL(10,2) NULL,
  `km_satz_eur` DECIMAL(10,2) NULL,
  `bemerkung` TEXT NULL,
  PRIMARY KEY (`turnier_id`),
  KEY `idx_turniere_datum_von` (`datum_von`),
  KEY `idx_turniere_datum_bis` (`datum_bis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `turnier_einsaetze` (
  `turnier_einsatz_id` VARCHAR(32) NOT NULL,
  `turnier_id` VARCHAR(32) NULL,
  `datum` DATE NULL,
  `trainer_id` VARCHAR(32) NULL,
  `rolle` VARCHAR(100) NULL,
  `anwesend` VARCHAR(20) NULL,
  `pauschale_tag_eur` DECIMAL(10,2) NULL,
  `freigegeben` TINYINT(1) NULL,
  `kommentar` TEXT NULL,
  PRIMARY KEY (`turnier_einsatz_id`),
  KEY `idx_turnier_einsaetze_turnier` (`turnier_id`),
  KEY `idx_turnier_einsaetze_trainer` (`trainer_id`),
  CONSTRAINT `fk_turnier_einsaetze_turnier` FOREIGN KEY (`turnier_id`) REFERENCES `turniere` (`turnier_id`),
  CONSTRAINT `fk_turnier_einsaetze_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fahrten` (
  `fahrt_id` VARCHAR(32) NOT NULL,
  `turnier_id` VARCHAR(32) NULL,
  `datum` DATE NULL,
  `fahrer_trainer_id` VARCHAR(32) NULL,
  `km_gesamt` DECIMAL(10,2) NULL,
  `km_satz_eur` DECIMAL(10,2) NULL,
  `km_betrag_eur` DECIMAL(10,2) NULL,
  `freigegeben` TINYINT(1) NULL,
  `kommentar` TEXT NULL,
  PRIMARY KEY (`fahrt_id`),
  KEY `idx_fahrten_turnier` (`turnier_id`),
  KEY `idx_fahrten_fahrer` (`fahrer_trainer_id`),
  CONSTRAINT `fk_fahrten_turnier` FOREIGN KEY (`turnier_id`) REFERENCES `turniere` (`turnier_id`),
  CONSTRAINT `fk_fahrten_fahrer` FOREIGN KEY (`fahrer_trainer_id`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mitfahrer` (
  `mitfahrer_id` VARCHAR(32) NOT NULL,
  `fahrt_id` VARCHAR(32) NOT NULL,
  `trainer_id` VARCHAR(32) NOT NULL,
  `kommentar` TEXT NULL,
  PRIMARY KEY (`mitfahrer_id`),
  KEY `idx_mitfahrer_fahrt` (`fahrt_id`),
  KEY `idx_mitfahrer_trainer` (`trainer_id`),
  CONSTRAINT `fk_mitfahrer_fahrt` FOREIGN KEY (`fahrt_id`) REFERENCES `fahrten` (`fahrt_id`),
  CONSTRAINT `fk_mitfahrer_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `abrechnungs_monate` (
  `monat` TINYINT NOT NULL,
  `jahr` SMALLINT NOT NULL,
  `typ` ENUM('training', 'turnier') NOT NULL,
  `status` ENUM('offen', 'gesperrt', 'freigegeben') NOT NULL DEFAULT 'offen',
  `gesperrt_am` DATETIME NULL,
  `gesperrt_von` VARCHAR(32) NULL,
  `freigegeben_am` DATETIME NULL,
  `freigegeben_von` VARCHAR(32) NULL,
  PRIMARY KEY (`monat`, `jahr`, `typ`),
  KEY `idx_abrechnungs_monate_status` (`status`),
  CONSTRAINT `fk_abrechnungs_monate_gesperrt_von` FOREIGN KEY (`gesperrt_von`) REFERENCES `trainer` (`trainer_id`),
  CONSTRAINT `fk_abrechnungs_monate_freigegeben_von` FOREIGN KEY (`freigegeben_von`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `turnier_bedarf` (
  `turnier_id` VARCHAR(32) NOT NULL,
  `datum` DATE NOT NULL,
  `benoetigt_trainer` INT NOT NULL,
  PRIMARY KEY (`turnier_id`, `datum`),
  CONSTRAINT `fk_turnier_bedarf_turnier` FOREIGN KEY (`turnier_id`) REFERENCES `turniere` (`turnier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `audit_id` BIGINT NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` VARCHAR(32) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `payload` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(32) NULL,
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_log_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_log_created_by` (`created_by`),
  CONSTRAINT `fk_audit_log_created_by` FOREIGN KEY (`created_by`) REFERENCES `trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
