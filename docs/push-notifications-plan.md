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
        │  zoekt alle PushSubscription's van gebruikers met ROLE_NEWS_SUBSCRIBER
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

## Openstaande beslissingen (graag bevestigen)

1. **Rolnaam**: hieronder gebruikt als `ROLE_NEWS_SUBSCRIBER` ("Nieuwsmeldingen
   ontvangen"). Verdient dat een generieke naam (herbruikbaar voor toekomstige
   soorten meldingen) of specifiek voor dit CGK/Rijnsburg-overzicht?
2. **Wie mag de rol toekennen**: alleen `ROLE_ADMIN` via `/admin/users` (zoals
   alle andere rollen), aannemen dat dat volstaat.
3. **Bewaren van verstuurde overzichten**: MVP hieronder logt elke push (titel,
   tekst, tijdstip, aantal geslaagd/mislukt) in een simpele `NewsDigest`-tabel,
   zodat er een `/admin`-overzicht van de historie is. Nodig, of overbodig?
4. **Icoon/branding van de melding**: gebruikt voorlopig `favicon.png` als
   notificatie-icoon; geen apart ontwerp nodig tenzij gewenst.

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
├── id            int, PK
├── title         string(255)
├── body          text
├── url           string(255), nullable    — relatief same-origin pad, gevalideerd bij ontvangst — zie §5.2
├── sentAt        datetime_immutable
├── successCount  int
└── failureCount  int
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
    ROLE_NEWS_SUBSCRIBER:       [ROLE_VIEWER]
    ROLE_ADMIN:                 [..., ROLE_NEWS_SUBSCRIBER]   # bestaande lijst + deze

access_control:
    - { path: ^/account/meldingen,          roles: ROLE_NEWS_SUBSCRIBER }
    - { path: ^/api/nieuwsoverzicht/push,   roles: PUBLIC_ACCESS }   # bewust: auth via Bearer-token in de controller, niet via de sessie-firewall (aanroeper is de scheduled task, geen ingelogde browser)
```

`AdminUserController::ASSIGNABLE_ROLES` krijgt er één regel bij:

```php
'ROLE_NEWS_SUBSCRIBER' => 'Nieuwsmeldingen (push)',
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
  (`openssl rand -hex 32`)
- `docker-compose.yml`, `app`-service `environment:`-blok:
  ```yaml
  VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:-}
  VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:-}
  NEWS_DIGEST_WEBHOOK_TOKEN: ${NEWS_DIGEST_WEBHOOK_TOKEN:-}
  ```
- `app/.env` (defaults) + `config/services.yaml` parameters die naar
  `%env(VAPID_PUBLIC_KEY)%` etc. verwijzen, zodat services en Twig
  (`vapid_public_key` global) ze kunnen gebruiken.

Voeg de drie secrets ook toe aan de checklist in `docs/deployment.md` §4/§7.1
en aan de GitHub Actions secrets-tabel (§ "Secrets toevoegen"), als die
uiteindelijk via CI wordt aangemaakt/geroteerd.

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

### 5.1 Zelf-abonneren (ingelogde gebruiker, `ROLE_NEWS_SUBSCRIBER`)

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

### 5.2 Binnenkomend webhook (machine-naar-machine, geen sessie)

`src/Controller/NewsDigestWebhookController.php`:

| Route | Methode | Doel |
|-------|---------|------|
| `/api/nieuwsoverzicht/push` | POST | Body: `{title, body, url?}`. Header `Authorization: Bearer <NEWS_DIGEST_WEBHOOK_TOKEN>`, vergeleken met `hash_equals()` (timing-safe). Bij mismatch: `403` zonder verdere verwerking. |

**`url`-validatie (verplicht, geen losse vervolgstap):** vóórdat de
`NewsDigest`-rij wordt opgeslagen of er iets gedispatcht wordt, wordt `url`
gecontroleerd op een relatief, same-origin pad. Alles anders wordt verworpen
met `422`, niet stilzwijgend genegeerd of ongevalideerd doorgezet:

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

