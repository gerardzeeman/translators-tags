# Security Audit

> Datum: 2026-06-29 · Scope: volledige codebase · Auditor: Claude Sonnet 4.6

## Samenvatting

| Ernst    | Aantal |
|----------|--------|
| Kritiek  | 2      |
| Hoog     | 4      |
| Medium   | 5      |
| Laag     | 4      |
| Info     | 3      |
| **Totaal** | **18** |

---

## Bevindingen

---

### KRITIEK — SQL-injectie via `implode` in `translationWordsBelongToTranslation`

**Locatie:** `app/src/Repository/LinkingRepository.php:682`

**Beschrijving:**
De methode `translationWordsBelongToTranslation()` bouwt een raw SQL-query via `implode` op een niet-gesanitiseerde array van integers:

```php
$list  = implode(',', array_map('intval', $twIds));
$count = (int) $this->connection->fetchOne(
    "SELECT COUNT(DISTINCT tw.id)
     FROM translation_words tw
     JOIN translation_verses tv ON tv.id = tw.verse_id
     WHERE tw.id IN ({$list})
       AND tv.translation_id = :translation_id",
    ['translation_id' => $translationId]
);
```

De aanroeper (o.a. `LinkingController::save()`) geeft `$twIds` direct door vanuit de POST-body, na slechts een `is_array` check. Hoewel `array_map('intval', ...)` integer-coercering uitvoert, is het een patroon dat gevoelig is voor vervuiling (bijv. objecten, heredoc-strings in oudere PHP-versies) en creëert precedent voor onveilig gebruik elders. Het array kan bovendien onbeperkt groot zijn (zie DoS hieronder).

**Risico:** Potentieel beperkte SQL-injectie bij type-jiggling edge cases, maar primair: dit patroon wordt op meerdere plaatsen hergebruikt (ook in `saveManualLinks`, regels 603, 619, 629) waar kolom-namen (`{$idCol}`) vanuit logica worden geïnterpoleerd.

**Aanbeveling:** Gebruik `ArrayParameterType::INTEGER` via Doctrine DBAL voor array-parameters, zoals elders al correct gebeurt in de codebase. Vervang de `implode`-aanpak door:

```php
$count = (int) $this->connection->fetchOne(
    'SELECT COUNT(DISTINCT tw.id)
     FROM translation_words tw
     JOIN translation_verses tv ON tv.id = tw.verse_id
     WHERE tw.id IN (:ids)
       AND tv.translation_id = :translation_id',
    ['ids' => array_map('intval', $twIds), 'translation_id' => $translationId],
    ['ids' => ArrayParameterType::INTEGER]
);
```

---

### KRITIEK — Productiegereed secrets staan in `app/.env` (niet in `.gitignore`)

**Locatie:** `app/.env:1-7`

**Beschrijving:**
Het bestand `app/.env` bevat echte productie-secrets:

```
APP_SECRET=<echte waarde — niet te committen>
REMEMBER_ME_SECRET=<echte waarde — niet te committen>
DATABASE_URL="postgresql://bible:<wachtwoord>@postgres:5432/bible_compare?..."
```

Het Symfony-standaard `.gitignore` in `app/` bevat regels voor `.env.local` en `.env.*.local`, maar **niet** voor `.env` zelf. Symfony's documentatie verwacht dat `app/.env` alleen standaardwaarden/templates bevat en **nooit** echte secrets. Hoewel het bestand momenteel niet in git staat (gecontroleerd via `git ls-files`), is dit een hoog risico omdat:
1. `app/.env` door Symfony altijd wordt ingeladen in alle omgevingen.
2. Er niets is dat verhindert dat het per ongeluk wordt gecommit.
3. De echte secrets ook in `app/.env.local` staan (dubbel opgeslagen).

**Risico:** Per ongeluk committen van productie-database credentials en secrets leidt tot volledige compromittering van de productie-omgeving.

**Aanbeveling:**
- Verplaats productieassets naar `app/.env.local` (staat al in `.gitignore`) en maak `app/.env` alleen een template met placeholder-waarden.
- Voeg aan de root-`.gitignore` toe: `app/.env.local` en zorg dat `app/.env` alleen commentaar en `!ChangeMe!` placeholders bevat.
- Overweeg Docker Secrets of een secrets-manager voor gevoelige waarden in productie.

