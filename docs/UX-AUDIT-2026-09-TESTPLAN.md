# Testplan — UX-verbeteringen Alef–Omega

**Bij:** [`UX-AUDIT-2026-09.md`](UX-AUDIT-2026-09.md) en [`UX-AUDIT-2026-09-MOBIEL.md`](UX-AUDIT-2026-09-MOBIEL.md)
**Datum:** 2026-09-09, aangevuld 2026-09-10 met de drie kritieke mobiele fixes
**Doel:** Elke doorgevoerde fix functioneel en visueel verifiëren vóór deploy naar productie, en vaststellen dat er geen regressie is opgetreden in de bestaande koppel- en leesflows.

---

## 1. Voorbereiding

### 1.1 Test-accounts

Maak (of gebruik) minimaal deze accounts via `/admin/users`:

| Account | Rollen | Waarvoor nodig |
|---|---|---|
| `anon` | — (niet ingelogd) | Homepage-CTA, publieke leesweergave |
| `viewer` | `ROLE_USER` (standaard, geen extra) | Basale leesweergave zonder linker-indicatoren |
| `linker` | `ROLE_LINKER`, `ROLE_VIEWER_HSV`, `ROLE_VIEWER_SVGBS`, `ROLE_VIEWER_SV1657` | Koppelinterface, alle vertaalkolommen, tooltips/kleurcodering |
| `admin` | `ROLE_ADMIN` (+ bovenstaande) | Gebruikersbeheer, verwijder-dialoog |

### 1.2 Tools

- **Browser devtools** (Chrome/Firefox/Edge) — Elements-paneel, Network-tab, mobiele emulatie (responsive mode).
- **Toetsenbord alleen** — muis loskoppelen of gewoon niet gebruiken; navigeren met Tab/Shift+Tab/Enter/Spatie/Escape.
- **Schermlezer** — NVDA (Windows, gratis) of VoiceOver (macOS: Cmd+F5).
- **Contrastchecker** — browser devtools contrast-indicator bij het kleurenkiezer-icoon, of WebAIM Contrast Checker.
- **axe DevTools** (browserextensie) — automatische accessibility-scan per pagina, optioneel maar aanbevolen.
- Minimaal 2 browsers (Chrome + Firefox of Safari) i.v.m. het nieuwe native `<dialog>`-element.
- Een echt mobiel toestel (of Chrome DevTools touch-emulatie) voor de tik-doel- en tooltip-tests.

### 1.3 Testdata

Kies vooraf een vers met:
- Meerdere Hebreeuwse/Griekse woorden met **meerdere** koppelingen per woord (voor de "×"-verwijdertest).
- Minstens één woord zonder koppeling (voor "—"-badge/contrast-test).
- Minstens één **voorstel** (propagated/`↝ voorstel`-label) voor de bulk-bevestigingstest — bijv. een nog niet volledig handmatig gecontroleerd hoofdstuk.

---

## 2. Algemene regressietest (eerst uitvoeren)

Doel: bevestigen dat de bestaande koppelflow niet is stukgegaan door de fixes.

| # | Stap | Verwacht resultaat |
|---|---|---|
| R1 | Log in als `linker`, ga naar `/link/passage/SV/<boek>/<hfst>/<vers>` | Pagina laadt, bron- en Nederlandse kolom tonen woorden |
| R2 | Klik op een bronwoord | Woord krijgt actieve rand, actiebalk verschijnt onderaan, Strong's-paneel + "Alle links"-paneel laden |
| R3 | Klik een Nederlands woord | Woord krijgt gele selectie-highlight |
| R4 | Klik "✓ Koppeling opslaan" | Statusregel toont "✓ … opgeslagen", **geen** volledige witte flits/reload zichtbaar; pagina ververst na ~0,6s vloeiend (via Turbo) |
| R5 | Herhaal R1–R4 op de Strong's-koppelpagina (`/link/strongs/SV/<strongs>`) | Zelfde gedrag; na opslaan wordt alleen het versblok + voortgangsbalk bijgewerkt, geen page-reload |
| R6 | Ga naar de bijbellezer `/boek/<usfm>/<hfst>` als `linker` | Klik op een grondtekstwoord → gekoppelde woorden in NL-kolommen lichten op (bestaande hover/klik-highlight-functie) |

