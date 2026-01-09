#!/usr/bin/env python3
import argparse
import html
import os
import re
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple


def parse_schema(schema_path: Path) -> Dict[str, List[str]]:
    text = schema_path.read_text(encoding="utf-8")
    tables: Dict[str, List[str]] = {}
    create_re = re.compile(
        r"CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)\s*\((.*?)\)\s*ENGINE",
        re.S,
    )
    for match in create_re.finditer(text):
        table = match.group(1)
        body = match.group(2)
        columns: List[str] = []
        for line in body.splitlines():
            line = line.strip().rstrip(",")
            if not line:
                continue
            upper = line.upper()
            if upper.startswith("PRIMARY KEY") or upper.startswith("CONSTRAINT"):
                continue
            col_match = re.match(r"`?([a-zA-Z0-9_]+)`?\s+", line)
            if col_match:
                columns.append(col_match.group(1))
        tables[table] = columns
    return tables


@dataclass
class ParsedTable:
    rows: List[List[str]]


class HTMLTableParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_tbody = False
        self.in_row = False
        self.in_cell = False
        self.rows: List[List[str]] = []
        self.current_row: List[str] = []
        self.current_cell: List[str] = []

    def handle_starttag(self, tag: str, attrs: Sequence[Tuple[str, Optional[str]]]) -> None:
        if tag == "tbody":
            self.in_tbody = True
        if not self.in_tbody:
            return
        if tag == "tr":
            self.in_row = True
            self.current_row = []
        elif tag in {"td", "th"} and self.in_row:
            self.in_cell = True
            self.current_cell = []

    def handle_endtag(self, tag: str) -> None:
        if tag == "tbody":
            self.in_tbody = False
        if not self.in_tbody and tag != "tbody":
            return
        if tag in {"td", "th"} and self.in_cell:
            cell_text = html.unescape("".join(self.current_cell)).strip()
            self.current_row.append(cell_text)
            self.in_cell = False
        elif tag == "tr" and self.in_row:
            if self.current_row:
                self.rows.append(self.current_row)
            self.in_row = False

    def handle_data(self, data: str) -> None:
        if self.in_cell:
            self.current_cell.append(data)


def parse_html_table(path: Path) -> ParsedTable:
    parser = HTMLTableParser()
    parser.feed(path.read_text(encoding="utf-8"))
    return ParsedTable(rows=parser.rows)


def normalize_header(value: str) -> str:
    return value.strip().lower().replace(" ", "_")


def normalize_value(value: str) -> Optional[str | int]:
    if value is None:
        return None
    cleaned = value.strip()
    if cleaned == "":
        return None
    upper = cleaned.upper()
    if upper in {"TRUE", "WAHR"}:
        return 1
    if upper in {"FALSE", "FALSCH"}:
        return 0
    if upper == "NULL":
        return None
    return cleaned


def find_header_row(
    rows: List[List[str]], schema_columns: List[str]
) -> Optional[Tuple[int, List[int], List[str]]]:
    schema_set = {normalize_header(col) for col in schema_columns}
    for idx, row in enumerate(rows):
        if not row:
            continue
        normalized = [normalize_header(cell) for cell in row if cell.strip()]
        if not normalized:
            continue
        if all(cell in schema_set for cell in normalized):
            col_indices: List[int] = []
            col_names: List[str] = []
            for col_idx, cell in enumerate(row):
                normalized_cell = normalize_header(cell)
                if normalized_cell in schema_set:
                    for original_col in schema_columns:
                        if normalize_header(original_col) == normalized_cell:
                            col_indices.append(col_idx)
                            col_names.append(original_col)
                            break
            if col_indices:
                return idx, col_indices, col_names
    return None


def get_mysql_connector():
    try:
        import mysql.connector as connector  # type: ignore

        return connector
    except ImportError:
        try:
            import pymysql as connector  # type: ignore

            return connector
        except ImportError:
            return None


