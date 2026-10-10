@if ($du)
Hallo,

für dein Swayy-Konto wurde ein neues Passwort angefordert. Über den Knopf legst du es fest.

Neues Passwort festlegen: {!! $url !!}

---

Der Link gilt {!! $dauer !!}. Hast du kein neues Passwort angefordert, bleibt dein Konto, wie es ist, und du kannst diese Mail löschen.
@else
Guten Tag,

für Ihr Swayy-Konto wurde ein neues Passwort angefordert. Über den Knopf legen Sie es fest.

Neues Passwort festlegen: {!! $url !!}

---

Der Link gilt {!! $dauer !!}. Haben Sie kein neues Passwort angefordert, bleibt Ihr Konto, wie es ist, und Sie können diese Mail löschen.
@endif
