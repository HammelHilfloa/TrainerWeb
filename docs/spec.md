# Fachliche Spezifikation (Ableitung aus HTML-Sheets)

## Quellenbasis
Die Ableitung basiert auf den Tabellenfeldern der bereitgestellten HTML-Sheets in `database/`.
Hinweis: Eine Datei `SETTINGS.html` konnte im Repository nicht gefunden werden und sollte nachgereicht oder bestätigt werden.

## Module
### Login
- Anmeldung erfolgt über **„Name (Vor + Nachname exakt) + PIN“**.
- PIN ist **4–10-stellig** (numerisch) und wird im Trainer-Datensatz hinterlegt (PIN-Hash).

### Übersicht (Dashboard)
- Trainings-Übersicht mit Datum, Start/Ende, Gruppe, Ort, benötigten Trainern, bereits eingeteilten Trainern, offenen Plätzen und Training-ID.
- Schneller Zugriff auf kommende Einheiten.

### Einsätze
- Trainings-Einteilungen pro Training inkl. Rolle, Anwesenheit/Check-in, Kommentar, Trainingsdauer, Satz und Betrag.
- Abmeldungen von Trainings mit Grund und Zeitstempel.
- Turnier-Einsätze pro Turnier/Datum inkl. Rolle, Anwesenheit, Tagessatz, Freigabe und Kommentar.

### Abrechnung
- Trainings-Abrechnung je Trainer (Einheiten, Stunden-Summe, Standard-Stundensatz, Betrags-Summe).
- Turnier-Abrechnung je Trainer (Pauschale-Summe, Kilometer-Summe, Gesamtbetrag).

### Admin
- Trainerverwaltung (Name, E-Mail, Aktiv-Status, Stundensatz, Standardrolle, Notizen, PIN-Hash, Admin-Flag, letzter Login).
- Rollensätze (Rolle, Stundensatz, abrechenbar).

### Trainingspläne
- Trainingspläne pro Training mit Titel, Inhalt, Link sowie Audit-Informationen (Created/Updated/Deleted inkl. Benutzer und Zeit).

### Turniere
- Turniere mit Datum, Ort, Pauschale pro Tag, KM-Satz und Bemerkung.
- Turnier-Einsätze und Fahrten (Anfahrten) mit Freigabe-Status.

### Fahrten
- Fahrten je Turnier/Datum mit Fahrer, Kilometer-Gesamt, KM-Satz, KM-Betrag, Freigabe und Kommentar.
- Mitfahrer je Fahrt mit optionalem Kommentar.

## Geschäftsregeln
### Abrechnung pro Einheit
- **Training:**
  - Abrechnung basiert auf Einteilungen je Training (Dauer in Stunden) und dem gültigen Stundensatz (Rolle oder individueller Trainer-Satz).
  - Summe je Trainer = Summe der (Training-Dauer × Satz), aggregiert im Trainings-Abrechnungsmodul.
- **Turnier:**
  - Abrechnung je Trainer setzt sich aus **Pauschale pro Einsatztag** und **Kilometer-Erstattung** zusammen.
  - Kilometer-Erstattung basiert auf Fahrten (KM-Gesamt × KM-Satz), ggf. auf Fahrer verteilt; Gesamtsumme pro Trainer wird im Turnier-Abrechnungsmodul ausgewiesen.

### Sperren / Freigeben / Entsperren
- **Freigabe** (Training/Turnier/Fahrt) kennzeichnet einen Datensatz als abrechnungsreif und schützt ihn vor Änderungen (Sperrlogik).
- **Entsperren** ermöglicht Korrekturen (z. B. falsche Zeiten, falsche Kilometer, falsche Rolle).
- **Sperren** kann zentral durch Admin erfolgen, z. B. für einen Abrechnungszeitraum, um Nachbearbeitung zu verhindern.

### Statuslogik
- **Training-Status:** `geplant`, `stattgefunden`, `ausgefallen`.
  - Bei `ausgefallen` wird ein Ausfallgrund gepflegt (z. B. Ferien/Feiertage).
  - Nur `stattgefunden` ist standardmäßig abrechenbar.