Bij een ongeldige `url` faalt het hele verzoek (`422`) — er wordt geen
`NewsDigest` met een afgekeurde of leeggemaakte `url` opgeslagen, zodat de
scheduled task de fout direct terugziet in plaats van dat de melding stilzwijgend
zonder klikbare link verstuurd wordt.

Bij een geldig verzoek: slaat een `NewsDigest`-rij op, dispatcht daarna een
Messenger-bericht `SendNewsDigestPush` (huidige `sync://`-transport is
voldoende bij een handvol abonnees per dag; als het aantal groeit is dit later
zonder controller-wijziging naar `async` te verplaatsen — alleen
`messenger.yaml`-routing verandert dan).

`SendNewsDigestPushHandler` haalt alle `PushSubscription`s op van gebruikers
met `ROLE_NEWS_SUBSCRIBER`, stuurt de melding via `minishlink/web-push`, en
verwerkt per subscription het rapport: `410 Gone`/`404 Not Found` → subscription
verwijderen; overig succes/falen → tellers op de `NewsDigest`-rij bijwerken.

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
3. Vraagt toestemming (`Notification.requestPermission()`) — alleen op
   expliciete klik van de gebruiker (browsers blokkeren dit bij
   page-load-aanvragen).
4. Abonneert: `registration.pushManager.subscribe({ userVisibleOnly: true,
   applicationServerKey: <VAPID public key, base64 → Uint8Array> })`.
5. Post het resultaat naar `/account/meldingen/abonneren`.
6. Bij uitzetten: `subscription.unsubscribe()` in de browser + POST naar
   `/account/meldingen/opzeggen`.

De VAPID-publieke sleutel komt als Twig-global (`vapid_public_key`) op de
pagina, in een `data-push-subscribe-vapid-key-value`-attribuut — zelfde
Stimulus-value-conventie als de bestaande controllers (bv.
`strongs_select_controller.js`).

### 6.3 Navigatie

Eén link "Meldingen" toevoegen aan de bestaande navigatie
(`templates/base.html.twig` / `nav_panel_controller.js`), zichtbaar zodra
`is_granted('ROLE_NEWS_SUBSCRIBER')`.

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
| Hoog   | 2 (✅ beide verwerkt in het plan) |
| Medium | 3      |
| Laag   | 2      |
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

Een gebruiker met `ROLE_NEWS_SUBSCRIBER` kon een willekeurige `endpoint`-string
meesturen; zonder expliciete check verwijderde dit elke `PushSubscription`-rij
met die endpoint, ongeacht van wie — een IDOR waarmee elke abonnee het
abonnement van een andere abonnee kon opzeggen. §5.1 scopet abonneren én
opzeggen nu altijd op `(endpoint, user = huidige gebruiker)`; een niet-match
bij opzeggen doet stil niets (geen foutmelding die verklapt of de endpoint bij
iemand anders hoorde). Dit vereiste ook een aanpassing van de unique-
constraint op `PushSubscription` (§1): niet los op `endpoint`, maar
samengesteld op `(user, endpoint)`.

### MEDIUM — Geen rate limiting of kill switch op de webhook, los van het token

**Locatie:** §5.2

Als `NEWS_DIGEST_WEBHOOK_TOKEN` ooit lekt, is de enige stop een handmatige
rotatie + herdeploy. `symfony/rate-limiter` staat al in `composer.json` (nu
gebruikt voor `login_throttling`) — hergebruik dat voor deze route. Overweeg
daarnaast een losse, snel om te zetten instelling (env-var of DB-vlag) die
verzending direct pauzeert zonder dat het token hoeft te roteren.

### MEDIUM — Geen replay-/idempotentiebescherming op de webhook

**Locatie:** §5.2

