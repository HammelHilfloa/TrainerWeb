# TrainerWeb auf Netcup Webhosting 4000 installieren (Schritt-für-Schritt)

Diese Anleitung erklärt für absolute Anfänger, wie du TrainerWeb auf einem Netcup **Webhosting 4000** Paket installierst, einrichtest und startest. Du beginnst beim FTP-Upload, richtest die Datenbank ein und trägst die `.env`-Konfiguration ein.

> **Kurzüberblick**
> 1. Datenbank im Netcup CCP anlegen
> 2. Dateien per FTP hochladen
> 3. Datenbankschema importieren
> 4. `.env` anlegen und konfigurieren
> 5. Aufruf testen

---

## 1) Voraussetzungen

- **Netcup Webhosting 4000** Zugang (CCP-Zugangsdaten)
- Ein **FTP-Programm** (z. B. FileZilla)
- Grundlegender Zugriff auf die Datenbank-Verwaltung in Netcup (meist **phpMyAdmin**)

---

## 2) Zugangsdaten im Netcup CCP finden

1. Melde dich im **Netcup Customer Control Panel (CCP)** an.
2. Öffne dein **Webhosting 4000** Paket.
3. Notiere dir:
   - **FTP-Host** (z. B. `deinhostingXYZ.netcup.net`)
   - **FTP-Benutzername**
   - **FTP-Passwort**
   - **Datenbank-Host** (häufig `localhost` oder ein interner Hostname)

> Tipp: Die FTP-Zugangsdaten findest du im CCP unter **Webhosting** → **FTP**.

---

## 3) Datenbank anlegen

1. Gehe im CCP auf **Datenbanken** und erstelle eine neue **MySQL/MariaDB** Datenbank.
2. Lege **Benutzername** und **Passwort** fest.
3. Notiere dir:
   - Datenbankname
   - Datenbankbenutzer
   - Datenbankpasswort
   - Datenbankhost

Diese Werte brauchst du später für die `.env`.

---

## 4) Dateien per FTP hochladen

### 4.1 FTP-Programm einrichten (FileZilla)

1. Öffne FileZilla.
2. Gehe zu **Datei → Servermanager → Neuer Server**.
3. Trage deine FTP-Daten ein:
   - **Protokoll:** FTP oder FTPS (falls im CCP angegeben)
   - **Server/Host:** (aus CCP)
   - **Benutzername** und **Passwort**
4. Verbinde dich.

### 4.2 Zielordner im Webspace

Bei Netcup ist der **Webroot** meistens:

```
/httpdocs
```

Öffne diesen Ordner auf der rechten Seite (Serverseite) in FileZilla.

### 4.3 Dateien hochladen

Lade **den kompletten Inhalt** des Repositories hoch:

- `public/` (wichtig: enthält die öffentlich erreichbaren Dateien)
- `database/`
- `scripts/` (nur falls du die Import-Skripte nutzen willst)
- `.env` (legen wir gleich an)

**Wichtig:** Die Dateien im Ordner `public/` müssen im Webroot (`/httpdocs`) landen, weil dort die Startseite und die API liegen.

Die Struktur sollte so aussehen:

```
/httpdocs
  ├── public
  │   ├── index.html
  │   └── api
  │       └── index.php
  ├── database
  │   └── schema.sql
  ├── scripts
  └── .env
```

---

## 5) Datenbankschema importieren

1. Öffne im Netcup CCP den **phpMyAdmin** Zugang deiner Datenbank.
2. Wähle die Datenbank aus.
3. Klicke auf **Importieren**.
4. Wähle die Datei `database/schema.sql` aus deinem Projekt.
5. Import starten.

Jetzt ist das Datenbankschema angelegt.

---

## 6) `.env` Datei erstellen und konfigurieren

Die Anwendung liest Konfigurationen aus Umgebungsvariablen oder aus einer `.env` Datei im Projekt-Root.

### 6.1 `.env` anlegen

Erstelle im Projekt-Root eine Datei `.env` (gleiche Ebene wie `public/`).
Du kannst die Datei `.env.example` als Vorlage nehmen.

Beispiel-Inhalt:

```
# Datenbank
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=deine_datenbank
DB_USER=dein_benutzer
DB_PASS=dein_passwort

# Anwendung
SESSION_TTL_SECONDS=28800
APP_TIMEZONE=Europe/Berlin

# Apps-Script-Konfiguration (falls genutzt)
SPREADSHEET_ID=
SHEET_TRAINER=TRAINER
SHEET_TRAININGS=TRAININGS
SHEET_EINTEILUNGEN=EINTEILUNGEN
SHEET_ABMELDUNGEN=ABMELDUNGEN
SHEET_TRAININGSPLAN=TRAININGSPLAN
SHEET_ROLLEN_SAETZE=ROLLEN_SAETZE
SHEET_TURNIERE=TURNIERE
SHEET_TURNIER_EINSAETZE=TURNIER_EINSAETZE
SHEET_FAHRTEN=FAHRTEN
```

**Wichtig:**
- `DB_HOST` ist bei Netcup oft `localhost` oder ein spezieller Host aus dem CCP.
- `DB_NAME`, `DB_USER`, `DB_PASS` müssen exakt den Datenbankdaten entsprechen.

Lade die `.env` danach per FTP hoch (oder bearbeite sie direkt auf dem Server, wenn möglich).

---

## 7) Server-Module (PHP)

Auf Netcup Webhosting 4000 ist PHP 8.x verfügbar. Achte darauf, dass folgende Module aktiv sind:

- `pdo_mysql`
- `json`
- empfohlen: `mbstring`, `openssl`

Im CCP kannst du unter **PHP-Einstellungen** prüfen, welche Module aktiv sind.

---

## 8) Anwendung starten und testen

Es gibt keinen extra „Start“-Befehl – die Anwendung läuft automatisch, sobald die Dateien im Webroot liegen.

Teste die Installation so:

1. Öffne im Browser:

```
https://deine-domain.de/public/
```

2. Teste die API:

```
https://deine-domain.de/public/api/bootstrap
```

Wenn die API erreichbar ist und keine Datenbank-Fehler erscheinen, ist die Einrichtung erfolgreich.

---

## 9) Häufige Fehler & Lösungen

### Fehler: „Datenbankverbindung fehlgeschlagen“
- Prüfe `DB_HOST`, `DB_USER`, `DB_PASS` in `.env`.
- Stelle sicher, dass die Datenbank im CCP wirklich existiert.

### Fehler: „Seite nicht gefunden“
- Prüfe, ob `public/` im Webroot liegt (`/httpdocs/public`).
- Öffne die URL **mit** `/public/`.

### Fehler: „500 Internal Server Error“
- Prüfe PHP-Version (8.x) und aktivierte Module.
- Prüfe die Server-Fehlerlogs im CCP.

---

## 10) Optional: Cronjob für Sessions

Wenn du alte Sessions automatisch löschen möchtest, kannst du einen Cronjob einrichten, der alte Einträge in der `sessions`-Tabelle bereinigt. Das ist optional, aber empfohlen bei langfristiger Nutzung.

---

## Fertig!

Wenn alle Schritte abgeschlossen sind, ist TrainerWeb auf deinem Netcup Webhosting 4000 live.

Wenn du irgendwo hängen bleibst, schau in `DEPLOYMENT.md` für eine kompakte Checkliste oder prüfe die Logs im CCP.
