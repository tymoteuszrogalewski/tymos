#!/usr/bin/env python3
# Skan sieci Zigbee z Z2M + raport + drzewo mocy.
#
#   ./zigbee_scan.py                 - nowy skan przez ssh TYMOS_SSH (config.inc.php) (~5 min) + raport
#   ./zigbee_scan.py scans/xxx.json  - raport z gotowego pliku (bez skanu)
#   ./zigbee_scan.py --last          - raport z ostatniego zapisanego skanu
#
# Skan pyta Z2M o networkmap type=raw routes=true. Uwaga: Sonoff S60ZBTPF,
# Tuya TS0004 i ti.router NIE oddaja tablicy routingu (limit firmware) -
# od nich mamy tylko tablice sasiadow (LQI).

import json, os, subprocess, sys, time
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
import re
HOST = re.search(r"define\('TYMOS_SSH',\s*'([^']*)'", open(os.path.join(HERE, "../tymos/config.inc.php")).read()).group(1)
SCANS = os.path.join(HERE, "scans")
SILNY = 100          # prog "silnego" linku
SLABY = 60           # prog "slabego" linku
# Urzadzenia swiadomie odlozone (leza w kartonie, w TymOS soft-deleted) -
# nadal sparowane w Z2M, wiec siedza w mapie jako duchy. Nie zglaszamy ich jako problem.
IGNORUJ = ["WOLNY245", "WOLNY404"]

# --- nowy skan przez ssh ---------------------------------------------------

def skanuj():
    remote = "/tmp/tymos/nwkmap.json"
    print("Zamawiam networkmap (raw + routes). To trwa 3-8 min...", flush=True)
    sub = (
        'mkdir -p /tmp/tymos; rm -f %s; '
        'nohup bash -c "mosquitto_sub -t zigbee2mqtt/bridge/response/networkmap '
        '-C 1 -W 900 > %s" >/dev/null 2>&1 & sleep 2; '
        "mosquitto_pub -t zigbee2mqtt/bridge/request/networkmap "
        "-m '{\"type\":\"raw\",\"routes\":true}'" % (remote, remote)
    )
    subprocess.run(["ssh", HOST, sub], check=True)
    czekaj = 'for i in $(seq 1 120); do [ -s %s ] && exit 0; sleep 5; done; exit 1' % remote
    t0 = time.time()
    if subprocess.run(["ssh", HOST, czekaj]).returncode != 0:
        sys.exit("BRAK ODPOWIEDZI z Z2M (timeout 10 min)")
    print("Odpowiedz po %.0f s" % (time.time() - t0))
    os.makedirs(SCANS, exist_ok=True)
    plik = os.path.join(SCANS, time.strftime("nwkmap-%Y%m%d-%H%M.json"))
    subprocess.run(["scp", "-q", "%s:%s" % (HOST, remote), plik], check=True)
    print("Zapisane: %s" % plik)
    return plik

# --- wczytanie ------------------------------------------------------------

def wczytaj(plik):
    d = json.load(open(plik))
    nm = d.get("data", d).get("value", d)
    nodes = {n["networkAddress"]: n for n in nm["nodes"]}
    return nodes, nm["links"]

# --- graf -----------------------------------------------------------------

def zbuduj(nodes, links):
    # adj: tylko routery + koordynator, max LQI z obu kierunkow, zerowe pomijamy
    adj = defaultdict(dict)
    for l in links:
        s, t, q = l["source"]["networkAddress"], l["target"]["networkAddress"], l["lqi"]
        if q <= 0:
            continue
        if nodes.get(s, {}).get("type") == "EndDevice" or nodes.get(t, {}).get("type") == "EndDevice":
            continue
        adj[s][t] = max(adj[s].get(t, 0), q)
        adj[t][s] = max(adj[t].get(s, 0), q)
    # rodzice end-device'ow: wpis relationship=1 (sasiad jest dzieckiem)
    rodzic = {}
    for l in links:
        s, t = l["source"]["networkAddress"], l["target"]["networkAddress"]
        if nodes.get(s, {}).get("type") == "EndDevice" and l["relationship"] == 1:
            if s not in rodzic or l["lqi"] > rodzic[s][1]:
                rodzic[s] = (t, l["lqi"])
    return adj, rodzic

def drzewo_szerokie(adj):
    # spanning tree maksymalizujace najslabsze ogniwo na sciezce (widest path)
    dist = {0: 255}
    par = {0: None}
    got = set()
    while True:
        u = max((n for n in dist if n not in got), key=lambda n: dist[n], default=None)
        if u is None:
            break
        got.add(u)
        for v, q in adj[u].items():
            b = min(dist[u], q)
            if b > dist.get(v, 0):
                dist[v] = b
                par[v] = (u, q)
    return dist, par

# --- rysowanie ------------------------------------------------------------

