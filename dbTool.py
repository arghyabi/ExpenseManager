#!/usr/bin/env python3

import sqlite3
import os
import argparse
from datetime import datetime

MASTER_SQL = "expenseManagerDatabase.sql"
DATA_ENTRY_SQL = "dataEntry.sql"
PRIMARY_SQLS = [MASTER_SQL]
DB_FILE = "expenseManagerDatabase.db"
MIGRATIONS_DIR = "database"

SCHEMA_FILE = "database_schema.sql"
DUMP_SQL_FILE = "database_dump_full.sql"
DUMP_TXT_FILE = "database_dump_table.txt"


# =========================================================
# Helpers
# =========================================================

def connect():
    return sqlite3.connect(DB_FILE)


def get_sql_files():
    sqls = sorted(f for f in os.listdir(MIGRATIONS_DIR)
                    if f.endswith(".sql") and f not in PRIMARY_SQLS)
    return sqls


# =========================================================
# INIT (install base only)
# =========================================================

def init_database():
    if os.path.exists(DB_FILE):
        print("Database already exists. Skipping init.")
        return

    apply_migrations(PRIMARY_SQLS)


# =========================================================
# MIGRATIONS
# =========================================================

def apply_migrations(files):
    conn = connect()
    cur = conn.cursor()

    cur.execute("""
        CREATE TABLE IF NOT EXISTS schema_version(
            version TEXT PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """)

    applied = set(r[0] for r in cur.execute("SELECT version FROM schema_version"))

    for file in files:
        if file not in applied:
            print("Applying", file)
            with open(os.path.join(MIGRATIONS_DIR, file)) as f:
                conn.executescript(f.read())

            cur.execute(
                "INSERT INTO schema_version(version) VALUES(?)",
                (file,)
            )
            conn.commit()

    conn.close()
    print("Migrations complete.")


# =========================================================
# SCHEMA SNAPSHOT
# =========================================================

def dump_schema():
    conn = connect()
    cur = conn.cursor()

    print("Generating schema snapshot...")

    with open(SCHEMA_FILE, "w") as f:
        f.write("-- Schema snapshot\n\n")

        for row in cur.execute("""
            SELECT sql FROM sqlite_master
            WHERE sql NOT NULL
            AND type IN ('table','index','trigger')
            ORDER BY type, name
        """):
            f.write(row[0] + ";\n\n")

    conn.close()
    print("Schema written ->", SCHEMA_FILE)


# =========================================================
# FULL SQL BACKUP
# =========================================================

def dump_sql():
    conn = connect()

    print("Generating SQL dump...")

    with open(DUMP_SQL_FILE, "w") as f:
        for line in conn.iterdump():
            f.write(line + "\n")

    conn.close()
    print("Dump written ->", DUMP_SQL_FILE)


# =========================================================
# HUMAN READABLE TABLE DUMP
# =========================================================

def format_table(headers, rows):
    col_widths = [len(h) for h in headers]

    for row in rows:
        for i, val in enumerate(row):
            col_widths[i] = max(col_widths[i], len(str(val)))

    def fmt(row):
        return " | ".join(str(v).ljust(col_widths[i]) for i, v in enumerate(row))

    sep = "-+-".join("-" * w for w in col_widths)

    out = [fmt(headers), sep]
    for r in rows:
        out.append(fmt(r))

    return "\n".join(out)


def dump_readable():
    conn = connect()
    cur = conn.cursor()

    print("Generating readable data dump...")

    tables = cur.execute("""
        SELECT name FROM sqlite_master
        WHERE type='table'
        AND name NOT LIKE 'sqlite_%'
        AND name != 'schema_version'
        ORDER BY name
    """).fetchall()

    with open(DUMP_TXT_FILE, "w") as f:
        f.write(f"Database dump generated at {datetime.now()}\n\n")

        for (table,) in tables:
            f.write(f"\n========== {table.upper()} ==========\n")

            cur.execute(f"SELECT * FROM {table}")
            rows = cur.fetchall()

            headers = [d[0] for d in cur.description]

            if rows:
                f.write(format_table(headers, rows))
            else:
                f.write("(empty)")

            f.write("\n")

    conn.close()
    print("Readable dump written ->", DUMP_TXT_FILE)


# =========================================================
# MAIN
# =========================================================

def main():
    parser = argparse.ArgumentParser(description="SQLite DB Tool")

    parser.add_argument("-i", "--init", action="store_true", help="Initialize base database")
    parser.add_argument("-mg", "--migrate", action="store_true", help="Apply migrations")
    parser.add_argument("-sc", "--schema", action="store_true", help="Dump schema only")
    parser.add_argument("-td", "--tabledata", action="store_true", help="Dump readable table data")
    parser.add_argument("-d", "--dump", action="store_true", help="Full SQL dump")
    parser.add_argument("-a", "--all", action="store_true", help="Run migrate + schema + dump + data")

    args = parser.parse_args()

    if args.init:
        init_database()

    if args.migrate:
        sqls = get_sql_files()
        apply_migrations(sqls)

    if args.schema:
        dump_schema()

    if args.dump:
        dump_sql()

    if args.tabledata:
        dump_readable()

    if args.all:
        sqls = get_sql_files()
        apply_migrations(sqls)
        dump_schema()
        dump_sql()
        dump_readable()


if __name__ == "__main__":
    main()
