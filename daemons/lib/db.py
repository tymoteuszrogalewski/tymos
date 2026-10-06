"""
Wspolny modul DB + log dla demonow TymOS (tymos.py, bridge_*.py).

Zawiera:
  - db_connect()  — otwiera polaczenie pymysql w thread-local (osobne per watek)
  - db_ping()     — ping polaczenia, reconnect gdy padlo
  - db_exec(sql, params) — wykonuje query z 3x retry i automatycznym reconnect
  - db_log(level, source, message) — print + INSERT do tabeli `log`

Uzycie:
    from lib.db import db_connect, db_ping, db_exec, db_log

Kazdy daemon ma WLASNE _db_local (thread-local) — polaczenia nie sa wspoldzielone
miedzy procesami, tylko miedzy watkami tego samego procesu.
"""

import sys
import time
import threading
import pymysql
from config import db_host, db_port, db_user, db_pass, db_name

DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME = db_host(), db_port(), db_user(), db_pass(), db_name()

# Thread-local: kazdy watek ma wlasne polaczenie DB (pymysql nie jest thread-safe).
_db_local = threading.local()


def db_connect():
    """Otwiera nowe polaczenie pymysql w biezacym watku. Zamyka stare jesli bylo."""
    try:
        old = getattr(_db_local, 'db', None)
        if old:
            old.close()
    except Exception:
        pass
    _db_local.db = pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS,
        database=DB_NAME, autocommit=True, connect_timeout=10,
        read_timeout=10, write_timeout=10,
    )
    print("Polaczono z MariaDB (watek: %s)" % threading.current_thread().name)


def db_ping():
    """Sprawdza czy polaczenie zyje, reconnectuje gdy padlo."""
    try:
        conn = getattr(_db_local, 'db', None)
        if conn:
            conn.ping(reconnect=False)
        else:
            db_connect()
    except Exception:
        db_connect()


def db_exec(sql, params=None):
    """
    Wykonuje query. Dla SELECT zwraca tuple wierszy, dla INSERT/UPDATE zwraca rowcount.
    Gdy padnie — 3x retry z reconnectem. Gdy wszystkie proby padna — zwraca None.
    """
    for attempt in range(3):
        try:
            conn = getattr(_db_local, 'db', None)
            if not conn:
                db_connect()
                conn = _db_local.db
            if attempt == 0:
                conn.ping(reconnect=False)
            cur = conn.cursor()
            try:
                cur.execute(sql, params)
                if cur.description:
                    result = cur.fetchall()
                else:
                    result = cur.rowcount
                return result
            finally:
                cur.close()
        except Exception as e:
            if attempt < 2:
                print("DB error (proba %d): %s — reconnect" % (attempt + 1, e), file=sys.stderr)
                try:
                    conn = getattr(_db_local, 'db', None)
                    if conn:
                        conn.close()
                except Exception:
                    pass
                try:
                    db_connect()
                    continue
                except Exception as e2:
                    print("DB reconnect failed: %s" % e2, file=sys.stderr)
                    time.sleep(1)
                    continue
            else:
                print("DB error (proba %d): %s — poddaje sie" % (attempt + 1, e), file=sys.stderr)
                # Zgloszenie do tabeli `log` (widoczne w admin/Logi) — z zabezpieczeniem anti-rekursja
                # (gdy to juz jest INSERT INTO log, nie probuj tam pisac ponownie).
                sql_upper = sql.lstrip().upper()
                if not sql_upper.startswith("INSERT INTO LOG") and not sql_upper.startswith("INSERT INTO `LOG`"):
                    try:
                        conn = getattr(_db_local, 'db', None)
                        if not conn:
                            db_connect()
                            conn = _db_local.db
                        cur = conn.cursor()
                        try:
                            msg = ("SQL: %s | err: %s" % (sql[:200], str(e)))[:500]
                            cur.execute("INSERT INTO log (level, source, message) VALUES (%s, %s, %s)",
                                        ("ERROR", "db_exec", msg))
                        finally:
                            cur.close()
                    except Exception:
                        pass
                return None
    return None


def db_log(level, source, message):
    """
    Loguje do tabeli `log` (widoczne w /admin/ -> Logi w tymosu) + print na stdout (journal).
    Source to zwykle "<daemon_name>:<sekcja>" dla bridge'ow, albo sama sekcja dla core.
    """
    print("[%s] %s: %s" % (level, source, message))
    try:
        db_exec("INSERT INTO log (level, source, message) VALUES (%s, %s, %s)",
                (level, source, str(message)[:500]))
    except Exception:
        pass