Een netwerkretry vanuit de scheduled task (of een afgevangen en herhaald
verzoek binnen de levensduur van het token) stuurt dezelfde melding nogmaals
naar alle abonnees. **Fix:** laat de aanroeper een idempotentiesleutel
meesturen (bv. hash van titel+datum) en negeer een tweede verzoek met dezelfde
sleutel binnen een tijdvenster, vóór het dispatchen van
`SendNewsDigestPush`.

### MEDIUM — Impact van VAPID-sleutelrotatie niet uitgewerkt

**Locatie:** §3

Bij een vermoede lek van `VAPID_PRIVATE_KEY` is roteren de enige optie, maar
dat maakt **alle** bestaande browserabonnementen in één keer ongeldig zonder
zichtbare foutmelding voor de gebruiker (de browser blijft "geabonneerd"
denken; verzending faalt stil). Neem dit expliciet op in de
secret-rotatiechecklist van `docs/deployment.md` (zoals nu al voor
`APP_SECRET`), inclusief hoe gebruikers merken dat ze opnieuw moeten
abonneren (bv. periodieke check op `/account/meldingen` die een
verlopen/ongeldige subscription detecteert en de toggle terugzet naar "uit").

### LAAG — Geen cap of formaatvalidatie op nieuwe `PushSubscription`-rijen

**Locatie:** §5.1

Beperkt risico omdat de route al `ROLE_NEWS_SUBSCRIBER` vereist, maar niets
weerhoudt zo'n account ervan herhaaldelijk te posten met verzonnen
`endpoint`/`p256dh`/`auth`-waarden. Voeg een basisvalidatie toe (verwachte
lengte/base64url-vorm) en een redelijk maximum aantal actieve subscriptions
per gebruiker (bv. 5, voor meerdere apparaten).

### LAAG — `NewsDigest.title`/`body` zijn extern aangeleverde, ongefilterde tekst

**Locatie:** §1, §5.2

Bij het bouwen van een `/admin`-historieoverzicht: vertrouw op Twig's
standaard auto-escaping en gebruik nooit `|raw` op deze velden — de inhoud
komt van een systeem buiten deze applicatie en moet als niet-vertrouwd worden
behandeld, ook al is XSS via de OS-notificatie zelf niet mogelijk (die toont
altijd platte tekst).

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

1. Migratie + entities `PushSubscription`, `NewsDigest`.
2. Rol `ROLE_NEWS_SUBSCRIBER` in `security.yaml` + `AdminUserController`.
3. `composer require minishlink/web-push`, VAPID-sleutels genereren, env-vars
   wiren (root `.env.local`, `docker-compose.yml`, `config/services.yaml`).
4. `NewsDigestWebhookController` + `SendNewsDigestPushHandler` (Messenger),
   inclusief de `url`-validatie uit §5.2 (al onderdeel van het endpoint-
   ontwerp, geen losse vervolgstap) — vul hierbij nog de openstaande
   MEDIUM-bevindingen uit §8 aan: rate limiting via `symfony/rate-limiter`
   en de idempotentiesleutel-check.
5. `PushSubscriptionController` + `/account/meldingen`-pagina, inclusief de
   eigenaarscontrole uit §5.1 (al onderdeel van het endpoint-ontwerp) — vul
   hierbij de LAAG-bevinding uit §8 aan: cap + formaatvalidatie op nieuwe
   subscriptions.
6. `public/sw.js` + `push_subscribe_controller.js` + navigatielink.
7. Handmatig testen: eigen account de rol geven, abonneren in de browser,
   `curl` naar `/api/nieuwsoverzicht/push` met testtoken, melding checken;
   expliciet ook testen dat een tweede identiek verzoek niet dubbel verstuurt
   en dat opzeggen met andermans endpoint niets doet.
8. Scheduled-task-config (buiten deze repo) uitbreiden met de HTTP-POST-actie.
9. VAPID- en webhook-token-rotatie toevoegen aan de secret-checklist in
   `docs/deployment.md` (§8, MEDIUM-bevinding).