Als een van deze stappen afwijkt van het bestaande gedrag (vóór de fixes), eerst dit oplossen voordat de onderstaande gerichte tests worden gedaan.

---

## 3. Testgevallen per bevinding

### Ernst 4 — Catastrofaal

#### T1 — Toetsenbord-toegankelijkheid kernwoorden
*Bevinding 1, principe Accessibility/Affordances*

| Stap | Verwacht resultaat |
|---|---|
| Ga (uitgelogd of als `viewer`) naar de leesweergave van een hoofdstuk. Klik ergens neutraal op de pagina, gebruik dan alleen Tab. | Focus doorloopt op enig moment de grondtekstwoorden (`.source-word`); elk gefocust woord toont een duidelijke gekleurde outline. |
| Druk op **Enter** op een gefocust grondtekstwoord. | Hetzelfde gebeurt als bij een muisklik (gekoppelde woorden lichten op / highlight-event). Pagina scrollt niet mee. |
| Druk op **Spatie** op een gefocust grondtekstwoord. | Zelfde resultaat als Enter. **Pagina scrollt niet naar beneden** (dit was het risico van Spatie op een niet-native knop). |
| Herhaal met een Nederlands woord (`.dutch-word`) — alleen zichtbaar/interactief als `linker` op de leespagina. | Zelfde: focus-outline zichtbaar, Enter/Spatie triggert highlight, geen scroll. |
| Log in als `linker`, ga naar de koppelpagina (`/link/passage/...`). Tab naar een bronwoord (`.src-word`). | Focus-outline zichtbaar; `aria-pressed="false"` in de Elements-inspector. |
| Druk Enter op het bronwoord. | Woord wordt geselecteerd (actiebalk verschijnt), `aria-pressed` wordt `"true"` in devtools. Druk nogmaals Enter → deselecteert, `aria-pressed` terug naar `"false"`. |
| Tab verder naar een Nederlands woord (`.nl-word`) terwijl een bronwoord geselecteerd is, druk Spatie. | Woord krijgt gele selectie, geen paginascroll, `aria-pressed="true"`. |
| **Schermlezer-check:** activeer NVDA/VoiceOver, navigeer met Tab over een bronwoord. | Schermlezer kondigt "knop" (of "button") aan bij elk woord — niet stil zoals vóór de fix. |

#### T2 — Tooltip-informatie bereikbaar via toetsenbord/tik
*Bevinding 2, principe Recognition over Recall/Accessibility*

| Stap | Verwacht resultaat |
|---|---|
| Als `linker`, op de leespagina, Tab naar een `.link-dot` (methode-stipje bij een gekoppeld woord). | Een donker tooltip-blokje verschijnt boven het stipje met dezelfde tekst als voorheen alleen bij hover (bijv. "manual: 100%"). |
| Tab verder naar een `.no-link-badge` ("—"). | Tooltip "Geen koppeling SV" verschijnt bij focus. |
| **Mobiel/touch-emulatie:** open de leespagina in Chrome DevTools met touch-emulatie aan, tik op een `.link-dot`. | Element krijgt focus bij tik, tooltip verschijnt — dit werkte vóór de fix helemaal niet op touch. |
| Op de koppelpagina, Tab naar een `.link-chip` ("×"). | Tooltip toont "methode: score% — klik om te verwijderen". |

---

### Ernst 3 — Groot

#### T3 — "×"-knop verwijdert daadwerkelijk één koppeling
*Bevinding 3, principe User Control/Affordances*

