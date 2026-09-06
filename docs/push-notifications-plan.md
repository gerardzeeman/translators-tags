# Push-meldingen ("Nieuwsoverzicht") — implementatieplan

Status: **plan, nog niet geïmplementeerd**
Branch: `feat/push-notifications`

## Doel

De scheduled task "Dagelijks CGK/Rijnsburg nieuwsoverzicht" stuurt op dit moment
alleen een melding naar de telefoon van de eigenaar (via het notificatiekanaal
van Claude Code zelf). Dat kanaal is persoonlijk en kan niet naar andere
telefoons doorgestuurd worden.

Dit plan maakt Alef-Omega (`alefomega.nl`) de verspreider: gebruikers met een
nieuwe rol kunnen zich in de app abonneren op browser-pushmeldingen (Web Push
API + Service Worker). Zodra de scheduled task een nieuw overzicht heeft, post
hij dat naar een nieuw endpoint in deze app; de app stuurt vervolgens een
pushmelding naar alle geabonneerde gebruikers — op elk toestel/besturingssysteem
dat de browser ondersteunt (Android volledig, iOS 16.4+ mits de site als
"Zet op beginscherm" is toegevoegd).

```
Scheduled task (Claude Code)
        │  genereert overzicht
        ▼
HTTP POST /api/nieuwsoverzicht/push   (Bearer-token, geen sessie)
        │
        ▼
Alef-Omega backend
        │  zoekt alle PushSubscription's van gebruikers met ROLE_CGK_RIJNSBURG_NIEUWS
        ▼
Web Push (VAPID, minishlink/web-push)
        │
        ▼
Browser service worker (elke geabonneerde telefoon)
        │  toont notificatie, klik → opent overzicht-URL
```

**Belangrijke randvoorwaarde buiten deze codebase:** de scheduled task zelf
moet worden uitgebreid met een actie die het overzicht als HTTP POST naar dit
endpoint stuurt (bijv. via `curl` of de WebFetch-tool), náást of in plaats van
de huidige telefoonmelding. Dat is een wijziging aan de scheduled-task-config,
niet aan deze applicatie — buiten scope van dit plan.

---

## Beslissingen (bevestigd door gebruiker)

1. **Rolnaam**: `ROLE_CGK_RIJNSBURG_NIEUWS` — specifiek voor dit overzicht,
   niet generiek. Komt er later een tweede soort pushmelding bij, dan hoort
   daar een eigen rol (en eventueel een eigen `type`-onderscheid op
   `NewsDigest`, zie §7) bij, niet hergebruik van deze rol.
2. **Roltoekenning**: alleen `ROLE_ADMIN` via `/admin/users`, zoals alle
   andere rollen — geen self-service opt-in.
3. **Verzendhistorie**: de `NewsDigest`-tabel met alleen-lezen
   `/admin`-overzicht (§5.3) blijft in het plan.
4. **Icoon/branding**: `favicon.png` als notificatie-icoon, geen apart
   ontwerptraject.

---

## 1. Datamodel

Twee nieuwe Doctrine-entities (ORM, zelfde patroon als `User` — zie
[blog-feature-plan.md](blog-feature-plan.md) §1 voor de precedent van
app-eigen content naast de Bijbel/Institutio DBAL-tabellen).

```
PushSubscription
├── id            int, PK
├── user          ManyToOne → User
├── endpoint      text                      — browser push-endpoint-URL, uniek per (user, endpoint) — zie §5.1
├── p256dhKey     string(255)               — encryptiesleutel uit PushSubscription.toJSON()
├── authKey       string(255)               — auth-secret uit PushSubscription.toJSON()
├── userAgent     string(255), nullable     — voor beheer/debug
├── createdAt     datetime_immutable
└── lastFailureAt datetime_immutable, nullable
    UNIQUE (user_id, endpoint)

NewsDigest
├── id             int, PK
├── idempotencyKey string(32), unique       — client-key of server-fallback (UTC-datum) — zie §5.2
├── title          string(255)
├── body           text
├── url            string(255), nullable    — relatief same-origin pad, gevalideerd bij ontvangst — zie §5.2
├── sentAt         datetime_immutable
├── successCount   int
└── failureCount   int
```