- **Einteilung:**
  - Einteilung enthält Rolle, (geplante) Anwesenheit/Check-in und optionalen Kommentar.
  - Einteilung kann „offen“ oder entfernt werden (z. B. durch Aus-/Eintragen), was die offenen Plätze im Training beeinflusst.
- **Turnier-Einsatz/Fahrt:**
  - Freigabe-Status steuert Abrechenbarkeit; ohne Freigabe keine Auszahlung.

## Screens & Navigation (Bottom-Navigation)
Vorschlag für eine mobile Bottom-Navigation mit 5–6 Hauptzielen:
1. **Übersicht** (Dashboard)
2. **Einsätze** (Trainings-Einteilungen, Abmeldungen, Turnier-Einsätze)
3. **Trainingspläne**
4. **Turniere** (inkl. Fahrten & Mitfahrer)
5. **Abrechnung** (Training/Turnier-Auswertung)
6. **Admin** (nur für Admin-User sichtbar)

## ER-Übersicht (Entitäten & Beziehungen)
- **Trainer** (trainer_id, Name, E-Mail, Aktiv, Stundensatz, Standardrolle, PIN-Hash, is_admin, last_login)
  - 1:n zu **Einteilung**
  - 1:n zu **Abmeldung**
  - 1:n zu **TurnierEinsatz**
  - 1:n zu **Fahrt** (als Fahrer)
  - 1:n zu **Mitfahrer**

- **RolleSatz** (rolle, stundensatz_eur, abrechenbar)
  - 1:n zu **Einteilung** (Rolle bestimmt Satz)
  - 1:n zu **TurnierEinsatz** (Rolle bestimmt Pauschale/Tagessatz)

- **Training** (training_id, datum, start, ende, dauer_stunden, gruppe, ort, benoetigt_trainer, status, ausfall_grund, bemerkung)
  - 1:n zu **Einteilung**
  - 1:n zu **Abmeldung**
  - 1:n zu **Trainingsplan**

- **Einteilung** (einteilung_id, training_id, trainer_id, rolle, eingetragen_am, ausgetragen_am, attendance, checkin_am, kommentar, satz_eur, betrag_eur)
  - n:1 zu **Training**
  - n:1 zu **Trainer**
  - n:1 zu **RolleSatz**

- **Abmeldung** (abmeldung_id, training_id, trainer_id, grund, created_at, deleted_at)
  - n:1 zu **Training**
  - n:1 zu **Trainer**

- **Trainingsplan** (plan_id, training_id, titel, inhalt, link, created_at/by, updated_at/by, deleted_at)
  - n:1 zu **Training**

- **Turnier** (turnier_id, name, datum_von/bis, ort, pauschale_tag_eur, km_satz_eur, bemerkung)
  - 1:n zu **TurnierEinsatz**
  - 1:n zu **Fahrt**

- **TurnierEinsatz** (turnier_einsatz_id, turnier_id, datum, trainer_id, rolle, anwesend, pauschale_tag_eur, freigegeben, kommentar)
  - n:1 zu **Turnier**
  - n:1 zu **Trainer**
  - n:1 zu **RolleSatz**

- **Fahrt** (fahrt_id, turnier_id, datum, fahrer_trainer_id, km_gesamt, km_satz_eur, km_betrag_eur, freigegeben, kommentar)
  - n:1 zu **Turnier**
  - n:1 zu **Trainer** (Fahrer)
  - 1:n zu **Mitfahrer**

- **Mitfahrer** (mitfahrer_id, fahrt_id, trainer_id, kommentar)
  - n:1 zu **Fahrt**
  - n:1 zu **Trainer**

- **AbrechnungTraining** (trainer_id, name, einheiten, stunden_summe, stundensatz_default, betrag_summe)
  - n:1 zu **Trainer**
  - Aggregation aus **Einteilung** und **Training**

- **AbrechnungTurnier** (trainer_id, name, pauschale_summe, km_summe, gesamt)
  - n:1 zu **Trainer**
  - Aggregation aus **TurnierEinsatz** und **Fahrt**