| Stap | Verwacht resultaat |
|---|---|
| Als `linker`, open een vers met een bronwoord dat **meerdere** koppelingen heeft. | Bronwoord toont meerdere "×"-chips in `.src-links`. |
| Klik op één specifieke "×"-chip (niet op de rest van het woord). | **Alleen die ene koppeling verdwijnt** (chip verdwijnt direct); het bronwoord wordt *niet* volledig geselecteerd/gedeselecteerd. Statusregel toont "✓ Koppeling verwijderd." |
| Controleer in het Network-paneel tijdens de klik. | Een `DELETE`-request naar `/link/api/delete/<linkId>` met status 200/204. |
| Herhaal op de Strong's-koppelpagina (`_strongs_verse_block.html.twig`). | Zelfde gedrag; na verwijderen wordt alleen het versblok + voortgangsbalk ververst (geen page-reload). |
| Druk **Enter** op een "×"-chip (toetsenbord). | Zelfde verwijdergedrag als bij klik. |
| Verwijder de koppeling opnieuw wanneer er nog maar 1 koppeling over is, ververs de pagina. | Koppeling is blijvend verwijderd in de database (niet alleen visueel verdwenen). |

#### T4 — Contrast lichte modus
*Bevinding 4, principe Accessibility*

| Stap | Verwacht resultaat |
|---|---|
| Open de leespagina in lichte modus. Selecteer met de contrastchecker (devtools) de kleur van een transliteratie (`.word-translit`) of "—"-badge tegen de achtergrond. | Contrastratio ≥ 4,5:1 (devtools toont een groen vinkje/"AA" bij de kleurkiezer, geen rode waarschuwing meer). |
| Run axe DevTools (of Lighthouse Accessibility-audit) op de leespagina en de koppelpagina in lichte modus. | Geen "insufficient color contrast"-bevindingen meer op elementen met `--color-ink-muted` of `--color-none`. |
| Schakel naar donkere modus (thema-knop rechtsboven). | Contrast blijft ongewijzigd goed (donkere modus was al gecontroleerd en is niet aangepast). |

#### T5 — Geen volledige page-reload na opslaan
*Bevinding 5, principe Visibility of System Status*

| Stap | Verwacht resultaat |
|---|---|
| Open Network-tab, filter op "Doc"/alle requests. Sla een koppeling op via de koppelpagina. | Na de save-request volgt een Turbo-visit (fetch/XHR-achtige requests, **geen** volledige document-navigatie met herladen van `app.css`/webfonts). |
| Scroll eerst wat naar beneden op de pagina, sla dan een koppeling op. | Geen zichtbare witte flits; de overgang oogt vloeiender dan een harde reload. (Exacte scroll-behoud is niet gegarandeerd, maar de flits moet weg zijn.) |
| Test met JavaScript-devtools: zet een breakpoint of console.log op `window.Turbo` vóór het opslaan. | `window.Turbo` bestaat (bevestigt dat de Turbo-visit-tak wordt gebruikt, niet de `window.location.reload()`-fallback). |
| **Fallback-scenario (optioneel, hoog niveau):** simuleer dat Turbo niet geladen is (bijv. via devtools `window.Turbo = undefined` in de console vóór het opslaan). | Pagina valt terug op een gewone `location.reload()` — geen JS-fout, geen vastgelopen status. |

#### T6 — Opslaan-knop uitgeschakeld tijdens verzoek
*Bevinding 6, principe Error Prevention*

| Stap | Verwacht resultaat |
|---|---|
| Zet in devtools Network-tab de snelheid op "Slow 3G". Selecteer een koppeling, klik "✓ Koppeling opslaan" **twee keer snel achter elkaar**. | Knop wordt direct na de eerste klik grijs/`disabled` (devtools: `disabled`-attribuut aanwezig); slechts **één** save-request in het Network-paneel. |
| Forceer een foutresponse (bijv. door de save-URL tijdelijk te blokkeren via devtools "Block request URL"). | Statusregel toont de vaste foutmelding (zie T10); de knop wordt weer **enabled** zodat de gebruiker opnieuw kan proberen. |

