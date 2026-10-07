#!/usr/bin/env python3
"""App-genaue Sicherheitsprüfung der lokalen gastrobook-Instanz (Black-Box HTTP).

Deckt ab, was ein generischer Scanner übersieht – autorisierungs- und
mandantenbezogene Logik:
  1. Anmeldezwang        – /admin und /api/v1 ohne Sitzung
  2. Mandantentrennung   – A-Sitzung gegen B-IDs (Web), lesend und schreibend
  3. API-Mandantentrennung – A-Token gegen B-IDs, ohne Token
  4. Rollen              – Staff darf keine Admin-Aktionen
  5. Gast-Token          – Verwaltungslink mit falschem/fremdem Token
  6. CSRF                – schreibende Anfrage ohne Token
  7. Kopfzeilen          – Sicherheits-Header
  8. Gespeichertes XSS   – Nutzlast erscheint escaped
  9. Login-Drossel       – wiederholte Fehlversuche

Eingaben über Umgebung:
  GB_BASE    Basis-URL (Vorgabe http://127.0.0.1:8088)
  GB_IDS     Pfad zur SEEDJSON-Datei aus seed-pentest.php
  GB_ROUTES  Pfad zu `php artisan route:list --json` (für 1 und 2)

Exit 0, wenn kein BEFUND. Nur gegen die eigene lokale Wegwerf-Instanz.
"""
import json
import os
import re
import sys

import requests

BASE = os.environ.get("GB_BASE", "http://127.0.0.1:8088")
ids = json.load(open(os.environ["GB_IDS"], encoding="utf-8"))
routes = json.load(open(os.environ["GB_ROUTES"], encoding="utf-8"))
A, B, PW, XSS, TOKEN = ids["A"], ids["B"], ids["pw"], ids["xss"], ids["token"]

PARAM = {"reservation": "reservation", "guest": "guest", "table": "table",
         "room": "room", "location": "location", "tag": "tag", "endpoint": "endpoint"}
SKIP = re.compile(r"(logout|abmelden|/export|/import|/send|attachments|/merge|/anonymize|rotate-secret|/ping)")
# Bewusst öffentlich (kein Login nötig) – aus dem Anmeldezwang ausgenommen.
PUBLIC = {"api/v1/openapi.yaml"}

befunde = []
def ok(m): print(f"  OK       {m}")
def befund(m): befunde.append(m); print(f"  BEFUND   {m}")
def hinweis(m): print(f"  hinweis  {m}")

def csrf(session, url):
    m = re.search(r'name="_token"\s+value="([^"]+)"', session.get(url).text)
    return m.group(1) if m else None

def login(email):
    s = requests.Session()
    t = csrf(s, BASE + "/login")
    s.post(BASE + "/login", data={"_token": t, "email": email, "password": PW}, allow_redirects=False)
    return s if s.get(BASE + "/admin", allow_redirects=False).status_code == 200 else None

def fill(uri, src):
    ps = re.findall(r"\{(\w+)\??\}", uri)
    if not ps:
        return None
    p = uri
    for name in ps:
        if name not in PARAM or src.get(PARAM[name]) is None:
            return None
        p = re.sub(r"\{" + name + r"\??\}", str(src[PARAM[name]]), p)
    return "/" + p


print("1) Anmeldezwang (ohne Sitzung)")
anon = requests.Session()
n = blocked = 0
for r in routes:
    uri = r["uri"]
    if not (uri.startswith("admin/") or uri.startswith("api/v1/")) or SKIP.search(uri) or uri in PUBLIC:
        continue
    p = fill(uri, A) or ("/" + uri if "{" not in uri else None)
    if p is None:
        continue
    for m in [x for x in r["method"].split("|") if x != "HEAD"]:
        rc = anon.request("GET" if m == "GET" else "POST", BASE + p,
                          data=None if m == "GET" else {"_method": m}, allow_redirects=False).status_code
        n += 1
        if rc in (301, 302, 303, 401, 403, 419) or (uri.startswith("api/v1/") and rc == 404):
            blocked += 1
        else:
            befund(f"anonym {m:6} /{uri} -> {rc}")
if blocked == n:
    ok(f"alle {n} Admin-/API-Aufrufe ohne Sitzung abgewiesen")

print("\n2) Mandantentrennung Web (A-Sitzung gegen B-IDs, lesend und schreibend)")
sa = login(A["admin"])
if sa is None:
    befund("Login als Admin A fehlgeschlagen")
else:
    token = csrf(sa, BASE + "/admin")
    n = leaks = 0
    for r in routes:
        uri = r["uri"]
        if not uri.startswith("admin/") or SKIP.search(uri):
            continue
        pf = fill(uri, B)
        if pf is None:
            continue
        for m in [x for x in r["method"].split("|") if x != "HEAD"]:
            rc = sa.request("GET" if m == "GET" else "POST", BASE + pf,
                            data=None if m == "GET" else {"_token": token, "_method": m}, allow_redirects=False).status_code
            n += 1
            if rc not in (403, 404):
                leaks += 1
                befund(f"fremd {m:6} {pf} -> {rc}")
    if leaks == 0:
        ok(f"{n} fremde Zugriffe alle 403/404")