Eén gebruiker kan meerdere `PushSubscription`s hebben (meerdere apparaten/
browsers). Een subscription die bij verzending een `404`/`410` teruggeeft
(browser-abonnement verlopen) wordt direct verwijderd — geen losse
`lastFailureAt`-opruimjob nodig voor dat geval; het veld is alleen voor
tijdelijke fouten (bv. netwerktimeout) zodat structureel falende subscriptions
zichtbaar zijn in `/admin`.

Migratie volgt het bestaande patroon in `app/migrations/VersionYYYYMMDDHHMMSS.php`.

---

## 2. Rol & toegang

`config/packages/security.yaml`:

```yaml
role_hierarchy:
    ROLE_CGK_RIJNSBURG_NIEUWS:       [ROLE_VIEWER]
    ROLE_ADMIN:                 [..., ROLE_CGK_RIJNSBURG_NIEUWS]   # bestaande lijst + deze

access_control:
    - { path: ^/account/meldingen,          roles: ROLE_CGK_RIJNSBURG_NIEUWS }
    - { path: ^/api/nieuwsoverzicht/push,   roles: PUBLIC_ACCESS }   # bewust: auth via Bearer-token in de controller, niet via de sessie-firewall (aanroeper is de scheduled task, geen ingelogde browser)
```

`AdminUserController::ASSIGNABLE_ROLES` krijgt er één regel bij:

```php
'ROLE_CGK_RIJNSBURG_NIEUWS' => 'Nieuwsmeldingen (push)',
```

Daarmee kan een admin de rol per gebruiker aan-/uitzetten op de bestaande
`/admin/users`-pagina's — geen nieuwe beheer-UI nodig.

---

## 3. VAPID-sleutels & configuratie

Web Push vereist een VAPID-sleutelpaar (publiek/privé) waarmee de server zich
bij de browsers' pushdiensten (FCM voor Chrome/Android, Mozilla Autopush voor
Firefox, Apple's dienst voor Safari/iOS) legitimeert.

```bash
docker compose exec app php bin/console app:push:generate-vapid-keys
```

(eigen console-commando bovenop `minishlink/web-push`'s `VAPID::createVapidKeys()`,
zodat het zonder losse Node/CLI-tool kan — consistent met de rest van het
project, dat verder geen Node-runtime nodig heeft buiten de asset-build).

Nieuwe env-vars, zelfde patroon als `GOOGLE_ANALYTICS_ID`/`ANTHROPIC_API_KEY`
(zie [`docs/deployment.md`](deployment.md)):

- root `.env.local`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `NEWS_DIGEST_WEBHOOK_TOKEN`
  (`openssl rand -hex 32`), `NEWS_DIGEST_PUSH_ENABLED` (default `true` — zie
  §3.1 hieronder)
- `docker-compose.yml`, `app`-service `environment:`-blok:
  ```yaml
  VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:-}
  VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:-}
  NEWS_DIGEST_WEBHOOK_TOKEN: ${NEWS_DIGEST_WEBHOOK_TOKEN:-}
  NEWS_DIGEST_PUSH_ENABLED: ${NEWS_DIGEST_PUSH_ENABLED:-true}
  ```
- `app/.env` (defaults) + `config/services.yaml` parameters die naar
  `%env(VAPID_PUBLIC_KEY)%` etc. verwijzen, zodat services en Twig
  (`vapid_public_key` global) ze kunnen gebruiken.

Voeg de vier secrets/vlaggen ook toe aan de checklist in `docs/deployment.md`
§4/§7.1 en aan de GitHub Actions secrets-tabel (§ "Secrets toevoegen"), als
die uiteindelijk via CI wordt aangemaakt/geroteerd.

### 3.1 Kill switch (los van tokenrotatie)

`NEWS_DIGEST_PUSH_ENABLED` wordt als eerste gecontroleerd in
`NewsDigestWebhookController` (zie §5.2) — op `false` antwoordt het endpoint
direct `503`, zonder het token te controleren of iets te verwerken. Om
verzending te pauzeren (bv. bij een vermoede tokenlek, of om welke reden dan
ook) volstaat dus:

```bash
# .env.local: NEWS_DIGEST_PUSH_ENABLED=false
docker compose up -d   # herstart, geen rebuild/redeploy nodig
```

Dat is aanzienlijk sneller dan een volledige tokenrotatie + CI/CD-redeploy,
en kan dus ook als eerste, voorlopige maatregel dienen terwijl het token
alsnog geroteerd wordt.