---

### HOOG — Registratie is open voor iedereen (geen uitnodigingssysteem)

**Locatie:** `app/src/Controller/SecurityController.php:39-105` · `app/config/packages/security.yaml:48`

**Beschrijving:**
`/register` is publiek toegankelijk en verleent na registratie standaard `ROLE_USER`. Elke bezoeker kan een account aanmaken. Hoewel er rate limiting op 5 pogingen per 15 minuten zit (per IP), is er geen:
- E-mailverificatie
- Uitnodigingscode
- Admin-goedkeuring

De code in `SecurityController::register()` zet expliciet `$user->setIsVerified(true)` met de TODO-opmerking "add email verification later if needed".

**Risico:** Bots of aanvallers kunnen onbeperkt accounts aanmaken die toegang hebben tot `ROLE_USER`-beschermde content. Bij eventuele rechten-escalatiefouten zou dit bijzonder schadelijk zijn.

**Aanbeveling:**
Implementeer e-mailverificatie (reeds voorzien in de codebase via `symfony/reset-password-bundle`), of schakel publieke registratie uit en gebruik alleen admin-uitnodigingen. Minimaal: zet `setIsVerified(false)` en weiger toegang voor ongewaarborgde gebruikers.

---

### HOOG — `innerHTML` met server-gegenereerde HTML in `word_linker_controller.js`

**Locatie:** `app/assets/controllers/word_linker_controller.js:274`

**Beschrijving:**
De `#refreshVerseBlock()` methode haalt een HTML-fragment op via `fetch()` en plaatst dit via `innerHTML` in de DOM:

```js
const tmp = document.createElement('div')
tmp.innerHTML = html.trim()
const newBlock = tmp.firstElementChild
this.element.replaceWith(newBlock)
```

De bron is `this.refreshUrlValue` — een server-side URL die Twig-rendered HTML teruggeeft. Twig auto-escaped standaard, maar:
1. Als er ooit een `|raw` filter of `{% autoescape false %}` wordt toegevoegd aan het partial template, introduceert dit direct XSS.
2. Het patroon zelf (`innerHTML` met server-data) is inherent riskant voor toekomstige ontwikkelaars.

**Risico:** Stored XSS indien server-side data ooit ongeescaped wordt gerenderd, bijv. via bijbeltekst of Strong's definities die HTML kunnen bevatten.

**Aanbeveling:**
Gebruik `Turbo.renderStreamMessage()` of de Turbo Streams API om HTML-fragmenten veilig te injecteren, of overweeg `DOMParser` met expliciete sanitization. Documenteer het huidige gedrag en voeg een linting-regel toe die `innerHTML` in controllers verbiedt.

---

### HOOG — GitHub Actions deployt als `root` via SSH

**Locatie:** `.github/workflows/deploy.yml:10`

**Beschrijving:**
De deploy-workflow logt in als `root` op de productieserver:

```yaml
username: root
```

Dit betekent dat elke compromittering van de GitHub Actions secrets (`SSH_PRIVATE_KEY`) volledige root-toegang tot de server geeft. Bovendien wordt `git pull` als root uitgevoerd op de productieserver, waardoor code rechtstreeks op de host wordt uitgevoerd.

**Risico:** Bij een gecompromitteerde GitHub Actions token of een kwaadaardig pull request (bij CI/CD misconfiguraties) is de hele server overneembaar.

**Aanbeveling:**
- Maak een aparte `deploy`-gebruiker aan met minimale rechten (sudo only voor `docker compose`).
- Gebruik SSH-sleutels met `restrict` in `authorized_keys` (bijv. `command="docker compose ..."` beperking).
- Overweeg SHA-pinning voor de `appleboy/ssh-action@v1` → `appleboy/ssh-action@<sha>`.

---

### HOOG — Geen `X-Powered-By` / Server-header onderdrukking; FrankenPHP lekt versie-info

**Locatie:** `app/Caddyfile` · `app/Dockerfile`

**Beschrijving:**
Caddy en FrankenPHP voegen standaard `Server: Caddy` en `X-Powered-By: PHP/8.x` headers toe. De `Caddyfile` en de `SecurityHeadersSubscriber` verwijderen deze niet. PHP's `expose_php = On` (standaard) zorgt voor versie-lekkage.

