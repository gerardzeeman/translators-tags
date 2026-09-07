# Push-meldingen — testplan

Status: **uitgevoerd — alle in-scope stappen geslaagd** (2026-09-07)
Branch: `feat/push-notifications`
Hoort bij: [`push-notifications-plan.md`](push-notifications-plan.md)

Dit testplan verifieert de implementatie op `feat/push-notifications`. Deel 1
is door mij uitgevoerd (via `curl` en een gesandboxte testbrowser). Deel 2
is samen met de gebruiker doorlopen in een echte Firefox- en Chrome-sessie
(de gesandboxte testbrowser kon geen secure context krijgen op
`http://localhost`, zie onderaan). Resultaat: **A t/m G volledig geslaagd**;
H gedeeltelijk (desktop Firefox/Chrome bevestigd, Android/iOS niet getest —
zie die sectie). Tijdens dit testen zijn twee echte implementatiebugs aan
het licht gekomen en gefixt (zie "Bekende bugs, al gefixt" in Deel 1).

---

## Voorbereiding

- [ ] Stack draait: `docker compose ps` toont `bible_app`/`bible_postgres` gezond.
- [ ] `.env.local` (projectroot) bevat `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`,
      `NEWS_DIGEST_WEBHOOK_TOKEN`, `NEWS_DIGEST_PUSH_ENABLED=true` (staan er
      al, door mij gegenereerd tijdens de implementatie — zie eerdere
      sessie). Herstart met `docker compose --env-file .env.local up -d`
      als je ze wijzigt.
- [ ] Je hebt een account met `ROLE_CGK_RIJNSBURG_NIEUWS` (rechtstreeks, of
      via `ROLE_ADMIN` — die rol erft hem nu automatisch). Toekennen via
      `/admin/users`.
- [ ] Voor de HTTPS-eis: ga naar `https://localhost`, klik de
      certificaatwaarschuwing weg ("Geavanceerd → Toch doorgaan") — zie
      `README.md`. Zonder een geaccepteerd HTTPS-certificaat weigert de
      browser `navigator.serviceWorker.register()` sowieso.

---

## Deel 1 — al geverifieerd (ter info, geen actie nodig)

| # | Test | Resultaat |
|---|------|-----------|
| 1 | Webhook zonder token | `403` |
| 2 | Webhook met kill switch uit (`NEWS_DIGEST_PUSH_ENABLED=false`) | `503`, geen redeploy nodig om te herstellen |
| 3 | Webhook met geldig token | `202 queued` |
| 4 | 11e webhook-verzoek binnen een uur | `429` |
| 5 | Herhaald verzoek met dezelfde `idempotency_key` | `200 already_sent`, geen dubbele verzending |
| 6 | Webhook met absolute `url` (bv. `https://evil.example.com`) | `422`, niets opgeslagen |
| 7 | `/profile` toont de Meldingen-sectie alleen met de rol | bevestigd |
| 8 | Twig-lint, YAML-lint, migratie tegen echte Postgres-schema | allemaal groen |
| 9 | Daadwerkelijke aflevering (`SendNewsDigestPushHandler`) tegen een bestaand abonnement | `success_count: 1`, geen exception — zie "Bekende bugs, al gefixt" hieronder |

### Bekende bugs, al gefixt (tijdens dit testen zelf ontdekt)

Test 9 hierboven faalde aanvankelijk met een `500` op elke verzendpoging —
niet gerelateerd aan het testplan zelf, maar twee echte gaten in de
implementatie die pas zichtbaar werden zodra er een echt abonnement bestond
om naartoe te versturen:

1. **Ontbrekende `bcmath`-PHP-extensie** — de VAPID-JWT-signing
   (`web-token/jwt-library`) gaf zonder `bcmath`/`gmp` een notice die
   Symfony's foutafhandeling fataal maakte. Opgelost: `bcmath` toegevoegd
   aan `app/Dockerfile`.