### 3.2 Impact van sleutelrotatie

Het roteren van `VAPID_PRIVATE_KEY`/`VAPID_PUBLIC_KEY` (bv. na een vermoede
lek) maakt **in één keer alle bestaande browserabonnementen ongeldig**: elk
abonnement is bij het aanmaken cryptografisch gekoppeld aan de toen geldende
publieke sleutel, en de pushdienst (FCM/Autopush/Apple) wijst elke verzending
met de nieuwe sleutel af (`401`/`403`) totdat de browser opnieuw abonneert
met de nieuwe sleutel. Dit wordt op twee plekken opgevangen, zodat het geen
stille storing wordt:

- **Server:** `SendNewsDigestPushHandler` (§5.2) behandelt `401`/`403` van de
  pushdienst hetzelfde als `404`/`410` — de `PushSubscription`-rij wordt
  verwijderd. Een verlopen/ongeldige rij blijft dus nooit onnodig staan,
  ongeacht de oorzaak.
- **Client:** `push_subscribe_controller.js` (§6.2) vergelijkt bij elk bezoek
  van `/account/meldingen` de `applicationServerKey` waarmee de browser
  ooit heeft geabonneerd tegen de huidige, door de server aangeleverde
  `vapid_public_key`. Bij een mismatch (na rotatie) wordt lokaal
  `unsubscribe()` aangeroepen en de toggle teruggezet naar "uit", met een
  duidelijke melding dat opnieuw inschakelen nodig is — in plaats van dat de
  gebruiker denkt geabonneerd te zijn terwijl meldingen allang niet meer
  aankomen.

Neem de rotatiestap zelf (wanneer, door wie, met welk effect) op in de
secret-rotatiechecklist van `docs/deployment.md`, zoals daar nu al voor
`APP_SECRET` gebeurt.

---

## 4. Backend package

```bash
docker compose exec app composer require minishlink/web-push
```

`minishlink/web-push` implementeert het volledige Web Push-protocol (VAPID,
payload-encryptie per RFC 8291) en geeft per subscription een verzendrapport
terug (incl. HTTP-statuscode van de pushdienst) — nodig om verlopen
subscriptions te kunnen opruimen.

---

## 5. Endpoints

### 5.1 Zelf-abonneren (ingelogde gebruiker, `ROLE_CGK_RIJNSBURG_NIEUWS`)

`src/Controller/PushSubscriptionController.php`, route-prefix `/account/meldingen`:

| Route | Methode | Doel |
|-------|---------|------|
| `/account/meldingen` | GET | Pagina met aan/uit-toggle + huidige status (leest of de browser al een actief abonnement heeft via JS, niet server-side af te leiden) |
| `/account/meldingen/abonneren` | POST | Body: `{endpoint, keys: {p256dh, auth}}` (rechtstreeks `PushSubscription.toJSON()` uit de browser) → upsert op `(user, endpoint)` |
| `/account/meldingen/opzeggen` | POST | Body: `{endpoint}` → verwijdert de rij |

Beveiliging: gewone sessie-firewall + CSRF-token (zelfde patroon als
`AdminUserController` — `isCsrfTokenValid`).

**Eigenaarscontrole (verplicht, geen losse vervolgstap):** zowel abonneren als
opzeggen scopen altijd op de ingelogde gebruiker, nooit alleen op `endpoint`:

```php
// opzeggen
$subscription = $repository->findOneBy(['endpoint' => $endpoint, 'user' => $this->getUser()]);
if ($subscription === null) {
    return $this->json(['status' => 'ok']); // stil niets doen — geen bevestiging
}                                             // of ontkenning dat de endpoint elders bestaat
$em->remove($subscription);
$em->flush();

// abonneren (upsert)
$subscription = $repository->findOneBy(['endpoint' => $endpoint, 'user' => $this->getUser()])
    ?? new PushSubscription($this->getUser(), $endpoint);
$subscription->setKeys($p256dh, $auth);
$em->persist($subscription);
$em->flush();
```

