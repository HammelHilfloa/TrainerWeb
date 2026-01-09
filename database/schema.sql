-- Auto-generated schema derived from HTML exports in database/.

CREATE TABLE IF NOT EXISTS rollen_saetze (
  rolle VARCHAR(64) NOT NULL,
  stundensatz_eur DECIMAL(10,2) NOT NULL,
  abrechenbar BOOLEAN NOT NULL,
  PRIMARY KEY (rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trainer (
  trainer_id VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  aktiv BOOLEAN NOT NULL,
  stundensatz_eur DECIMAL(10,2),
  rolle_standard VARCHAR(64),
  notizen TEXT,
  pin VARCHAR(255),
  is_admin BOOLEAN NOT NULL,
  last_login DATE,
  PRIMARY KEY (trainer_id),
  CONSTRAINT fk_trainer_rolle_standard
    FOREIGN KEY (rolle_standard) REFERENCES rollen_saetze(rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(128) NOT NULL,
  value TEXT,
  description TEXT,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trainings (
  training_id VARCHAR(32) NOT NULL,
  datum DATE,
  start TIME,
  ende TIME,
  dauer_stunden TIME,
  gruppe VARCHAR(128),
  ort VARCHAR(255),
  benoetigt_trainer INT,
  status VARCHAR(32),
  ausfall_grund TEXT,
  bemerkung TEXT,
  eingeteilt INT,
  offen INT,
  PRIMARY KEY (training_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trainingsplan (
  plan_id VARCHAR(32) NOT NULL,
  training_id VARCHAR(32) NOT NULL,
  titel VARCHAR(255),
  inhalt TEXT,
  link TEXT,
  created_at DATE,
  created_by VARCHAR(32),
  updated_at DATE,
  updated_by VARCHAR(32),
  deleted_at DATE,
  PRIMARY KEY (plan_id),
  CONSTRAINT fk_trainingsplan_training
    FOREIGN KEY (training_id) REFERENCES trainings(training_id),
  CONSTRAINT fk_trainingsplan_created_by
    FOREIGN KEY (created_by) REFERENCES trainer(trainer_id),
  CONSTRAINT fk_trainingsplan_updated_by
    FOREIGN KEY (updated_by) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abmeldungen (
  abmeldung_id VARCHAR(32) NOT NULL,
  training_id VARCHAR(32) NOT NULL,
  trainer_id VARCHAR(32) NOT NULL,
  grund TEXT,
  created_at DATETIME,
  deleted_at DATETIME,
  PRIMARY KEY (abmeldung_id),
  CONSTRAINT fk_abmeldungen_training
    FOREIGN KEY (training_id) REFERENCES trainings(training_id),
  CONSTRAINT fk_abmeldungen_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS einteilungen (
  einteilung_id VARCHAR(32) NOT NULL,
  training_id VARCHAR(32) NOT NULL,
  trainer_id VARCHAR(32) NOT NULL,
  rolle VARCHAR(64),
  eingetragen_am DATETIME,
  ausgetragen_am DATETIME,
  attendance VARCHAR(32),
  checkin_am DATETIME,
  kommentar TEXT,
  training_datum DATE,
  training_status VARCHAR(32),
  training_dauer_stunden TIME,
  satz_eur DECIMAL(10,2),
  betrag_eur DECIMAL(10,2),
  PRIMARY KEY (einteilung_id),
  CONSTRAINT fk_einteilungen_training
    FOREIGN KEY (training_id) REFERENCES trainings(training_id),
  CONSTRAINT fk_einteilungen_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id),
  CONSTRAINT fk_einteilungen_rolle
    FOREIGN KEY (rolle) REFERENCES rollen_saetze(rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abrechnung_training (
  trainer_id VARCHAR(32) NOT NULL,
  name VARCHAR(255),
  einheiten INT,
  stunden_summe DECIMAL(10,4),
  stundensatz_default DECIMAL(10,2),
  betrag_summe DECIMAL(10,2),
  PRIMARY KEY (trainer_id),
  CONSTRAINT fk_abrechnung_training_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS turniere (
  turnier_id VARCHAR(32) NOT NULL,
  name VARCHAR(255),
  datum_von DATE,
  datum_bis DATE,
  ort VARCHAR(255),
  pauschale_tag_eur DECIMAL(10,2),
  km_satz_eur DECIMAL(10,2),
  bemerkung TEXT,
  PRIMARY KEY (turnier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS turnier_einsaetze (
  turnier_einsatz_id VARCHAR(32) NOT NULL,
  turnier_id VARCHAR(32),
  datum DATE,
  trainer_id VARCHAR(32),
  rolle VARCHAR(64),
  anwesend VARCHAR(32),
  pauschale_tag_eur DECIMAL(10,2),
  freigegeben BOOLEAN,
  kommentar TEXT,
  PRIMARY KEY (turnier_einsatz_id),
  CONSTRAINT fk_turnier_einsaetze_turnier
    FOREIGN KEY (turnier_id) REFERENCES turniere(turnier_id),
  CONSTRAINT fk_turnier_einsaetze_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id),
  CONSTRAINT fk_turnier_einsaetze_rolle
    FOREIGN KEY (rolle) REFERENCES rollen_saetze(rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fahrten (
  fahrt_id VARCHAR(32) NOT NULL,
  turnier_id VARCHAR(32),
  datum DATE,
  fahrer_trainer_id VARCHAR(32),
  km_gesamt DECIMAL(10,2),
  km_satz_eur DECIMAL(10,2),
  km_betrag_eur DECIMAL(10,2),
  freigegeben BOOLEAN,
  kommentar TEXT,
  PRIMARY KEY (fahrt_id),
  CONSTRAINT fk_fahrten_turnier
    FOREIGN KEY (turnier_id) REFERENCES turniere(turnier_id),
  CONSTRAINT fk_fahrten_fahrer
    FOREIGN KEY (fahrer_trainer_id) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mitfahrer (
  mitfahrer_id VARCHAR(32) NOT NULL,
  fahrt_id VARCHAR(32),
  trainer_id VARCHAR(32),
  kommentar TEXT,
  PRIMARY KEY (mitfahrer_id),
  CONSTRAINT fk_mitfahrer_fahrt
    FOREIGN KEY (fahrt_id) REFERENCES fahrten(fahrt_id),
  CONSTRAINT fk_mitfahrer_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abrechnung_turnier (
  trainer_id VARCHAR(32) NOT NULL,
  name VARCHAR(255),
  pauschale_summe DECIMAL(10,2),
  km_summe DECIMAL(10,2),
  gesamt DECIMAL(10,2),
  PRIMARY KEY (trainer_id),
  CONSTRAINT fk_abrechnung_turnier_trainer
    FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dashboard_offene_trainer_slots (
  training_id VARCHAR(32) NOT NULL,
  datum DATE,
  start TIME,
  ende TIME,
  gruppe VARCHAR(128),
  ort VARCHAR(255),
  benoetigt INT,
  eingeteilt INT,
  offen INT,
  PRIMARY KEY (training_id),
  CONSTRAINT fk_dashboard_training
    FOREIGN KEY (training_id) REFERENCES trainings(training_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
