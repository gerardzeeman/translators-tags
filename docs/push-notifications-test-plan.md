# Push-meldingen — testplan

Status: **klaar om uit te voeren**
Branch: `feat/push-notifications`
Hoort bij: [`push-notifications-plan.md`](push-notifications-plan.md)

Dit testplan verifieert de implementatie op `feat/push-notifications`. Deel 1
is al door mij uitgevoerd (via `curl` en een gesandboxte testbrowser) en
hoeft niet herhaald te worden. Deel 2 vereist een **echte browser met een
secure context** (HTTPS, of een browser die `http://localhost` als
zodanig erkent) — dat kon mijn testomgeving niet bieden, dus dit is voor
jou om uit te voeren.

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

---

## Deel 2 — handmatig, in een echte browser

### A. Abonneren (happy path)

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

- [ ] Verstuur een testoverzicht naar de webhook:
      ```bash
      curl -sk -X POST https://localhost/api/nieuwsoverzicht/push \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer <NEWS_DIGEST_WEBHOOK_TOKEN uit .env.local>" \
        -d '{"title":"Testoverzicht","body":"Handmatige testmelding.","url":"/blog","idempotency_key":"handtest-1"}'
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

- [ ] Zet de checkbox op `/profile` weer uit.
- [ ] Controleer dat de rij uit `push_subscriptions` verdwenen is.
- [ ] Verstuur nogmaals een testoverzicht (nieuwe `idempotency_key`) → geen
      melding meer voor dit account (`success_count`/`failure_count` blijven
      `0` als er verder niemand anders geabonneerd is).

### D. Rolgating (incl. rolhiërarchie)

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

- [ ] `/admin/nieuwsoverzicht` (als `ROLE_ADMIN`) toont alle verstuurde
      overzichten met kloppende `success_count`/`failure_count`.
- [ ] Verstuur een testoverzicht met een titel die HTML bevat, bv.
      `"title": "<script>alert(1)</script>"` → op de historiepagina wordt de
      tekst **letterlijk getoond** (geëscaped door Twig), er verschijnt geen
      JavaScript-alert. Dit bevestigt dat `title`/`body`/`url` als
      niet-vertrouwde tekst behandeld worden (plan §5.3/§8, laag-bevinding).

### H. Cross-browser (voor zover relevant/beschikbaar)

- [ ] Chrome/Edge desktop — volledige ondersteuning verwacht.
- [ ] Firefox desktop — volledige ondersteuning verwacht.
- [ ] Android Chrome — volledige ondersteuning verwacht.
- [ ] iOS Safari 16.4+ — vereist eerst "Zet op beginscherm"; pas daarna is
      Web Push beschikbaar. Zonder dat: de "niet ondersteund"-melding hoort
      te verschijnen, geen kapotte toggle.
- [ ] Een browser zonder Push-ondersteuning (of privé-venster met beperkte
      API's) → de "Pushmeldingen worden niet ondersteund door deze
      browser"-melding verschijnt, de checkbox is uitgeschakeld, geen
      onbehandelde JS-fout in de console.

---

## Bekende beperking van mijn eigen verificatie

Mijn gesandboxte testbrowser kon `navigator.serviceWorker.register()` niet
succesvol uitvoeren op `http://localhost` (geen erkende secure context) en
kon de zelfondertekende certificaatwaarschuwing op `https://localhost` niet
wegklikken. Deel 2 hierboven is dus door niemand nog echt bevestigd — dat is
precies waarom dit testplan er is.