Een `endpoint` die al aan een **andere** gebruiker hoort, wordt bij abonneren
dus niet overgenomen (geen reassignment) — de upsert-`WHERE` bevat altijd
`user = :current_user`, dus dat scenario resulteert simpelweg in een nieuwe
rij voor de huidige gebruiker met dezelfde endpoint-string. Omdat `endpoint`
uniek is over de hele tabel (zie §1), moet de kolom daarom **niet**
`unique: true` zijn maar een samengestelde unique-constraint op
`(user, endpoint)` — anders gooit de tweede gebruiker een DB-fout in plaats
van gewoon een eigen rij te krijgen. (In de praktijk genereert dezelfde
browser/device na een eerdere `unsubscribe()` meestal een nieuwe endpoint bij
een volgende `subscribe()`, maar de constraint moet dit randgeval hoe dan ook
correct afhandelen in plaats van op een DB-exceptie te vertrouwen.)

**Formaatvalidatie & cap per gebruiker (verplicht, geen losse vervolgstap):**
`/account/meldingen/abonneren` valideert de drie velden vóór het opslaan, en
weigert een nieuw abonnement als de gebruiker al vijf actieve heeft:

```php
private function isValidSubscriptionPayload(string $endpoint, string $p256dh, string $auth): bool
{
    return str_starts_with($endpoint, 'https://')
        && strlen($endpoint) <= 512
        && (bool) preg_match('#^[A-Za-z0-9_-]{60,}$#', $p256dh)  // base64url, ~65 bytes rauw
        && (bool) preg_match('#^[A-Za-z0-9_-]{16,}$#', $auth);   // base64url, ~16 bytes rauw
}

// vóór persist():
if ($subscription === null && $repository->count(['user' => $this->getUser()]) >= 5) {
    return $this->json(['error' => 'Maximaal 5 apparaten per account.'], 429);
}
```

Bij een ongeldige payload of een overschreden limiet: `422` respectievelijk
`429`, niets opgeslagen. Dit begrenst zowel per-ongeluk als moedwillig misbruik
van de route door een account dat de rol al heeft — de route blijft verder
onbereikbaar voor iedereen zonder `ROLE_CGK_RIJNSBURG_NIEUWS` (§2).

### 5.2 Binnenkomend webhook (machine-naar-machine, geen sessie)

`src/Controller/NewsDigestWebhookController.php`:

| Route | Methode | Doel |
|-------|---------|------|
| `/api/nieuwsoverzicht/push` | POST | Body: `{title, body, url?, idempotency_key?}`. Header `Authorization: Bearer <NEWS_DIGEST_WEBHOOK_TOKEN>`, vergeleken met `hash_equals()` (timing-safe). |

Verwerkingsvolgorde in de controller — elke stap kan het verzoek beëindigen
vóórdat de volgende (duurdere) stap wordt uitgevoerd:

1. **Kill switch** (§3.1): `NEWS_DIGEST_PUSH_ENABLED=false` → `503`, niets
   anders wordt gecontroleerd of gelogd.
2. **Token** (`hash_equals`) → mismatch: `403`.
3. **Rate limit** → over de limiet: `429`.
4. **Idempotentie** (zie hieronder) → bekende sleutel: `200` met het eerder
   opgeslagen resultaat, geen nieuwe verzending.
5. **`url`-validatie** → ongeldig: `422`.
6. Geldig: `NewsDigest`-rij opslaan, `SendNewsDigestPush` dispatchen → `202`.

**Rate limiting (verplicht, geen losse vervolgstap):** dit endpoint heeft één
legitieme aanroeper (de scheduled task) die hooguit een paar keer per dag
zou moeten posten. `symfony/rate-limiter` (al een dependency, nu gebruikt
voor `login_throttling`) krijgt een tweede policy, globaal gesleuteld (niet
per IP — de scheduled task draait in de cloud met wisselend IP-adres):

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        news_digest_push:
            policy: fixed_window
            limit: 10
            interval: '1 hour'
