-- Datenbankschema für Trainerabrechnung & Trainingsplanung
-- MySQL 8.x empfohlen

CREATE TABLE roles (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(30) NULL,
    email_verified_at TIMESTAMP NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_roles FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trainer_statuses (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(30) UNIQUE NOT NULL,
    title VARCHAR(100) NOT NULL,
    hourly_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trainers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    iban VARCHAR(34) NULL,
    bic VARCHAR(11) NULL,
    tax_number VARCHAR(50) NULL,
    employment_type ENUM('mini_job','honorarbasis','angestellt') NOT NULL DEFAULT 'honorarbasis',
    default_location VARCHAR(150) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_trainers_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_trainers_status FOREIGN KEY (status_id) REFERENCES trainer_statuses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_locations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    postal_code VARCHAR(15) NULL,
    gps_point POINT NULL,
    INDEX idx_training_locations_point (gps_point)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_groups (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    age_group VARCHAR(50) NULL,
    intensity ENUM('basic','advanced','pro') DEFAULT 'basic'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_sessions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    scheduled_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    plan_details TEXT NULL,
    planned_by BIGINT UNSIGNED NULL,
    plan_updated_at TIMESTAMP NULL DEFAULT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    recurrence_rule VARCHAR(120) NULL,
    max_trainers INT UNSIGNED NOT NULL DEFAULT 2,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_group FOREIGN KEY (group_id) REFERENCES training_groups(id),
    CONSTRAINT fk_sessions_location FOREIGN KEY (location_id) REFERENCES training_locations(id),
    CONSTRAINT fk_sessions_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_sessions_updater FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_sessions_planner FOREIGN KEY (planned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_assignments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    trainer_id BIGINT UNSIGNED NOT NULL,
    status ENUM('zugeteilt','abgesagt','warteliste') NOT NULL DEFAULT 'zugeteilt',
    assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at TIMESTAMP NULL,
    cancellation_reason VARCHAR(255) NULL,
    CONSTRAINT fk_assignments_session FOREIGN KEY (session_id) REFERENCES training_sessions(id),
    CONSTRAINT fk_assignments_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trainer_unavailabilities (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    trainer_id BIGINT UNSIGNED NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_unavail_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cancellations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    cancelled_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NULL,
    cancelled_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    is_reimbursable TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_cancellations_session FOREIGN KEY (session_id) REFERENCES training_sessions(id),
    CONSTRAINT fk_cancellations_user FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournaments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(200) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    registration_deadline DATE NULL,
    notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournament_teams (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    age_group VARCHAR(50) NULL,
    CONSTRAINT fk_tournament_teams FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournament_assignments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tournament_team_id BIGINT UNSIGNED NOT NULL,
    trainer_id BIGINT UNSIGNED NOT NULL,
    role ENUM('coach','assistant','driver') NOT NULL DEFAULT 'coach',
    status ENUM('geplant','bestätigt','abgesagt') NOT NULL DEFAULT 'geplant',
    notes VARCHAR(255) NULL,
    CONSTRAINT fk_tournament_assignment_team FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id),
    CONSTRAINT fk_tournament_assignment_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournament_trips (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    departure_at DATETIME NOT NULL,
    return_at DATETIME NOT NULL,
    vehicle VARCHAR(100) NULL,
    mileage_km INT UNSIGNED NULL,
    cost_estimate DECIMAL(10,2) NULL,
    CONSTRAINT fk_tournament_trips FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournament_trip_segments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NOT NULL,
    trainer_id BIGINT UNSIGNED NULL,
    role ENUM('driver','passenger') DEFAULT 'passenger',
    from_location VARCHAR(150) NOT NULL,
    to_location VARCHAR(150) NOT NULL,
    depart_at DATETIME NOT NULL,
    arrive_at DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    CONSTRAINT fk_trip_segments_trip FOREIGN KEY (trip_id) REFERENCES tournament_trips(id),
    CONSTRAINT fk_trip_segments_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    trainer_id BIGINT UNSIGNED NOT NULL,
    half_year ENUM('H1','H2') NOT NULL,
    year SMALLINT NOT NULL,
    generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('entwurf','versendet','bezahlt') NOT NULL DEFAULT 'entwurf',
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    approved_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_invoices_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id),
    CONSTRAINT fk_invoices_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    CONSTRAINT fk_invoice_items_assignment FOREIGN KEY (assignment_id) REFERENCES training_assignments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    meta JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beispiel-Daten für Rollen und Trainer-Status
INSERT INTO roles (name, description) VALUES
('admin', 'Kann Trainings, Benutzer und Abrechnungen verwalten'),
('trainer', 'Kann eigene Einteilung, Abwesenheiten und Turniere pflegen');

INSERT INTO trainer_statuses (code, title, hourly_rate, description) VALUES
('senior', 'Senior-Trainer:in', 35.00, 'Leitet eigenständig Einheiten'),
('assistant', 'Co-Trainer:in', 20.00, 'Unterstützt die Haupttrainer:innen'),
('volunteer', 'Ehrenamt', 0.00, 'Ohne Vergütung, nur Aufwandsersatz');