print("\n3) API-Mandantentrennung (A-Token gegen B-IDs)")
h = {"Authorization": "Bearer " + TOKEN, "Accept": "application/json"}
api = BASE + "/api/v1"
if requests.get(f"{api}/guests/{A['guest']}", headers={"Accept": "application/json"}).status_code != 401:
    befund("API ohne Token nicht 401")
else:
    ok("ohne Token -> 401")
for label, url, exp in [("eigener Gast", f"{api}/guests/{A['guest']}", (200,)),
                        ("fremder Gast", f"{api}/guests/{B['guest']}", (403, 404)),
                        ("fremde Reservierung", f"{api}/reservations/{B['code']}", (403, 404)),
                        ("fremder Webhook DELETE", f"{api}/webhooks/{B['endpoint']}", (403, 404))]:
    meth = "DELETE" if "DELETE" in label else "GET"
    rc = requests.request(meth, url, headers=h).status_code
    (ok if rc in exp else befund)(f"{label} -> {rc} (erwartet {exp})")

print("\n4) Rollen (Staff darf keine Admin-Aktionen)")
ss = login(A["staff"])
if ss is None:
    hinweis("Staff-Login nicht möglich – übersprungen")
else:
    st = csrf(ss, BASE + "/admin") or ""
    for m, p in [("GET", "/admin/users"), ("GET", "/admin/webhooks"), ("GET", "/admin/billing"),
                 ("PUT", "/admin/settings/general"), ("PUT", "/admin/settings/stripe")]:
        rc = ss.request("GET" if m == "GET" else "POST", BASE + p,
                        data=None if m == "GET" else {"_token": st, "_method": m}, allow_redirects=False).status_code
        (ok if rc in (403, 419) else befund)(f"Staff {m:4} {p} -> {rc}")

print("\n5) Gast-Token (Verwaltungslink)")
good = requests.get(f"{BASE}/reservation/{A['code']}/manage/{A['manage_token']}", allow_redirects=False).status_code
bad = requests.get(f"{BASE}/reservation/{A['code']}/manage/falschertoken123", allow_redirects=False).status_code
foreign = requests.get(f"{BASE}/reservation/{A['code']}/manage/{B['manage_token']}", allow_redirects=False).status_code
(ok if good == 200 else befund)(f"richtiger Token -> {good}")
(ok if bad in (403, 404) else befund)(f"falscher Token -> {bad}")
(ok if foreign in (403, 404) else befund)(f"fremder Token -> {foreign}")

print("\n6) CSRF (schreibende Anfrage ohne Token)")
if sa:
    rc = sa.post(BASE + "/admin/settings/guest-mail", data={"_method": "PUT", "mail_from_name": "X"}, allow_redirects=False).status_code
    (ok if rc == 419 else befund)(f"PUT ohne _token -> {rc} (erwartet 419)")

print("\n7) Sicherheits-Kopfzeilen (/login)")
hd = requests.get(BASE + "/login").headers
for name, muster in [("X-Frame-Options", "DENY"), ("X-Content-Type-Options", "nosniff"),
                     ("Content-Security-Policy", "frame-ancestors")]:
    v = hd.get(name, "")
    (ok if muster.lower() in v.lower() else hinweis)(f"{name}: {v or 'fehlt'}")

print("\n8) Gespeichertes XSS (escaped in der Verwaltung)")
if sa:
    body = sa.get(f"{BASE}/admin/reservations/{A['reservation']}" if A.get("reservation") else f"{BASE}/admin/guests/{A['guest']}").text
    if XSS in body:
        befund(f"Nutzlast roh im HTML: {XSS}")
    else:
        ok("Nutzlast nicht roh enthalten (escaped)")

print("\n9) Login-Drossel")
# Wegwerf-Adresse, damit der Test das echte Admin-Konto nicht sperrt (sonst
# scheitern Logins in einem direkt folgenden Lauf an der Drossel).
sd = requests.Session()
codes = []
for _ in range(13):
    t = csrf(sd, BASE + "/login")
    codes.append(sd.post(BASE + "/login", data={"_token": t, "email": "drossel-probe@gastrobook.test", "password": "falsch"}, allow_redirects=False).status_code)
(ok if 429 in codes else befund)(f"Drossel {'greift' if 429 in codes else 'fehlt'} (Codes {codes})")

print(f"\nERGEBNIS: {'kein BEFUND' if not befunde else str(len(befunde)) + ' BEFUND(e)'}")
sys.exit(1 if befunde else 0)