```

Dit begrenst zowel misbruik van een gelekt token als een verkeerd
geconfigureerde scheduled task die per ongeluk in een lus komt te draaien.

**Idempotentie (verplicht, geen losse vervolgstap):** de aanroeper mag een
`idempotency_key` meesturen; ontbreekt die, dan valt de server terug op de
huidige UTC-datum (`Y-m-d`) — passend bij een taak die hooguit eens per dag
loopt, zonder dat de aanroeper iets hoeft aan te passen. `NewsDigest` krijgt
een unieke kolom `idempotencyKey`. Bestaat er al een rij met die sleutel, dan
wordt er niets opnieuw verstuurd; de controller antwoordt `200` met de
destijds opgeslagen `successCount`/`failureCount`. Zo is een netwerkretry
vanuit de scheduled task altijd veilig, en kan dezelfde dag nooit twee keer
dezelfde melding pushen.

**`url`-validatie:** vóórdat de `NewsDigest`-rij wordt opgeslagen of er iets
gedispatcht wordt, wordt `url` gecontroleerd op een relatief, same-origin
pad. Alles anders wordt verworpen met `422`, niet stilzwijgend genegeerd of
ongevalideerd doorgezet:

```php
private function isValidRelativePath(?string $url): bool
{
    if ($url === null || $url === '') {
        return true; // optioneel veld, sw.js valt terug op '/'
    }
    // moet beginnen met exact één '/': geen 'https://...' (absoluut),
    // geen '//evil.tld' (protocol-relative), geen 'javascript:'/'data:'
    return (bool) preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $url);
}
```

Bij een geldig verzoek: slaat een `NewsDigest`-rij op (incl.
`idempotencyKey`), dispatcht daarna een Messenger-bericht
`SendNewsDigestPush` (huidige `sync://`-transport is voldoende bij een
handvol abonnees per dag; als het aantal groeit is dit later zonder
controller-wijziging naar `async` te verplaatsen — alleen
`messenger.yaml`-routing verandert dan).

`SendNewsDigestPushHandler` haalt alle `PushSubscription`s op van gebruikers
met `ROLE_CGK_RIJNSBURG_NIEUWS`, stuurt de melding via `minishlink/web-push`, en
verwerkt per subscription het rapport: `410 Gone`/`404 Not Found` **en**
`401 Unauthorized`/`403 Forbidden` (VAPID-sleutel niet meer geldig, zie §3.2)
→ subscription verwijderen; overig succes/falen → tellers op de
`NewsDigest`-rij bijwerken.

### 5.3 Admin-historie

Volgt uit beslissing 3 (§ "Beslissingen"): een alleen-lezen overzicht van
verstuurde overzichten.

`src/Controller/AdminNewsDigestController.php`, `#[Route('/admin/nieuwsoverzicht')]`
`#[IsGranted('ROLE_ADMIN')]` — zelfde beveiligingspatroon als
`AdminUserController`. Toont `NewsDigest::findBy([], ['sentAt' => 'DESC'])`
in een tabel (`sentAt`, `title`, `url`, `successCount`/`failureCount`).

`title`/`body`/`url` zijn afkomstig van een systeem buiten deze applicatie
(de scheduled task) en worden dus als niet-vertrouwde tekst behandeld, niet
anders dan gebruikersinvoer elders in de app:

```twig
{# templates/admin/news_digest/index.html.twig #}
<td>{{ digest.title }}</td>   {# Twig's standaard auto-escaping — nooit |raw op deze velden #}
```

Geen extra sanitisatie nodig zolang Twig's default `autoescape` (al actief in
`config/packages/twig.yaml`, ongewijzigd) van toepassing blijft — XSS via de
OS-notificatie zelf is sowieso niet mogelijk (die toont altijd platte tekst,
zie §6.1), dit gaat puur over deze ene toekomstige HTML-weergave.

---

## 6. Frontend

### 6.1 Service worker

`public/sw.js` — bewust **buiten** de AssetMapper-pijplijn (`assets/`) gehouden:
een service worker moet op een stabiele, ongehashte URL staan om de hele
origin (`/`) als scope te kunnen claimen. Minimale inhoud:

```js
self.addEventListener('push', (event) => {
    const data = event.data.json();
    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/favicon.png',
        data: { url: data.url },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url || '/'));
});
```

### 6.2 Stimulus-controller

`assets/controllers/push_subscribe_controller.js`, geregistreerd zoals de
overige controllers in `assets/controllers.json`. Op de
`/account/meldingen`-pagina:

1. Voelt `'serviceWorker' in navigator && 'PushManager' in window` — toont
   anders een nette "niet ondersteund door je browser"-melding (met name
   relevant voor oudere iOS-versies of privé-browsen).