def rysuj(nodes, adj, rodzic, dist, par):
    def nazwa(a):
        return nodes.get(a, {}).get("friendlyName", "?%s" % a)

    dzieci = defaultdict(list)
    for v, p in par.items():
        if p:
            dzieci[p[0]].append((v, p[1]))
    for a, (p, q) in rodzic.items():
        dzieci[p].append((a, q))

    def opis(a, q):
        n = nodes.get(a, {})
        typ = {"Router": "R", "EndDevice": "E", "Coordinator": "C"}.get(n.get("type"), "?")
        silne = sum(1 for x in adj[a].values() if x >= SILNY) if typ == "R" else 0
        alt = "  alt %d" % silne if typ == "R" else ""
        bot = "  waskie gardlo %d" % dist[a] if typ == "R" and dist.get(a, 0) < SILNY else ""
        fail = "  [%s]" % ",".join(n["failed"]) if n.get("failed") else ""
        flag = " <<< SLABO" if q < SLABY else ""
        return "[%3d] %s (%s)%s%s%s%s" % (q, nazwa(a), typ, alt, bot, fail, flag)

    print("Coordinator (dongle)")
    def gal(a, pre):
        lista = sorted(dzieci.get(a, []), key=lambda x: -x[1])
        for i, (v, q) in enumerate(lista):
            ost = i == len(lista) - 1
            print(pre + ("`-- " if ost else "|-- ") + opis(v, q))
            gal(v, pre + ("    " if ost else "|   "))
    gal(0, "")

# --- raport ---------------------------------------------------------------

def raport(plik):
    nodes, links = wczytaj(plik)
    adj, rodzic = zbuduj(nodes, links)
    dist, par = drzewo_szerokie(adj)
    def nazwa(a):
        return nodes.get(a, {}).get("friendlyName", "?%s" % a)

    r = [a for a, n in nodes.items() if n["type"] == "Router"]
    e = [a for a, n in nodes.items() if n["type"] == "EndDevice"]
    st = defaultdict(int)
    for l in links:
        for x in l.get("routes", []):
            st[x["status"]] += 1
    print("=" * 78)
    print("SKAN: %s" % os.path.basename(plik))
    print("Routery: %d   End-device: %d   linki: %d   trasy: %s" % (len(r), len(e), len(links), dict(st)))
    print("=" * 78)
    print()
    print("--- DRZEWO (LQI do rodzica; alt = ilu silnych sasiadow >=%d) ---" % SILNY)
    rysuj(nodes, adj, rodzic, dist, par)
    print()

    print("--- CO SLYSZY DONGLE BEZPOSREDNIO ---")
    co = sorted([(l["lqi"], nazwa(l["source"]["networkAddress"]))
                 for l in links if l["target"]["networkAddress"] == 0], reverse=True)
    for q, n in co:
        print("  %3d  %s" % (q, n))
    print("  razem %d" % len(co))
    print()

    print("--- PROBLEMY ---")
    poza = [a for a in r if a not in dist and nazwa(a) not in IGNORUJ]
    odlozone = [a for a in r if a not in dist and nazwa(a) in IGNORUJ]
    if odlozone:
        print("  (odlozone w kartonie, pomijam: %s)" % ", ".join(sorted(nazwa(a) for a in odlozone)))
    if poza:
        print("  POZA SIECIA (zero zywych linkow): %s" % ", ".join(sorted(nazwa(a) for a in poza)))
    for a in r:
        if nodes[a].get("failed") and "lqi" in nodes[a]["failed"] and nazwa(a) not in IGNORUJ:
            ls = nodes[a].get("lastSeen")
            wiek = "%.0f min" % ((time.time() * 1000 - ls) / 60000) if ls else "?"
            print("  NIE ODPOWIADA na lqi: %s (last seen %s)" % (nazwa(a), wiek))
    for a in sorted(r, key=lambda x: dist.get(x, 0)):
        if a in dist and dist[a] < SILNY:
            p = par[a]
            print("  waskie gardlo %3d: %s (najlepsza sciezka przez %s, link %d)"
                  % (dist[a], nazwa(a), nazwa(p[0]), p[1]))
    for a, (p, q) in sorted(rodzic.items(), key=lambda x: x[1][1]):
        if q < SILNY:
            print("  slaby rodzic %3d: %s <- %s" % (q, nazwa(a), nazwa(p)))
    brak = [a for a in e if a not in rodzic]
    if brak:
        print("  END-DEVICE BEZ RODZICA: %s" % ", ".join(nazwa(a) for a in brak))
    print()

# --- main -----------------------------------------------------------------

if __name__ == "__main__":
    arg = sys.argv[1] if len(sys.argv) > 1 else None
    if arg == "--last":
        p = sorted(os.path.join(SCANS, f) for f in os.listdir(SCANS) if f.endswith(".json"))
        if not p:
            sys.exit("brak zapisanych skanow w %s" % SCANS)
        raport(p[-1])
    elif arg:
        raport(arg)
    else:
        raport(skanuj())