**Risico:** Aanvallers kunnen de exacte PHP-versie en server-stack achterhalen, wat gerichte exploits vergemakkelijkt.

**Aanbeveling:**
Voeg in de `Caddyfile` toe:
```
header -Server
header -X-Powered-By
```
Of stel in `php.ini` / via environment-variabele `PHP_INI_SCAN_DIR` in:
```ini
expose_php = Off
```

---

### MEDIUM — Onbeperkte grootte van `twIds` array leidt tot DoS-risico

**Locatie:** `app/src/Controller/LinkingController.php:145-165` · `app/src/Repository/LinkingRepository.php:682`

**Beschrijving:**
De `save` API-endpoint accepteert een arbitrair grote `tw_ids` array vanuit de POST-body. Er is geen maximumlimiet op het aantal elementen. Dit resulteert in een `IN (:list)` clause in de database met potentieel duizenden of miljoenen integers, wat kan leiden tot:
- Database query-overbelasting
- Hoge geheugenconsumptie in PHP
- Potentieel time-out van databaseverbindingen

**Risico:** Geauthenticeerde gebruikers (zelfs met basisrol `ROLE_LINKER`) kunnen de database overbelasten.

**Aanbeveling:**
Voeg een maximum toe:
```php
if (count($twIds) > 100) {
    return $this->json(['error' => 'Too many IDs.'], 400);
}
```

---

### MEDIUM — `remember_me` cookie heeft geen `httponly`-vlag expliciet ingesteld; sessie-fixatie mogelijk

**Locatie:** `app/config/packages/security.yaml:33-38` · `app/config/packages/framework.yaml:5-10`

**Beschrijving:**
De `remember_me` cookie configuratie stelt `samesite: strict` in maar vermeldt geen expliciete `httponly: true`. Symfony's standaard stelt `httponly` in voor sessie-cookies, maar niet altijd voor `remember_me` cookies afhankelijk van de versie. De framework-sessieconfiguratie gebruikt `cookie_secure: auto` (goed) en `cookie_samesite: strict` (goed), maar `cookie_httponly` wordt niet expliciet ingesteld (standaard is `true` in Symfony, maar het is beter om dit expliciet te bevestigen).

**Aanbeveling:**
Maak de instelling expliciet:
```yaml
remember_me:
  secret: '%env(REMEMBER_ME_SECRET)%'
  lifetime: 604800
  path: /
  samesite: strict
  httponly: true
  secure: true
```

---

### MEDIUM — `APP_SECRET` in root `.env` is een placeholder (`changeme`)

**Locatie:** `.env:9`

**Beschrijving:**
De root `.env` die wel in git staat bevat:
```
APP_SECRET=changeme
DB_PASSWORD=changeme
```

Hoewel `docker-compose.yml` this environment variables injecteert en de werkelijke productie-waarden via `APP_SECRET` environment variable worden overschreven, is het risico dat development-omgevingen (of CI) de placeholder-waarden gebruiken zonder dit te weten.

**Risico:** Als de dev-omgeving per ongeluk met `APP_SECRET=changeme` draait, zijn sessies en CSRF-tokens triviaal te vervalsen.

**Aanbeveling:**
- Genereer voor elke omgeving een echte `APP_SECRET` en zet die in `.env.local` of Docker Secrets.
- Voeg een startup-check toe die deployment weigert als `APP_SECRET` een bekende placeholder-waarde heeft.

---

### MEDIUM — CSP heeft `'unsafe-inline'` voor `style-src`

**Locatie:** `app/src/EventSubscriber/SecurityHeadersSubscriber.php:64-65`

**Beschrijving:**
De Content Security Policy voor **productie** staat inline styles toe:

```
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
```

Dit is een bekende CSP-verzwakking. Inline style injection via XSS is mogelijk bij andere kwetsbaarheden.

**Risico:** Gedeeltelijk vermindert de effectiviteit van de CSP bij een XSS-aanval (style-based clickjacking, UI redressing).

**Aanbeveling:**
Verwijder `'unsafe-inline'` uit `style-src` en gebruik `nonce` of `hash`-gebaseerde CSP voor de paar inline stijlen die nodig zijn. Alternatiefelijk: verplaats alle inline stijlen naar externe stylesheets.