#### T7 — Login/registratie gebruiken gedeelde CSS
*Bevinding 7, principe Consistency*

| Stap | Verwacht resultaat |
|---|---|
| Open `/login` en `/register` naast elkaar (twee tabbladen). | Visueel identiek qua kaartbreedte, kleuren, typografie (voorheen 420px vs. 440px kaartbreedte-verschil, nu gelijk). |
| Bekijk de paginabron (View Source) van beide pagina's. | Beide laden `security/_auth_styles.html.twig` via `{% include %}` — geen los, gedupliceerd `<style>`-blok met de designtokens meer. |
| Pas testsgewijs één kleur aan in `_auth_styles.html.twig` (bijv. `--color-accent`), herlaad beide pagina's, zet de wijziging terug. | Wijziging is op **beide** pagina's tegelijk zichtbaar — bevestigt dat er nu één bron van waarheid is. |

#### T8 — Custom foutpagina's
*Bevinding 8, principe Error Recovery*

| Stap | Verwacht resultaat |
|---|---|
| Navigeer naar een niet-bestaande URL, bijv. `/boek/xyz/9999`. | Nederlandse 404-pagina in de huisstijl (niet de kale Symfony-foutpagina), met "404" en een link "← Terug naar de Bijbel". |
| Klik de terug-link. | Komt uit op de homepage (`app_home`). |
| **Let op:** in de lokale dev-omgeving (`APP_ENV=dev`) toont Symfony standaard de debug-foutpagina i.p.v. de custom template — test dit expliciet met `APP_ENV=prod` lokaal, of op een staging-omgeving. | Custom 404/error-template wordt getoond, geen debug-stacktrace zichtbaar voor eindgebruikers. |
| Forceer (indien mogelijk in staging) een 500-fout. | Generieke `error.html.twig` verschijnt met een niet-technische Nederlandse melding. |

#### T9 — Kleur + lijnstijl voor koppelmethoden
*Bevinding 9, principe Accessibility/Perceptibility*

| Stap | Verwacht resultaat |
|---|---|
| Bekijk een vers met woorden gekoppeld via "handmatig", "hint" en "eigennaam" naast elkaar. | Zichtbaar verschillende onderstreping/rand: handmatig = **doorlopende lijn**, hint = **gestippelde lijn** (dotted), eigennaam = **dubbele lijn** (double, dikker). Positioneel blijft **streepjeslijn** (dashed) zoals voorheen. |
| Simuleer kleurenblindheid: Chrome DevTools → Rendering-tab → "Emulate vision deficiencies" → Deuteranopia. | De drie methoden blijven onderscheidbaar dankzij het lijnpatroon, ook al vervagen de kleuren. |
| Controleer zowel de leesweergave (`.dutch-word[data-method]`) als de koppelweergave (`.method-underline-*` en `.method-border-*` op `.src-word`). | Consistent lijnpatroon op alle drie plekken. |

#### T10 — Nederlandse, begrijpelijke foutmeldingen
*Bevinding 10, principe Match Between System and Real World*