def insert_rows(
    connection,
    table: str,
    columns: List[str],
    rows: List[List[Optional[str | int]]],
) -> int:
    if not rows:
        return 0
    placeholders = ", ".join(["%s"] * len(columns))
    columns_sql = ", ".join(f"`{col}`" for col in columns)
    sql = f"INSERT INTO `{table}` ({columns_sql}) VALUES ({placeholders})"
    cursor = connection.cursor()
    cursor.executemany(sql, rows)
    connection.commit()
    return cursor.rowcount


def resolve_table(
    html_path: Path,
    rows: List[List[str]],
    schema: Dict[str, List[str]],
) -> Tuple[str, Tuple[int, List[int], List[str]]]:
    stem = html_path.stem.lower()
    candidates = [stem] if stem in schema else list(schema.keys())
    for table in candidates:
        header = find_header_row(rows, schema[table])
        if header:
            return table, header
    if stem in schema:
        raise ValueError(f"Keine gültige Kopfzeile in {html_path} für Tabelle {stem} gefunden.")
    raise ValueError(f"Keine passende Tabelle für {html_path} gefunden.")


def collect_rows(
    rows: List[List[str]],
    header_info: Tuple[int, List[int], List[str]],
) -> List[List[Optional[str | int]]]:
    header_idx, col_indices, _ = header_info
    data_rows: List[List[Optional[str | int]]] = []
    for row in rows[header_idx + 1 :]:
        values: List[Optional[str | int]] = []
        for col_idx in col_indices:
            raw = row[col_idx] if col_idx < len(row) else ""
            values.append(normalize_value(raw))
        if all(value is None for value in values):
            continue
        data_rows.append(values)
    return data_rows


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Importiert HTML-Tabellen aus database/*.html in MySQL."
    )
    parser.add_argument(
        "--database-dir",
        default="database",
        help="Pfad zum Verzeichnis mit HTML-Exports.",
    )
    parser.add_argument(
        "--schema",
        default="database/schema.sql",
        help="Pfad zu schema.sql.",
    )
    parser.add_argument("--host", default=os.getenv("MYSQL_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.getenv("MYSQL_PORT", "3306")))
    parser.add_argument("--user", default=os.getenv("MYSQL_USER", "root"))
    parser.add_argument("--password", default=os.getenv("MYSQL_PASSWORD", ""))
    parser.add_argument("--db", default=os.getenv("MYSQL_DATABASE", "trainer"))
    parser.add_argument("--dry-run", action="store_true", help="Nur prüfen, nichts importieren.")
    args = parser.parse_args()

    schema = parse_schema(Path(args.schema))
    html_files = sorted(Path(args.database_dir).glob("*.html"))
    connector = get_mysql_connector()
    if connector is None:
        raise SystemExit(
            "Kein MySQL-Python-Treiber gefunden. Installiere mysql-connector-python oder pymysql."
        )

    connection = None
    if not args.dry_run:
        connection = connector.connect(
            host=args.host,
            port=args.port,
            user=args.user,
            password=args.password,
            database=args.db,
        )

    total_imported = 0
    for html_path in html_files:
        parsed = parse_html_table(html_path)
        if not parsed.rows:
            continue
        table, header_info = resolve_table(html_path, parsed.rows, schema)
        _, _, columns = header_info
        data_rows = collect_rows(parsed.rows, header_info)
        if args.dry_run:
            print(f"[DRY RUN] {html_path.name}: {table} -> {len(data_rows)} Zeilen")
            continue
        if connection is None:
            raise SystemExit("MySQL-Verbindung fehlt.")
        inserted = insert_rows(connection, table, columns, data_rows)
        total_imported += inserted
        print(f"{html_path.name}: {table} -> {inserted} Zeilen")

    if connection:
        connection.close()
    if args.dry_run:
        print("Dry-Run abgeschlossen.")
    else:
        print(f"Import abgeschlossen. Insgesamt {total_imported} Zeilen.")


if __name__ == "__main__":
    main()