---

### MEDIUM — Geen `Content-Security-Policy` `upgrade-insecure-requests` in productie

**Locatie:** `app/src/EventSubscriber/SecurityHeadersSubscriber.php:58-69`

**Beschrijving:**
De CSP bevat geen `upgrade-insecure-requests` directive. Externe font-links (Google Fonts) worden via HTTP aangeroepen als de browser geen TLS enforceert.

**Aanbeveling:**
Voeg toe aan de productie-CSP:
```
upgrade-insecure-requests;
```

---

### LAAG — Wachtwoordbeleid is te permissief (8 tekens minimum, geen complexiteitsvereiste)

**Locatie:** `app/src/Controller/SecurityController.php:75,145`

**Beschrijving:**
Wachtwoorden worden alleen gevalideerd op een minimum van 8 tekens:
```php
} elseif (strlen($password) < 8) {
    $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
```
Er is geen complexiteitsvereiste (hoofdletters, cijfers, speciale tekens) en geen controle op veelvoorkomende wachtwoorden.

**Aanbeveling:**
Implementeer wachtwoordkwaliteitscontrole via Symfony's Validator component:
```php
use Symfony\Component\Validator\Constraints\PasswordStrength;
```
Of voeg minimaal een controle op wachtwoordlengte van 12+ tekens toe.

---

### LAAG — GitHub Actions gebruikt actie-versies zonder SHA-pinning

**Locatie:** `.github/workflows/deploy.yml:9`

**Beschrijving:**
```yaml
uses: appleboy/ssh-action@v1
```
Het gebruik van een tag (`v1`) in plaats van een commit-SHA maakt de workflow kwetsbaar voor supply chain attacks waarbij de actie-auteur een kwaadaardige update pusht onder hetzelfde tag.

**Aanbeveling:**
Pin acties aan een specifieke commit-SHA:
```yaml
uses: appleboy/ssh-action@4a03da89e5c43da56e502053be0e9d0a998b9a8a  # v1.0.0
```

---

### LAAG — Adminer is bereikbaar in productie (ook al via 127.0.0.1)

**Locatie:** `docker-compose.prod.yml:14-16`

**Beschrijving:**
Adminer is in productie geconfigureerd op `127.0.0.1:8081`. Hoewel dit SSH-tunneling vereist, heeft Adminer zelf geen authenticatie-laag anders dan de databasecredentials. Een gecompromitteerde SSH-sessie of port-forward geeft direct database-beheertoegang.

**Aanbeveling:**
Overweeg Adminer te verwijderen uit de productie-stack. Gebruik `psql` via een SSH-sessie voor database-beheer. Als Adminer toch nodig is: voeg HTTP Basic Auth toe via Caddy.

---

### LAAG — `display_name` kent geen maximumlengte aan de serverkant

**Locatie:** `app/src/Controller/SecurityController.php:73,126`

**Beschrijving:**
De `display_name` validatie controleert alleen een minimumlengte (2 tekens):
```php
} elseif (strlen($name) < 2) {
    $error = 'Naam moet minimaal 2 tekens bevatten.';
```
Er is geen maximumlengte. De database-kolom (`length: 100`) beperkt dit uiteindelijk, maar Doctrine/DBAL zal een generieke exception gooien die gevoelige informatie kan bevatten.

**Aanbeveling:**
Voeg validatie toe:
```php
} elseif (strlen($name) < 2 || strlen($name) > 100) {
    $error = 'Naam moet tussen 2 en 100 tekens bevatten.';
```

---

### INFO — `app/.env` bevat dubbele en tegenstrijdige `APP_ENV` / `APP_SECRET` waarden

**Locatie:** `app/.env:1,10`

**Beschrijving:**
Het bestand bevat eerst productie-waarden en daarna Symfony-gegenereerde placeholder-blokken, wat leidt tot:
```
APP_ENV=prod          # regel 1
...
APP_ENV=dev           # regel 10 (Symfony-generator block)
APP_SECRET=           # leeg
```
In PHP worden environment-variabelen door Symfony's Dotenv component verwerkt waarbij de **laatste** waarde wint. Dit betekent dat `APP_ENV=dev` en een lege `APP_SECRET` effectief actief zijn.