| Stap | Verwacht resultaat |
|---|---|
| Blokkeer de save-URL via devtools ("Block request URL" op `/link/api/save`), probeer een koppeling op te slaan. | Statusregel toont "Opslaan is mislukt. Controleer je internetverbinding en probeer opnieuw." — **geen** Engelse foutmelding zoals "Failed to fetch". |
| Doe hetzelfde tijdens "Bevestig alle voorstellen". | Bij falen: nette samenvatting ("X opgeslagen, Y mislukt…"); open de browserconsole → de technische foutdetails (woord-ID's) staan daar als `console.warn`, niet in de UI. |
| Blokkeer de delete-URL, verwijder een koppeling via "×". | "Verwijderen van de koppeling is mislukt. Controleer je verbinding en probeer opnieuw." |

---

### Ernst 2 — Klein

#### T11 — `aria-live` op statusmeldingen
*Bevinding 11, principe Accessibility*

| Stap | Verwacht resultaat |
|---|---|
| Activeer NVDA/VoiceOver op de koppelpagina. Selecteer en sla een koppeling op zonder naar de statusregel te kijken. | Schermlezer **kondigt automatisch** de statustekst aan ("✓ … opgeslagen") zonder dat je er handmatig naartoe hoeft te navigeren. |
| Verwijder een gebruiker via `/admin/users` (als `admin`) en let op de flashmelding. | Schermlezer kondigt de flashmelding ("Gebruiker … is verwijderd" o.i.d.) automatisch aan. |
| Controleer in de Elements-inspector dat `.linking-status` `role="status" aria-live="polite"` heeft, en flash-`.alert`-divs `role="alert"`. | Attributen aanwezig zoals verwacht. |

#### T12 — Grotere tik-doelen op mobiel
*Bevinding 12, principe Affordances/Accessibility*

| Stap | Verwacht resultaat |
|---|---|
| Open de koppelpagina op een echt mobiel toestel (of DevTools-emulatie ≤ 900px breed). | Bron- en Nederlandse woorden zijn merkbaar makkelijker precies te raken dan voorheen; geen frustrerend mis-tikken bij korte woorden. |
| Meet in devtools de effectieve boundingbox van een `.nl-word`/`.src-word` op mobiele breedte. | Padding is zichtbaar groter dan de oorspronkelijke `.05em .1em` — dichter bij (niet per se exact) de 44×44px-richtlijn. |
| Controleer dat de tekstgrootte zelf **niet** is gewijzigd. | Lettergrootte identiek aan voor de fix — alleen de klikzone is vergroot. |

#### T13 — Bulk-bevestiging annuleerbaar
*Bevinding 13, principe User Control and Freedom*

| Stap | Verwacht resultaat |
|---|---|
| Ga naar een vers/hoofdstuk met meerdere "↝ voorstel"-woorden. Klik "✓ Bevestig alle voorstellen (N)". | De gestileerde bevestigingsdialoog verschijnt (zie T16) i.p.v. de kale browser-`confirm()`. |
| Bevestig. Klik **direct** daarna op de nu zichtbare "Annuleren"-knop, vóórdat alle voorstellen verwerkt zijn. | Statusregel toont "Geannuleerd na X van N koppeling(en)."; de reeds verwerkte koppelingen blijven opgeslagen, de rest wordt niet meer verwerkt. Pagina wordt na ~0,8s ververst. |
| Herhaal, maar laat het proces nu volledig doorlopen zonder te annuleren. | "✓ N koppeling(en) bevestigd." — "Bevestig alle voorstellen"-knop is tijdens het proces verborgen (`hidden`), "Annuleren"-knop zichtbaar; na afloop weer omgekeerd. |
| Test met slechts 0 voorstellen op de pagina (alles al handmatig bevestigd). | Geen "Bevestig alle voorstellen"-knop zichtbaar (bestaand gedrag, ongewijzigd). |

#### T14 — Inline validatie wachtwoord-bevestiging
*Bevinding 14, principe Error Prevention*

| Stap | Verwacht resultaat |
|---|---|
| Ga naar `/register`. Typ een wachtwoord, typ in "Wachtwoord bevestigen" iets anders, en klik/tab weg van dat veld (blur). | Direct verschijnt "De wachtwoorden komen niet overeen." onder het veld; het veld krijgt een rode rand (`aria-invalid="true"`). |
| Corrigeer het bevestigingsveld zodat het overeenkomt, terwijl je nog aan het typen bent. | Foutmelding verdwijnt live zodra de waarden weer overeenkomen (zonder opnieuw te hoeven wegklikken). |
| Dien het formulier in met kloppende wachtwoorden. | Normale registratie-flow, geen wijziging in server-side gedrag. |
| Schermlezer-check: NVDA/VoiceOver op het bevestigingsveld. | `aria-describedby` koppelt het veld aan de foutmelding; schermlezer leest de fout voor bij focus zodra deze zichtbaar is. |

#### T15 — `prefers-reduced-motion` gerespecteerd
*Bevinding 15, principe Flexibility/Perceptibility*

| Stap | Verwacht resultaat |
|---|---|
| Zet in het besturingssysteem "Verminder beweging" / "Reduce motion" aan (Windows: Instellingen → Toegankelijkheid → Visuele effecten; macOS: Systeeminstellingen → Toegankelijkheid → Beeldscherm). | Wissel van thema (licht/donker) via de knop rechtsboven: kleuren veranderen **direct**, zonder de 0,35s vervaag-animatie. |
| Zet de instelling weer uit, herhaal de thema-wissel. | Vervaag-animatie is terug (normale, gewenste situatie voor gebruikers zonder deze voorkeur). |
| Devtools-alternatief: Rendering-tab → "Emulate CSS media feature prefers-reduced-motion: reduce". | Zelfde resultaat als hierboven, zonder OS-instelling te hoeven wijzigen. |

---

### Ernst 1 — Cosmetisch

#### T16 — Gestileerde bevestigingsdialoog i.p.v. `confirm()`
*Bevinding 16, principe Consistency*

| Stap | Verwacht resultaat |
|---|---|
| Als `admin`, klik "Verwijderen" bij een gebruiker in `/admin/users`. | Een gestileerde dialoog in de huisstijl verschijnt (geen kale browser-popup), met de vraag "Gebruiker `<email>` verwijderen?", een neutrale "Annuleren"- en een rode "Verwijderen"-knop. |
| Druk **Escape** terwijl de dialoog open is. | Dialoog sluit, **geen** verwijdering uitgevoerd (native `<dialog>`-gedrag). |
| Klik "Annuleren". | Dialoog sluit, gebruiker blijft bestaan. |
| Klik "Verwijderen" (de knop in de dialoog). | Formulier wordt alsnog ingediend, gebruiker wordt verwijderd zoals voorheen (inclusief CSRF-token). |
| **Toetsenbord-check:** open de dialoog, druk Tab herhaaldelijk. | Focus blijft **binnen** de dialoog (native focus-trap) — kan niet per ongeluk naar de achterliggende pagina tabben. |
| Test hetzelfde voor "✓ Bevestig alle voorstellen" op de koppelpagina (zie ook T13). | Zelfde dialoogstijl, met "Bevestig alle" als groene bevestigingsknop i.p.v. rode. |
| **Cross-browser:** herhaal in minimaal 2 browsers (bijv. Chrome + Firefox). | Dialoog rendert en centreert correct in beide (native `<dialog>`-ondersteuning + custom centrering-CSS). |

#### T17 — Consistente vertaal-terminologie
*Bevinding 17, principe Consistency*

| Stap | Verwacht resultaat |
|---|---|
| Open dezelfde vertaling naast elkaar op de leespagina (`verse_frame`) en de koppelpagina (`passage`). | Beide tonen nu naam + afkorting op eenzelfde manier (bijv. "Statenvertaling (Jongbloed) SV(JB)" vs. voorheen kaal "Statenvertaling (Jongbloed)" op de koppelpagina). |
| Herhaal voor HSV, SV(GBS), SV(1657) indien de test-account daar rechten toe heeft. | Consistente labeling voor elke vertaling. |

#### T18 — Call-to-action voor uitgelogde bezoekers
*Bevinding 18, principe Recognition over Recall*

| Stap | Verwacht resultaat |
|---|---|
| Log uit, ga naar de homepage (`/`). | Onder de coverage-statistieken verschijnt een kader met "Log in om alle bijbelboeken en hoofdstukken te doorbladeren." met één knop: "Inloggen". |
| Klik "Inloggen". | Komt op `/login` terecht. |
| Log in, ga terug naar de homepage. | CTA-kader is **niet** meer zichtbaar; de normale boekenlijst (OT/NT-kolommen) verschijnt zoals voorheen. |

> **Let op — aangepast t.o.v. de oorspronkelijke bevinding:** de eerste implementatie voegde ook een "Account aanmaken"-knop toe die naar `path('app_register')` linkte. Tijdens het visueel testen (zie [`UX-AUDIT-2026-09-MOBIEL.md`](UX-AUDIT-2026-09-MOBIEL.md)) bleek die route helemaal niet te bestaan — de app heeft geen zelfregistratie, gebruikers worden alleen door een admin aangemaakt via `/admin/users`. De knop gaf een 500-fout en is verwijderd. Test dus alleen de "Inloggen"-knop, niet "Account aanmaken".

---

## 4. Mobiele fixes (aanvulling 2026-09-10)

Bij: [`UX-AUDIT-2026-09-MOBIEL.md`](UX-AUDIT-2026-09-MOBIEL.md), bevindingen 1–3 (Kritiek). Deze drie zijn gevonden én geverifieerd met Playwright tegen de echte draaiende app (iPhone SE/13-emulatie, `window.innerWidth`-metingen) — de stappen hieronder zijn dezelfde controles, nu handmatig uit te voeren.

### Voorbereiding
Voor T19 en T21 is een account met minimaal `ROLE_VIEWER_HSV` (of een andere restricted-translation-rol) nodig — de overflow in T19 zat specifiek in de vertaal-indicator-toggle die alleen voor die rollen zichtbaar is. Gebruik het `linker`-account uit sectie 1.1.

#### T19 — Verskoppelpagina niet langer breder dan het scherm
*Bevinding 1 (mobiel), root cause: `1fr`-grid-track zonder `min-width:0`, plus een niet-wrappende `.panel-heading`*

| Stap | Verwacht resultaat |
|---|---|
| Open op een telefoon (of DevTools-mobielemulatie, bv. iPhone 13/390px) een los vers, bv. `/book/GEN/1/1/SV`, ingelogd als `linker`. | Pagina vult exact de schermbreedte — geen enkel element steekt rechts uit, geen onbedoelde horizontale scroll of "uitgezoomd" ogende tekst. |
| Meet in de console: `window.innerWidth`. | Waarde is **gelijk aan** de devicebreedte (bv. 390), niet groter. |
| Bekijk de kop van het Hebreeuws/Grieks-paneel ("Hebreeuws" + "Indicatoren: [dropdown]"). | De "Indicatoren"-toggle valt netjes op een eigen regel onder de taalnaam i.p.v. de kop uit elkaar te duwen. |
| Herhaal op een breder mobiel formaat (bv. 430px) en op tablet-breedte (bv. 768px). | Blijft correct — geen regressie op tussenliggende breedtes. |
| **Regressie:** open dezelfde pagina op desktop-breedte (>1000px). | Twee kolommen naast elkaar zoals voorheen, layout ongewijzigd. |

#### T20 — Tabellen duwen de pagina niet meer uit elkaar
*Bevinding 3 (mobiel), root cause: `<table>` zonder `overflow-x`-wrapper*

| Stap | Verwacht resultaat |
|---|---|
| Open op mobiel (of DevTools-emulatie) `/admin/users` als `admin`. | Header, "+ Nieuwe gebruiker"-knop en paginatitel renderen op normale, leesbare grootte — **niet** uitgezoomd. |
| Meet `window.innerWidth`. | Gelijk aan de devicebreedte, niet ~1000px+. |
| Veeg horizontaal over de tabel zelf. | De tabel scrollt **binnen zijn eigen kader** (afgeronde hoeken blijven zichtbaar aan het begin/einde); de rest van de pagina blijft stilstaan. |
| Herhaal op `/link/translations/historical/library` als `linker`. | Zelfde gedrag: pagina op normale grootte, alleen de tabel scrollt intern. |
| **Regressie:** open beide pagina's op desktop. | Tabellen tonen zoals voorheen, geen ongewenste scrollbalk als alle kolommen toch al passen. |

#### T21 — Rechtstreeks bezoek aan een Strong's-pagina toont de volledige site, niet een kaal fragment
*Bevinding 2 (mobiel), root cause: route retourneerde altijd het kale turbo-frame-fragment, ook buiten een frame-navigatie*

| Stap | Verwacht resultaat |
|---|---|
| Navigeer **rechtstreeks** naar `/strongs/H7225` (nieuwe tab, adresbalk, of gedeelde link) — dus niet via een klik binnen de leesweergave. | Volledige pagina met normale navigatie (logo, hamburger/menu), een "← Terug naar de Bijbel"-link, en het Strong's-woordenboekkaartje in de vertrouwde huisstijl (lettertypen, kleuren) — **geen** kale, ongestylede tekst. |
| Bekijk de paginabron. | `<meta name="viewport" content="width=device-width, initial-scale=1">` is aanwezig. |
| Klik "← Terug naar de Bijbel". | Komt uit op de homepage. |
| Herhaal op mobiel-emulatie. | Pagina is normaal leesbaar, geen uitzoom-effect (`window.innerWidth` = devicebreedte). |
| **Regressie — bestaande in-page flow:** ga naar `/book/GEN/1/1/SV` (of de hoofdstukweergave) en klik op een Strong's-nummer (bv. "H7225") in de brontekst-popup. | Het detailpaneel/de zijbalk vult **inline** met de woordenboekinhoud, zonder de pagina te verlaten of te verversen — precies zoals voorheen. |
| **Regressie — koppelinterface:** ga naar `/link/passage/SV/<boek>/<hfst>/<vers>` als `linker`, klik een bronwoord met een Strong's-nummer. | Het "Strong's"-paneel rechts vult zoals gebruikelijk, zonder waarneembare vertraging (dit paneel wordt via een losse `fetch` geladen, niet via Turbo — moet nog steeds het lichte fragment krijgen, niet de volledige pagina). |
| **Regressie — link vanuit historische alignment:** open `/link/translations/historical/<boek>/<hfst>/<vers>`, klik een Strong's-nummer in de brontekstregel (opent in nieuw tabblad). | Nieuw tabblad toont de volledige, gestileerde pagina (dit gebruikte al `target="_blank"` en werkte impliciet mee als motivatie voor deze fix). |
| **Regressie — statistiekenpagina:** ga naar `/link/statistieken/SV` als `linker`, klik een Strong's-nummer in de tabel. | Navigeert netjes naar de volledige Strong's-pagina (deze link had geen `data-turbo-frame`-attribuut en verwachtte dus altijd al een volledige pagina — dit werkte impliciet niet correct vóór de fix). |

---

## 5. Niet in dit testplan (bewust buiten scope)

- **Link naar de koppelgids** (bevinding uit het rapport, ernst 2) — deze fix is **niet** doorgevoerd (zie samenvatting bij de implementatie); niets te testen.
- **Overige `confirm()`-aanroepen** in `admin/news_digest/index.html.twig` en `translation_linker_controller.js` — bewust ongewijzigd gelaten, dus nog steeds de kale browser-dialoog. Geen regressie te verwachten, maar ook geen verbetering hier.
- **Nested interactive elements** (`.link-chip` met `role="button"` binnen `.src-word` met `role="button"`) — functioneel correct getest in T3, maar dit is geen WCAG-perfecte structuur. Geen aparte test nodig buiten T3/T1.

---

## 6. Afrondingscriteria

Alle testgevallen T1 t/m T21 zijn **groen**, én de algemene regressietest (R1–R6) toont geen afwijkend gedrag t.o.v. de situatie vóór de fixes. Test minimaal op:
- 1 desktopbrowser (Chrome of Firefox) met toetsenbord + schermlezer.
- 1 mobiel toestel of touch-emulatie.
- Zowel lichte als donkere modus.
- Zowel `linker`- als niet-ingelogde/`viewer`-rechten (waar van toepassing per tabel hierboven).
- T19–T21 specifiek: minimaal twee mobiele breedtes (bv. 375px en 390px) plus een desktopbreedte voor de regressiecontrole.