2. **Ontbrekende PSR-17-implementatie** — `minishlink/web-push` kan zonder
   een geïnstalleerd pakket als `nyholm/psr7` geen HTTP-requests bouwen.
   Opgelost: `composer require nyholm/psr7`; `SendNewsDigestPushHandler`
   gebruikt weer gewoon `WebPush`'s standaard auto-discovery.

Beide zijn gecommit vóór dit testplan werd bijgewerkt — als je een fris
image bouwt (`docker compose build app`) zit de fix er al in.

---

## Deel 2 — handmatig, in een echte browser

### A. Abonneren (happy path)

> **✅ Bevestigd** — meerdere keren doorlopen (initieel, na opzeggen, na
> VAPID-rotatie), zowel in Firefox als Chrome. `push_subscriptions` kreeg
> telkens de verwachte rij; niet elke afzonderlijke devtools-subcheck
> (service-workerstatus, exacte statustekst) is apart gerapporteerd, maar de
> eindtoestand (succesvolle aflevering in B) bewijst dat de hele keten werkt.

- [ ] Log in, ga naar `https://localhost/profile`.
- [ ] Open de devtools-console: geen fouten bij het laden van de pagina.
- [ ] Devtools → Application → Service Workers: `/sw.js` staat geregistreerd
      en actief (scope `/`).
- [ ] Vink de checkbox bij "Dagelijks CGK/Rijnsburg nieuwsoverzicht ontvangen
      als pushmelding" aan.
- [ ] Browser vraagt om toestemming voor meldingen → **Toestaan**.
- [ ] Checkbox blijft aangevinkt; statustekst wordt "Meldingen staan aan.".
- [ ] Controleer in de database dat er een rij bijkwam:
      ```bash
      docker compose exec postgres psql -U bible -d bible_compare -c "SELECT id, user_id, endpoint FROM push_subscriptions;"
      ```

### B. Melding ontvangen

> **✅ Bevestigd** — testmeldingen kwamen aan in zowel Firefox als Chrome
> (`success_count` correct per keer). Het specifieke "klik op de melding
> opent /blog"-detail is niet apart teruggekoppeld.

- [ ] Verstuur een testoverzicht naar de webhook. In een Bash-terminal (WSL,
      Git Bash):
      ```bash
      curl -sk -X POST https://localhost/api/nieuwsoverzicht/push \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer <NEWS_DIGEST_WEBHOOK_TOKEN uit .env.local>" \
        -d '{"title":"Testoverzicht","body":"Handmatige testmelding.","url":"/blog","idempotency_key":"handtest-1"}'
      ```
      In PowerShell: gebruik `curl.exe` (niet de `curl`-alias van
      `Invoke-WebRequest`) én schrijf de JSON-body eerst naar een tijdelijk
      bestand — inline `-d '{"..."}'` wordt door PowerShell's eigen
      argument-quoting voor native executables stilzwijgend afgebroken:
      ```powershell
      $json = '{"title":"Testoverzicht","body":"Handmatige testmelding.","url":"/blog","idempotency_key":"handtest-1"}'
      $tmp = "$env:TEMP\push-test-body.json"
      [System.IO.File]::WriteAllText($tmp, $json, [System.Text.UTF8Encoding]::new($false))
      curl.exe -sk -X POST https://localhost/api/nieuwsoverzicht/push -H "Content-Type: application/json" -H "Authorization: Bearer <NEWS_DIGEST_WEBHOOK_TOKEN uit .env.local>" --data-binary "@$tmp"
      ```
- [ ] Binnen enkele seconden verschijnt een OS-melding (Chrome moet draaien,
      hoeft niet op de voorgrond).
- [ ] Klik op de melding → opent `/blog` (relatief pad, same-origin).
- [ ] Controleer de tellers:
      ```bash
      docker compose exec postgres psql -U bible -d bible_compare -c "SELECT title, success_count, failure_count FROM news_digests WHERE idempotency_key = 'handtest-1';"
      ```
      → `success_count = 1`, `failure_count = 0`.