2. Registreert `/sw.js` (`navigator.serviceWorker.register('/sw.js')`).
3. **Sleutelrotatie-detectie (verplicht, geen losse vervolgstap — zie §3.2):**
   bij elke page-load, vóórdat de aan/uit-status bepaald wordt, haalt de
   controller de bestaande subscription op
   (`registration.pushManager.getSubscription()`) en vergelijkt
   `subscription.options.applicationServerKey` byte-voor-byte met de huidige
   `vapid_public_key` uit de pagina. Bij een mismatch: lokaal
   `subscription.unsubscribe()` aanroepen, status tonen als "uit", met een
   melding dat meldingen opnieuw ingeschakeld moeten worden (de oude
   subscription kan met de nieuwe sleutel nooit meer slagen — zie §3.2).
4. Vraagt toestemming (`Notification.requestPermission()`) — alleen op
   expliciete klik van de gebruiker (browsers blokkeren dit bij
   page-load-aanvragen).
5. Abonneert: `registration.pushManager.subscribe({ userVisibleOnly: true,
   applicationServerKey: <VAPID public key, base64 → Uint8Array> })`.
6. Post het resultaat naar `/account/meldingen/abonneren`.
7. Bij uitzetten: `subscription.unsubscribe()` in de browser + POST naar
   `/account/meldingen/opzeggen`.

De VAPID-publieke sleutel komt als Twig-global (`vapid_public_key`) op de
pagina, in een `data-push-subscribe-vapid-key-value`-attribuut — zelfde
Stimulus-value-conventie als de bestaande controllers (bv.
`strongs_select_controller.js`).

### 6.3 Navigatie

Eén link "Meldingen" toevoegen aan de bestaande navigatie
(`templates/base.html.twig` / `nav_panel_controller.js`), zichtbaar zodra
`is_granted('ROLE_CGK_RIJNSBURG_NIEUWS')`.

---

## 7. Niet in scope (MVP)

- Andere soorten pushmeldingen dan het CGK/Rijnsburg-overzicht (het datamodel
  laat dit toe via een `type`-veld op `NewsDigest`, maar wordt nu niet gebouwd).
- Automatisch opnieuw proberen bij tijdelijke verzendfouten (netwerktimeout) —
  wordt alleen gelogd, niet herhaald.
- E-mail- of SMS-fallback voor gebruikers zonder ondersteunde browser.

---

## 8. Beveiligingsaudit (plan-niveau, vóór implementatie)

> Auditor: Claude Sonnet 5 · Scope: dit document, geen code (nog niet gebouwd)

| Ernst  | Aantal |
|--------|--------|
| Hoog   | 2 (✅ alle verwerkt in het plan) |
| Medium | 3 (✅ alle verwerkt in het plan) |
| Laag   | 2 (✅ alle verwerkt in het plan) |
| Info   | 3      |

### ✅ VERWERKT — HOOG — Ongevalideerde `url` opent willekeurige bestemming bij klik op de melding

**Locatie:** §5.2 (webhook-body `{title, body, url?}`), §6.1 (`sw.js`
`notificationclick` → `clients.openWindow(event.notification.data.url)`)
**Fix opgenomen in:** §5.2 (`isValidRelativePath()`, `422` bij afwijzing), §1 (kolomomschrijving)

Er was geen validatie op `url` voorzien. Als het webhook-token ooit lekt (zie
Info-bevinding hieronder) kon een aanvaller een melding pushen die er voor elke
abonnee uitziet als een vertrouwde melding van Alef-Omega, maar bij een klik
een externe phishingpagina opent. §5.2 valideert nu server-side dat `url`
een relatief, same-origin pad is en verwerpt absolute URL's (`http:`,
`https:`, `javascript:`, `data:`, protocol-relative `//`) met een `422`,
vóórdat de `NewsDigest`-rij wordt opgeslagen.

### ✅ VERWERKT — HOOG — Ontbrekende eigenaarscontrole op `/account/meldingen/opzeggen`

**Locatie:** §5.1
**Fix opgenomen in:** §5.1 (query gescopet op `user = :current_user`), §1 (samengestelde unique-constraint)

Een gebruiker met `ROLE_CGK_RIJNSBURG_NIEUWS` kon een willekeurige `endpoint`-string
meesturen; zonder expliciete check verwijderde dit elke `PushSubscription`-rij
met die endpoint, ongeacht van wie — een IDOR waarmee elke abonnee het
abonnement van een andere abonnee kon opzeggen. §5.1 scopet abonneren én
opzeggen nu altijd op `(endpoint, user = huidige gebruiker)`; een niet-match
bij opzeggen doet stil niets (geen foutmelding die verklapt of de endpoint bij
iemand anders hoorde). Dit vereiste ook een aanpassing van de unique-
constraint op `PushSubscription` (§1): niet los op `endpoint`, maar
samengesteld op `(user, endpoint)`.