**Aanbeveling:**
Ruim `app/.env` op. Zet alleen productie-waarden in `app/.env.local`. Voer `php bin/console debug:container --env-vars` uit om te controleren welke waarden werkelijk worden gebruikt.

---

### INFO — `localStorage` gebruikt voor thema-opslag (geen gevoelige data, maar documenteer dit)

**Locatie:** `app/templates/base.html.twig:12`

```html
<script>var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t;</script>
```

**Beschrijving:**
`localStorage` wordt gebruikt voor de lichte/donkere thema-voorkeur. Hoewel dit geen gevoelige data betreft, is het een patroon dat gedocumenteerd moet worden zodat toekomstige ontwikkelaars niet per ongeluk gevoelige data (bijv. gebruikersdata of tokens) in `localStorage` opslaan.

**Aanbeveling:**
Voeg in de development docs een expliciete regel op dat `localStorage` alleen voor UI-voorkeuren mag worden gebruikt.

---

### INFO — Geen expliciete session-timeout / inactiviteitslogout

**Locatie:** `app/config/packages/security.yaml:33-38`

**Beschrijving:**
De `remember_me` lifetime is ingesteld op 604800 seconden (7 dagen). Er is geen inactiviteits-timeout geconfigureerd. Sessieverloop is afhankelijk van de PHP-standaardinstellingen (`session.gc_maxlifetime`).

**Aanbeveling:**
Voeg een inactiviteitsbeperking toe aan de firewall:
```yaml
main:
  session_fixation_strategy: migrate
  # Voeg een maximale sessieduur in via een custom authenticator of bundle.
```
Overweeg `knpuniversity/oauth2-client-bundle` of een custom event listener om sessies na X minuten inactiviteit te vernielen.

---

### INFO — Dockerfile `prod`-stage: Composer scripts worden deels genegeerd met `|| true`

**Locatie:** `app/Dockerfile:50-52`

**Beschrijving:**
```dockerfile
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true \
    && php bin/console cache:warmup \
    && php bin/console asset-map:compile 2>/dev/null || true
```

De `|| true` zorgt ervoor dat fouten in `post-install-cmd` en `asset-map:compile` stil worden genegeerd. Dit kan leiden tot een deploy waarbij de cache of assets niet correct worden gecompileerd, zonder dat de Docker-build faalt.

**Aanbeveling:**
Verwijder `|| true` of vervang door expliciete foutafhandeling. Als bepaalde scripts niet van toepassing zijn, gebruik dan een expliciete conditie:
```dockerfile
RUN composer run-script post-install-cmd --no-interaction || echo "No post-install-cmd scripts defined"
RUN php bin/console cache:warmup
RUN php bin/console asset-map:compile
```

---

## Positieve bevindingen

De volgende security-maatregelen zijn correct geïmplementeerd en verdienen vermelding:

- **CSRF-bescherming** is consequent aanwezig op alle state-muterende endpoints (formulieren via `isCsrfTokenValid`, AJAX via `X-CSRF-Token` header).
- **Parameterized queries** worden correct gebruikt in alle Doctrine DBAL-queries buiten de `translationWordsBelongToTranslation` uitzondering.
- **IDOR-bescherming** is aanwezig: `translationWordsBelongToTranslation()` en `sourceWordExists()` voorkomen cross-user data-manipulatie.
- **Role-gebaseerde toegangscontrole** is goed ingericht via `security.yaml` `access_control` en `#[IsGranted]` attributen.
- **Login throttling** (5 pogingen per 15 minuten) is geconfigureerd.
- **Rate limiting** op registratie is aanwezig.
- **Password hashing** gebruikt Symfony's `auto` algoritme (Bcrypt/Argon2).
- **Security headers** zijn uitgebreid geconfigureerd: HSTS, X-Content-Type-Options, X-Frame-Options DENY, Referrer-Policy, Permissions-Policy, CSP met sha256-hash voor importmap.
- **Adminer** is in productie gebonden aan `127.0.0.1` (SSH-tunnel vereist).
- **Database** is niet publiek exposed in productie.
- **Remember-me** cookie heeft `samesite: strict`.
- **Sessie-cookies** hebben `cookie_secure: auto` en `cookie_samesite: strict`.