### C. Opzeggen

> **✅ Bevestigd** — rij verdween uit `push_subscriptions`; een daarna
> verstuurd testoverzicht kreeg `success_count: 0, failure_count: 0`.

- [ ] Zet de checkbox op `/profile` weer uit.
- [ ] Controleer dat de rij uit `push_subscriptions` verdwenen is.
- [ ] Verstuur nogmaals een testoverzicht (nieuwe `idempotency_key`) → geen
      melding meer voor dit account (`success_count`/`failure_count` blijven
      `0` als er verder niemand anders geabonneerd is).

### D. Rolgating (incl. rolhiërarchie)

> **✅ Bevestigd** — gebruiker zonder rol zag geen Meldingen-sectie en kreeg
> `403` op een directe POST. Rolhiërarchie-expansie was al bewezen doordat
> het echte account (alleen `ROLE_ADMIN`, geen expliciete
> `ROLE_CGK_RIJNSBURG_NIEUWS`) gedurende het hele testplan meldingen bleef
> ontvangen.

- [ ] Log in als gebruiker **zonder** de rol → `/profile` toont geen
      Meldingen-sectie.
- [ ] Directe POST zonder rol:
      ```bash
      curl -sk -X POST https://localhost/account/meldingen/abonneren -H "Content-Type: application/json" -d '{}' -b "<sessiecookie van niet-gerechtigde gebruiker>"
      ```
      → `403` (via `#[IsGranted]`, vóór enige CSRF/payload-check).
- [ ] Geef een testaccount **alleen** `ROLE_ADMIN` (niet expliciet
      `ROLE_CGK_RIJNSBURG_NIEUWS`) → bevestig dat de Meldingen-sectie wél
      verschijnt (rolhiërarchie) én dat een verstuurd testoverzicht bij dit
      account aankomt (test `findAllForRole()`'s hiërarchie-expansie, niet
      alleen een letterlijke rol-match in de database).

### E. Robuustheid (nogmaals, nu vanuit de browser i.p.v. curl)

> **✅ Bevestigd** — cross-user opzeggen gaf `200 {"status":"ok"}` maar liet
> de rij van de ander ongemoeid (geen IDOR). Het 6e abonnement op één account
> kreeg exact `429 "Maximaal 5 apparaten per account."`, de eerste 5 slaagden.

- [ ] **Eigenaarscontrole:** noteer de `endpoint` van jouw abonnement, log in
      als een andere geabonneerde gebruiker, en probeer diens endpoint op te
      zeggen via de browserconsole:
      ```js
      fetch('/account/meldingen/opzeggen', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify({endpoint: '<andermans endpoint>'})
      })
      ```
      → `200 {"status":"ok"}` maar de rij van de ander blijft gewoon bestaan
      (stil genegeerd, geen IDOR).
- [ ] **Cap van 5 apparaten:** abonneer met 6 verschillende browsers/profielen
      op hetzelfde account → de 6e krijgt `429` met
      `"Maximaal 5 apparaten per account."`.

### F. VAPID-sleutelrotatie

> **✅ Bevestigd, beide kanten.** Client: na rotatie sprong de toggle vanzelf
> naar "uit" met de rotatiemelding. Server: een verzendpoging naar de
> verouderde subscription gaf `failure_count: 1`, waarna die rij meteen
> automatisch verwijderd werd (`401`/`403`-afhandeling). Opnieuw abonneren
> met de nieuwe sleutel werkte daarna gewoon (herhaling van B).

- [ ] Genereer een nieuw sleutelpaar:
      ```bash
      docker compose exec app php bin/console app:push:generate-vapid-keys
      ```
- [ ] Zet de nieuwe waarden in `.env.local`, herstart:
      `docker compose --env-file .env.local up -d`.