### ✅ VERWERKT — MEDIUM — Geen rate limiting of kill switch op de webhook, los van het token

**Locatie:** §5.2
**Fix opgenomen in:** §3.1 (kill switch), §5.2 (rate limiter policy + verwerkingsvolgorde)

Als `NEWS_DIGEST_WEBHOOK_TOKEN` ooit lekt, was de enige stop een handmatige
rotatie + herdeploy. §5.2 voegt een `symfony/rate-limiter`-policy toe
(`news_digest_push`, 10/uur, globaal gesleuteld) als stap 3 in de
verwerkingsvolgorde. §3.1 voegt daarnaast `NEWS_DIGEST_PUSH_ENABLED` toe: een
kill switch die verzending direct pauzeert met alleen een `docker compose up
-d` (geen tokenrotatie of herdeploy nodig) — bruikbaar als eerste maatregel
terwijl het token alsnog geroteerd wordt.

### ✅ VERWERKT — MEDIUM — Geen replay-/idempotentiebescherming op de webhook

**Locatie:** §5.2
**Fix opgenomen in:** §5.2 (idempotentie-stap), §1 (`NewsDigest.idempotencyKey`)

Een netwerkretry vanuit de scheduled task (of een afgevangen en herhaald
verzoek binnen de levensduur van het token) stuurde de melding nogmaals naar
alle abonnees. §5.2 introduceert een optionele `idempotency_key` in de
request-body, met een server-side fallback op de huidige UTC-datum
(`Y-m-d`) als die ontbreekt — passend bij een taak die hooguit eens per dag
draait. Een tweede verzoek met dezelfde sleutel dispatcht niets opnieuw en
krijgt gewoon het eerder opgeslagen resultaat terug (`200`).

### ✅ VERWERKT — MEDIUM — Impact van VAPID-sleutelrotatie niet uitgewerkt

**Locatie:** §3
**Fix opgenomen in:** §3.2 (rotatie-impact), §5.2 (verbrede cleanup op `401`/`403`), §6.2 (client-side rotatiedetectie)

Bij een vermoede lek van `VAPID_PRIVATE_KEY` is roteren nog steeds de enige
optie, en dat maakt nog steeds **alle** bestaande browserabonnementen in één
keer ongeldig — maar dit gebeurt niet langer stil. §3.2 legt de twee kanten
vast: server-side worden `401`/`403`-reacties van de pushdienst voortaan net
als `404`/`410` behandeld (subscription wordt opgeruimd), en client-side
vergelijkt de Stimulus-controller (§6.2, stap 3) bij elke page-load de
sleutel waarmee ooit geabonneerd is met de huidige — bij een mismatch wordt
de gebruiker expliciet gevraagd opnieuw in te schakelen, in plaats van in de
veronderstelling te blijven dat meldingen nog aankomen.

### ✅ VERWERKT — LAAG — Geen cap of formaatvalidatie op nieuwe `PushSubscription`-rijen

**Locatie:** §5.1
**Fix opgenomen in:** §5.1 (`isValidSubscriptionPayload()`, max. 5 subscriptions/gebruiker)

Beperkt risico omdat de route al `ROLE_CGK_RIJNSBURG_NIEUWS` vereist, maar niets
weerhield zo'n account ervan herhaaldelijk te posten met verzonnen
`endpoint`/`p256dh`/`auth`-waarden. §5.1 valideert nu lengte/formaat van alle
drie de velden en weigert (`429`) een nieuw abonnement zodra een gebruiker
al vijf actieve heeft.

### ✅ VERWERKT — LAAG — `NewsDigest.title`/`body` zijn extern aangeleverde, ongefilterde tekst

**Locatie:** §1, §5.2
**Fix opgenomen in:** §5.3 (admin-historieweergave, expliciete escaping-eis)

