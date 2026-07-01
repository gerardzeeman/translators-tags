# Security Audit – /login/ pagina
**Datum:** 2026-07-01  
**Branch:** `security/login-audit`  
**Scope:** Login-flow, authenticatie-configuratie, sessie-management, aangrenzende auth-endpoints  
**Stack:** Symfony 7 / FrankenPHP / Caddy / PostgreSQL

---

## Samenvatting

| Ernst     | Aantal |
|-----------|--------|
| CRITICAL  | 0      |
| HIGH      | 1      |
| MEDIUM    | 3      |
| LOW       | 4      |
| INFO      | 3      |
| **Totaal**| **11** |

De login-implementatie is over het algemeen solide: CSRF-bescherming actief, rate limiting geconfigureerd, Argon2 password hashing, remember-me cookie volledig beveiligd. De meest urgente bevindingen zijn een ontbrekende `.gitignore`-entry voor `.env.dev` (blootstelling van APP_SECRET) en ontbrekende HTTP security headers.

---

## Bevindingen

### [HIGH] SEC-01 — `.env.dev` niet in `.gitignore`, bevat echte APP_SECRET

**Bestand:** `app/.gitignore`, `app/.env.dev`  
**OWASP:** A02:2021 – Cryptographic Failures  
**CWE:** CWE-312 Cleartext Storage of Sensitive Information  

**Beschrijving:**  
`app/.env.dev` bevat een echte `APP_SECRET` (`54f88194d7875c76482111d2254e7c51`) maar het bestand is **niet** uitgesloten in `.gitignore`. De gitignore-entries `/.env.local` en `/.env.*.local` dekken dit bestand niet. Eén `git add .` volstaat om het secret in de repository-geschiedenis te schrijven.

De APP_SECRET wordt gebruikt voor:
- CSRF-token generatie (formulierhijacking indien uitgelekt)
- Cookie signing (sessie-impersonatie)
- Remember-me token signing

**Bewijs:**
```
app/.gitignore regels:
  /.env.local       ← dekt .env.dev NIET
  /.env.*.local     ← dekt .env.dev NIET
  (/.env.dev ontbreekt)
```

**Oplossing:**
1. Voeg `/.env.dev` toe aan `app/.gitignore`
2. Roteer de huidige APP_SECRET (zie fix hieronder)
3. Overweeg `/.env.*` toe te voegen om alle omgevingsoverschrijvingen te dekken

---

### [MEDIUM] SEC-02 — Ontbrekende Content-Security-Policy (CSP) header

**Bestand:** `app/Caddyfile`  
**OWASP:** A05:2021 – Security Misconfiguration  
**CWE:** CWE-1021 Improper Restriction of Rendered UI Layers  

**Beschrijving:**  
Er is geen `Content-Security-Policy` header geconfigureerd. De loginpagina laadt externe resources (Google Fonts) zonder beperking van toegestane bronnen. Zonder CSP is de pagina kwetsbaar voor XSS-aanvallen als er ooit een template-injection of andere injectievector gevonden wordt.