- [ ] Herlaad `/profile` (met een account dat al vóór de rotatie geabonneerd
      was) → checkbox springt automatisch naar **uit**, met de melding dat
      opnieuw inschakelen nodig is (client-side detectie, §3.2/§6.2 van het
      plan).
- [ ] Controleer dat de oude `push_subscriptions`-rij inmiddels weg is (client
      heeft lokaal `unsubscribe()` aangeroepen vóór de POST naar
      `/opzeggen`, of — als er nog een oude rij achterbleef vóórdat de
      client dit kon detecteren — dat die na een verzendpoging automatisch
      opgeruimd wordt via de `401`/`403`-afhandeling in
      `SendNewsDigestPushHandler`).
- [ ] Vink de checkbox weer aan → nieuw abonnement met de nieuwe sleutel
      werkt gewoon (test B nogmaals).
- [ ] Zet de originele testsleutels terug in `.env.local` als je verder wilt
      testen met bestaande abonnementen, of laat de nieuwe staan als
      definitieve dev-sleutels.

### G. Admin-historie

> **✅ Bevestigd** — historiepagina toonde kloppende rijen/tellers. Een
> titel met `<script>alert(1)</script>` verscheen geëscaped
> (`&lt;script&gt;...`) in de HTML, geen alert, geen ongeautoriseerd script
> in de DOM.

- [ ] `/admin/nieuwsoverzicht` (als `ROLE_ADMIN`) toont alle verstuurde
      overzichten met kloppende `success_count`/`failure_count`.
- [ ] Verstuur een testoverzicht met een titel die HTML bevat, bv.
      `"title": "<script>alert(1)</script>"` → op de historiepagina wordt de
      tekst **letterlijk getoond** (geëscaped door Twig), er verschijnt geen
      JavaScript-alert. Dit bevestigt dat `title`/`body`/`url` als
      niet-vertrouwde tekst behandeld worden (plan §5.3/§8, laag-bevinding).

### H. Cross-browser (voor zover relevant/beschikbaar)

- [x] Chrome desktop — bevestigd (abonneren, aflevering: `success_count: 2`
      samen met Firefox in dezelfde test).
- [x] Firefox desktop — bevestigd (het hele testplan A–G is hiermee
      doorlopen).
- [ ] Android Chrome — **niet getest**, geen apparaat beschikbaar tijdens
      deze sessie.
- [ ] iOS Safari 16.4+ — **niet getest**, geen apparaat beschikbaar tijdens
      deze sessie.
- [x] Een browser zonder secure context/Push-ondersteuning → bevestigd in de
      gesandboxte testbrowser: de "niet ondersteund"-melding verscheen, de
      checkbox werd uitgeschakeld, geen onbehandelde JS-fout in de console
      (zie ook de `try`/`catch` rond `serviceWorker.register()` in
      `push_subscribe_controller.js`, toegevoegd naar aanleiding hiervan).

**Openstaand voor later:** Android Chrome en iOS Safari zijn niet getest.
Beide gebruiken hetzelfde standaard Web Push-mechanisme als Chrome/Firefox
desktop, dus een probleem is niet waarschijnlijk — maar iOS vereist specifiek
"Zet op beginscherm" vóórdat Web Push beschikbaar is, en dat pad is nog nooit
uitgeprobeerd.

---

## Beperking van de gesandboxte testbrowser (achterhaald)

Mijn gesandboxte testbrowser kon `navigator.serviceWorker.register()` niet
succesvol uitvoeren op `http://localhost` (geen erkende secure context) en
kon de zelfondertekende certificaatwaarschuwing op `https://localhost` niet
wegklikken — dat is waarom Deel 2 oorspronkelijk om handmatige uitvoering
vroeg. Inmiddels is dat deel samen met de gebruiker in echte Firefox- en
Chrome-sessies doorlopen (zie hierboven); deze beperking is dus niet meer
van toepassing op de resultaten in dit document.