Het `/admin`-historieoverzicht uit beslissing 3 was nog niet uitgewerkt; nu
het dat is (§5.3), staat er expliciet bij dat `title`/`body`/`url` als
niet-vertrouwde tekst behandeld worden en dat de Twig-template nooit `|raw`
op deze velden mag gebruiken — de inhoud komt van een systeem buiten deze
applicatie. (XSS via de OS-notificatie zelf was sowieso al niet mogelijk, die
toont altijd platte tekst; dit ging puur over de toekomstige HTML-weergave.)

### INFO — Het webhook-token leeft ook buiten deze repository

Wie het `NEWS_DIGEST_WEBHOOK_TOKEN` in de configuratie van de Claude
Code-scheduled-task kan inzien of bewerken, heeft dezelfde macht als iemand
die het token direct heeft. Dat valt buiten deze codebase, maar hoort bij het
dreigingsmodel van deze feature — betrek dit bij de beslissing wie toegang
heeft tot die scheduled-task-configuratie.

### INFO — Bestaande CSP-header ontbreekt in `app/Caddyfile`, ondanks dat `docs/security.md`/`SECURITY-AUDIT-LOGIN.md` hem als opgelost registreren

Losstaand van dit plan gevonden tijdens de audit: het huidige
`header { ... }`-blok in `app/Caddyfile` bevat geen
`Content-Security-Policy`-regel, terwijl eerdere audit-documenten vermelden
dat die is toegevoegd. Niet iets om nu op te lossen, maar relevant zodra dat
alsnog gebeurt: een CSP moet `worker-src 'self'` (of een `script-src 'self'`
die als fallback dient) bevatten, anders registreert `public/sw.js` niet meer
en breekt deze hele feature stilzwijgend.

### INFO — Meldingsinhoud is zichtbaar op het vergrendelscherm

Pushmeldingen verschijnen standaard ook op een vergrendeld toestel. Prima voor
een publiek kerknieuwsoverzicht, maar hou hier rekening mee als het bereik van
dit kanaal ooit verbreedt naar gevoeligere inhoud.

---

## 9. Bouwvolgorde

1. Migratie + entities `PushSubscription`, `NewsDigest` (incl. de
   samengestelde unique-constraint en `idempotencyKey` uit §1).
2. Rol `ROLE_CGK_RIJNSBURG_NIEUWS` in `security.yaml` + `AdminUserController`.
3. `composer require minishlink/web-push`, VAPID-sleutels genereren, env-vars
   wiren (root `.env.local`, `docker-compose.yml`, `config/services.yaml`) —
   inclusief `NEWS_DIGEST_PUSH_ENABLED` (§3.1) en de
   `news_digest_push`-rate-limiter-policy (§5.2).
4. `NewsDigestWebhookController` + `SendNewsDigestPushHandler` (Messenger)
   volgens de volledige verwerkingsvolgorde uit §5.2 (kill switch → token →
   rate limit → idempotentie → `url`-validatie → dispatch), en de verbrede
   `401`/`403`-cleanup uit §3.2.
5. `PushSubscriptionController` + `/account/meldingen`-pagina, inclusief de
   eigenaarscontrole (§5.1) én de formaatvalidatie/cap van 5 subscriptions
   per gebruiker (§5.1) — beide al onderdeel van het endpoint-ontwerp.
6. `public/sw.js` + `push_subscribe_controller.js` (incl. de
   sleutelrotatie-detectie uit §6.2, stap 3) + navigatielink.
7. `AdminNewsDigestController` + historie-template (§5.3), met Twig's
   default auto-escaping op `title`/`body`/`url` — geen `|raw`.
8. Handmatig testen: eigen account de rol geven, abonneren in de browser,
   `curl` naar `/api/nieuwsoverzicht/push` met testtoken, melding checken;
   expliciet ook testen dat een tweede identiek verzoek niet dubbel verstuurt,
   dat opzeggen met andermans endpoint niets doet, dat een zesde abonnement
   per gebruiker geweigerd wordt, en dat een VAPID-rotatie de toggle op
   `/account/meldingen` terugzet naar "uit".
9. Scheduled-task-config (buiten deze repo) uitbreiden met de HTTP-POST-actie.
10. De rotatieprocedure uit §3.2 (VAPID) en het bestaande `APP_SECRET`-patroon
    toevoegen aan de secret-checklist in `docs/deployment.md` — het ontwerp
    staat al in dit plan, dit is alleen nog het overnemen ervan in dat
    losstaande document.
