# HTML-Import in MySQL

Dieses Repository enthält HTML-Exporte der Google-Tabellen unter `database/*.html` sowie das Ziel-Schema in `database/schema.sql`. Der Importer liest die Tabellen aus den HTML-Dateien und schreibt die Zeilen in die entsprechenden MySQL-Tabellen.

## Voraussetzungen

- Python 3.10+
- Ein MySQL-Treiber für Python:
  - `mysql-connector-python` **oder**
  - `pymysql`

Beispiel:

```bash
pip install mysql-connector-python
```

## Erwartete Dateipfade

- HTML-Exporte: `database/*.html`
- Schema: `database/schema.sql`
- Import-Skript: `scripts/import_html_tables.py`

## Importablauf

1. Schema anlegen (falls noch nicht vorhanden):

   ```bash
   mysql -u root -p trainer < database/schema.sql
   ```

2. Import ausführen:

   ```bash
   python3 scripts/import_html_tables.py \
     --host 127.0.0.1 \
     --port 3306 \
     --user root \
     --password "<passwort>" \
     --db trainer
   ```

3. Optionaler Dry-Run (liest und zählt Zeilen, schreibt nichts):

   ```bash
   python3 scripts/import_html_tables.py --dry-run
   ```

## Normalisierung

Der Importer normalisiert die Werte beim Einlesen:

- `TRUE` / `WAHR` → `1`
- `FALSE` / `FALSCH` → `0`
- Leere Felder → `NULL`

## Zuordnung der Tabellen

- Standardmäßig wird der Dateiname (z. B. `TRAINER.html`) in Kleinbuchstaben auf eine Tabelle gemappt (`trainer`).
- Falls kein direktes Mapping existiert, wird die Kopfzeile mit `schema.sql` abgeglichen.

