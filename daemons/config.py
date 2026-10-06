"""TymOS — config loader. Parsuje define() z config.inc.php."""

import re

_CONFIG = {}
_LOADED = False

def _load():
    global _CONFIG, _LOADED
    if _LOADED:
        return
    with open('/opt/tymos/tymos/config.inc.php', 'r') as f:
        for line in f:
            m = re.match(r"define\(\s*'(\w+)'\s*,\s*(.+?)\s*\);", line)
            if m:
                key = m.group(1)
                val = m.group(2).strip().strip("'\"")
                if val.isdigit():
                    val = int(val)
                _CONFIG[key] = val
    _LOADED = True

def get(key, default=None):
    _load()
    return _CONFIG.get(key, default)

# DB shortcuts
def db_host(): return get('DB_HOST', 'localhost')
def db_port(): return get('DB_PORT', 3306)
def db_user(): return get('DB_USER', 'tymos')
def db_pass(): return get('DB_PASS', '')
def db_name(): return get('DB_TYM', 'tymos')