**Huidig:** Alleen `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.

**Oplossing:**
```caddy
header Content-Security-Policy "default-src 'self'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'"
```

---

### [MEDIUM] SEC-03 — HSTS (Strict-Transport-Security) ontbreekt

**Bestand:** `app/Caddyfile`  
**OWASP:** A05:2021 – Security Misconfiguration  
**CWE:** CWE-523 Unprotected Transport of Credentials  

**Beschrijving:**  
Er is geen `Strict-Transport-Security` header. Zonder HSTS kan een aanvaller bij een MITM-aanval de verbinding downgraden naar HTTP, waarna inloggegevens en sessie-cookies zichtbaar worden.

Note: FrankenPHP/Caddy beheert TLS automatisch. HSTS moet desondanks expliciet als header worden geconfigureerd zodat browsers toekomstige HTTP-verbindingen weigeren.

**Oplossing:**
```caddy
header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
```

---

### [MEDIUM] SEC-04 — Password-reset bundle geïnstalleerd maar niet geconfigureerd

**Bestand:** `app/config/packages/reset_password.yaml`  
**OWASP:** A07:2021 – Identification and Authentication Failures  

**Beschrijving:**  
`symfonycasts/reset-password-bundle` is geïnstalleerd maar geconfigureerd met de placeholder `symfonycasts.reset_password.fake_request_repository`. Er is geen `ResetPasswordRequestRepository`, geen controller, en geen route voor wachtwoordherstel.

Gebruikers die hun wachtwoord vergeten zijn volledig afhankelijk van een beheerder. Dit is een functionele security-gap: vergrendelde of vergeten accounts bieden aanvallers geen aanvalsvector (goed), maar het verhoogt de kans op zwakke wachtwoorden ("ik gebruik iets simpels, anders raak ik het kwijt").

**Oplossing:** Implementeer het wachtwoordherstelsysteem volledig, of verwijder de ongebruikte bundle.

---

### [LOW] SEC-05 — Geen Permissions-Policy header

**Bestand:** `app/Caddyfile`  
**OWASP:** A05:2021 – Security Misconfiguration  

**Beschrijving:**  
Geen `Permissions-Policy` header. Dit laat browser-API's zoals camera, microfoon en geolocation onbeperkt beschikbaar voor de pagina (en geïnjecteerde scripts).

**Oplossing:**
```caddy
header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
```

---

### [LOW] SEC-06 — Minimale wachtwoordsterkte (alleen lengte)

**Bestand:** `app/src/Controller/AdminUserController.php:61`, `app/src/Controller/SecurityController.php:74`  
**OWASP:** A07:2021 – Identification and Authentication Failures  
**NIST SP 800-63B**  

**Beschrijving:**  
Wachtwoorden worden alleen gevalideerd op minimale lengte (8 tekens). Er is geen controle op:
- Bekende/veelgebruikte wachtwoorden (b.v. "password1", "12345678")
- Herhaling van het huidige wachtwoord bij wijziging
- Maximale lengte (DoS-bescherming bij bcrypt/Argon2 voor extreem lange inputs — Symfony vangt dit deels op)

**Oplossing:** Voeg minimale complexiteitseis toe of gebruik een `PasswordStrengthValidator`.

---

### [LOW] SEC-07 — displayName zonder maximale lengtevalidatie in controller

**Bestand:** `app/src/Controller/SecurityController.php:55-59`  
**CWE:** CWE-20 Improper Input Validation  

**Beschrijving:**  
In de profiel-controller wordt `display_name` alleen gevalideerd op `strlen($name) < 2`. Er is geen maximum. Hoewel de database-kolom `length: 100` heeft en Doctrine dit afdwingt als een exception, is het beter om dit in de applicatielaag te valideren met een foutmelding in plaats van een onverwachte 500-fout.

**Oplossing:**
```php
if (strlen($name) < 2 || strlen($name) > 100) {
    $error = 'Naam moet tussen 2 en 100 tekens zijn.';
}
```

---

### [LOW] SEC-08 — Geen `Permissions-Policy` op inlogpagina + externe font-dependency

**Bestand:** `app/templates/security/login.html.twig:9-10`  
**OWASP:** A08:2021 – Software and Data Integrity Failures  

**Beschrijving:**  
De loginpagina laadt Google Fonts via een extern CDN (`fonts.googleapis.com`). Google (of een aanvaller die het DNS-record comprometteert) kan hiermee de laadtijd meten en metadata verzamelen over loginpogingen. Bovendien voegt dit een externe dependency toe aan een kritische pagina.

**Oplossing:** Self-host de font-bestanden via `app/public/fonts/` en verwijder de externe CDN-aanroepen.

---

### [INFO] SEC-09 — APP_ENV=dev in `.env` (template)

**Bestand:** `app/.env`  
**Ernst:** Informatief  

**Beschrijving:**  
Het `.env` template-bestand heeft `APP_ENV=dev`. Bij productie-deployments moet dit worden overschreven via `.env.local` of omgevingsvariabelen. Als een deploy-pipeline vergeet dit te overschrijven, staat Symfony in debug-mode.

**Aanbeveling:** Verander het template naar `APP_ENV=prod` en overschrijf lokaal met `APP_ENV=dev` in `.env.local`.

---

### [INFO] SEC-10 — Remember-me lifetime 7 dagen zonder sessie-invalidatie bij wachtwoordwijziging

**Bestand:** `app/config/packages/security.yaml:35`  
**Ernst:** Informatief  

**Beschrijving:**  
De remember-me cookie blijft 7 dagen geldig. Als een gebruiker zijn wachtwoord wijzigt (via `/profile`), worden de bestaande remember-me cookies van andere sessies **niet automatisch geïnvalideerd** door de huidige implementatie. Symfony heeft hiervoor een `TokenProvider`-mechanisme nodig.

**Aanbeveling:** Implementeer `RememberMeTokenProvider` met database-backed tokens, en invalideer bestaande tokens bij wachtwoordwijziging.

---

### [INFO] SEC-11 — Geen audit logging van loginpogingen

**Bestand:** `app/src/Event/LoginSubscriber.php`  
**Ernst:** Informatief  
**OWASP:** A09:2021 – Security Logging and Monitoring Failures  

**Beschrijving:**  
De `LoginSubscriber` logt alleen het tijdstip van succesvolle logins. Er is geen logging van:
- Mislukte loginpogingen (IP, tijdstip, e-mailadres)
- Rate limit triggers
- Wachtwoordwijzigingen
- Beheerdersacties

**Aanbeveling:** Voeg een `AuthenticationFailureHandlerInterface` toe en log naar een gestructureerde log (Monolog met `security` channel).

---

## Sterke punten (Good Security Practices)

| Punt | Detail |
|------|--------|
| ✅ CSRF bescherming | `enable_csrf: true` op form_login + token in template |
| ✅ Rate limiting | 5 pogingen per 15 minuten via `login_throttling` |
| ✅ Argon2 hashing | `algorithm: auto` kiest bcrypt/Argon2ID |
| ✅ Remember-me cookie | `httponly`, `secure`, `samesite: strict` |
| ✅ Open redirect beschermd | Redirect na login naar vaste route (`app_home`) |
| ✅ Already-logged-in redirect | Controller check vóór render |
| ✅ Role hierarchy | Correct geconfigureerd met expliciete inheritance |
| ✅ Access control | Expliciete whitelist van routes met minimale rechten |
| ✅ CSRF op profiel | Beide actions (`update_name`, `change_password`) valideren token |
| ✅ Role injection geblokkeerd | `array_intersect` in AdminUserController filtert onbekende roles |
| ✅ Self-delete beschermd | AdminUserController voorkomt dat admin zichzelf verwijdert |
| ✅ Password upgrade | `PasswordUpgraderInterface` in UserRepository |

---

## Fix-prioriteiten

| Prioriteit | ID | Actie |
|------------|----|-------|
| 1 (nu) | SEC-01 | `/.env.dev` toevoegen aan `.gitignore`, APP_SECRET roteren |
| 2 (nu) | SEC-02 | CSP header toevoegen aan Caddyfile |
| 3 (nu) | SEC-03 | HSTS header toevoegen aan Caddyfile |
| 4 (sprint) | SEC-04 | Password reset implementeren of bundle verwijderen |
| 5 (sprint) | SEC-05 | Permissions-Policy header toevoegen |
| 6 (backlog) | SEC-06 | Wachtwoordsterkte-validatie uitbreiden |
| 7 (backlog) | SEC-07 | displayName max-lengte valideren in controller |
| 8 (backlog) | SEC-08 | Google Fonts self-hosten |
| 9 (backlog) | SEC-09 | `APP_ENV=prod` als standaard in `.env` template |
| 10 (backlog) | SEC-10 | Remember-me tokens database-backed + invalidatie bij pw-wijziging |
| 11 (backlog) | SEC-11 | Security audit logging toevoegen |
