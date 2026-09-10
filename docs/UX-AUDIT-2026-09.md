# UX Design Audit — Alef–Omega Bijbelwoord-koppeltool

**Datum:** 2026-09-09
**Uitgevoerd als:** Senior UX Engineer/Designer review
**Scope:** Front-end van de Symfony/Twig/Stimulus-applicatie — publieke bijbellezer, koppelinterface (linker), authenticatie, blog en beheer.

**Bronnen (gelezen bestanden):**
- `app/templates/base.html.twig` (applicatie-shell/navigatie)
- `app/templates/bible/home.html.twig`, `bible/verse_frame.html.twig`, `bible/chapter_view.html.twig`, `bible/strongs.html.twig`
- `app/templates/linking/passage.html.twig`, `linking/home.html.twig`
- `app/templates/security/login.html.twig`, `security/register.html.twig`
- `app/templates/admin/users/index.html.twig`
- `app/templates/blog/show.html.twig`
- `app/assets/styles/app.css` (4598 regels — designtokens, thema's, responsive breakpoints)
- `app/assets/controllers/word_linker_controller.js`, `mobile_nav_controller.js`, `theme_controller.js`, `nav_panel_controller.js`
- `README.md` (functionele context)

**Interfacetype:** Hybride — publieke leesapplicatie (bijbelvergelijking, laag-drempelig voor bezoekers) + gespecialiseerde data-annotatie/linker-tool (voor ingelogde redacteuren) + standaard beheer/auth-schermen. Elk van deze drie te beoordelen naar eigen aard (marketing/lees-ervaring vs. dense werktool vs. formulier).

---

### Hoe dit rapport te lezen

Bevindingen zijn beoordeeld op een schaal van 0-4 (4 = gebruikers kunnen de taak niet voltooien, 1 = alleen cosmetisch). Elke bevinding verwijst naar een gevestigd usability-principe, bevat de exacte bestandslocatie en een concrete, uitvoerbare fix. Begin bovenaan — de meest impactvolle problemen staan eerst.

---

### Samenvatting

| Ernst | Aantal |
|---|---|
| 4 - Catastrofaal | 2 |
| 3 - Groot | 7 |
| 2 - Klein | 6 |
| 1 - Cosmetisch | 3 |
| **Totaal** | **18** |

### Snelle winst (hoge impact, relatief eenvoudig te fixen)

1. **Kapotte "×"-knop op individuele koppelingen** (Ernst 3) — voeg de ontbrekende click-handler + delete-call toe; de infrastructuur (`deleteUrlValue`, `#deleteUrl()`) bestaat al maar wordt nergens aangeroepen.
2. **Geen focus-stijl op klikbare woorden** (Ernst 4) — één CSS-regel (`:focus-visible` met duidelijke outline) op `.source-word`, `.src-word`, `.dutch-word`, `.nl-word`, `.book-link` lost het grootste deel van het toetsenbord-probleem op.
3. **Volledige paginaherlaad na opslaan van een koppeling** (Ernst 3) — vervang `setTimeout(() => window.location.reload(), 600)` door een gerichte DOM-update, zoals al wél gebeurt in de Strong's-weergave (`#refreshVerseBlock`).
4. **Contrast van gedempte tekst in lichte modus** (Ernst 3) — `--color-ink-muted` en `--color-none` halen geen WCAG AA; dit raakt transliteraties, hints en "geen koppeling"-labels door de hele site.
5. **Geïnline gedupliceerde CSS in login/registratie** (Ernst 2) — trek `security/login.html.twig` en `security/register.html.twig` uit `app.css` zodat thema-updates niet op twee plekken los van elkaar bijgehouden moeten worden.

---

### Bevindingen

#### [Ernst 4] De kerninteractie van de hele applicatie is niet met het toetsenbord te bedienen
- **Principe:** Accessibility (H13), Affordances and Signifiers (H11)
- **Locatie:** `app/templates/bible/verse_frame.html.twig:56` (`.source-word`), `app/templates/linking/passage.html.twig:88` (`.src-word`), `:145` (`.nl-word`), `app/assets/styles/app.css` (geen `:focus`/`:focus-visible`-regel voor deze klassen)
- **Issue:** De centrale handeling van de app — een grondtekstwoord aanklikken om koppelingen te zien of te leggen — gebeurt op `<div>`/`<span>`-elementen met alleen `data-action="click->..."`. Ze hebben geen `role="button"`, geen `tabindex="0"`, geen `keydown`-handler voor Enter/Spatie, en er is nergens in `app.css` een `:focus` of `:focus-visible`-stijl voor `.source-word`, `.src-word`, `.dutch-word` of `.nl-word` gedefinieerd (alleen `:focus-within` op de popup, niet op het element zelf). Ook `.book-link` op de homepage mist een focusindicator.
- **User impact:** Een toetsenbordgebruiker (motorische beperking, of iemand die simpelweg met Tab navigeert) kan geen woord selecteren, geen koppeling leggen en geen Strong's-detail openen — de kernfunctionaliteit van de applicatie is voor deze gebruikers volledig ontoegankelijk. Een screenreadergebruiker krijgt bovendien geen enkele aankondiging dat dit interactieve elementen zijn.
- **Fix:** Voeg `role="button" tabindex="0"` toe aan alle vier woord-elementen, een gedeelde `keydown`-handler in de Stimulus-controllers die Enter/Spatie naar de bestaande click-methode doorstuurt, én een zichtbare `:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }`-regel die voor alle vier klassen geldt.

#### [Ernst 4] Statustekst, methode en betrouwbaarheidsscore zijn alleen bereikbaar via `title`-tooltips (hover-only)
- **Principe:** Recognition Over Recall (H6), Help and Documentation (H10), Accessibility (H13)
- **Locatie:** `app/templates/bible/verse_frame.html.twig:89-138`, `app/templates/linking/passage.html.twig:117-127` — elk `link-dot`/`link-chip`/`no-link-badge` draagt de methode en het percentage uitsluitend in het `title`-attribuut
- **Issue:** De enige plek waar een gebruiker ziet *welke* koppelmethode is gebruikt en met welke score (bijv. "manual: 100%" vs. "auto_positional: 42%") is een browser-`title`-tooltip. Tooltips verschijnen alleen bij `:hover`, wat op touchscreens (telefoon/tablet) niet bestaat — en worden door de meeste screenreaders niet automatisch voorgelezen.
- **User impact:** Op mobiel — een reëel gebruiksscenario voor een leesapp die dagelijks bijbelteksten toont — kan een bezoeker de betrouwbaarheid van een koppeling helemaal niet meer opvragen; de kleurcode van het stipje/lijntje is het enige signaal dat overblijft, en zelfs dat is voor kleurenblinde gebruikers dubbelzinnig (zie ook de bevinding hieronder over kleurcodering).
- **Fix:** Geef de detailinformatie een zichtbare, tik-vriendelijke plek: een `aria-describedby`-gekoppeld panel dat opent bij klik/tap (bijv. hergebruik van het bestaande `word-popup`-mechanisme, maar getriggerd door `click`/`focus` in plaats van uitsluitend `hover`), met de methode en score als leesbare tekst.

#### [Ernst 3] De "×"-knop om één koppeling te verwijderen doet niets
- **Principe:** User Control and Freedom (H3), Affordances and Signifiers (H11), Tolerance and Forgiveness (H15)
- **Locatie:** `app/templates/linking/passage.html.twig:117-122` (`.link-chip`), `app/assets/styles/app.css:1933-1943` (`cursor: pointer` op `.link-chip`), `app/assets/controllers/word_linker_controller.js:266-268` (`#deleteUrl()` is gedefinieerd maar wordt nergens aangeroepen)
- **Issue:** Elke gelegde koppeling toont een chip met een "×"-symbool en `cursor: pointer` — een sterk visueel signaal van "klik hier om te verwijderen". Er is echter geen `data-action` op dit element gezet. Een klik erop bubbelt gewoon door naar de bovenliggende `.src-word` en triggert `selectSource()` (dus: het hele woord wordt geselecteerd/gedeselecteerd), niet het verwijderen van die ene koppeling. De controller bevat zelfs een kant-en-klare `#deleteUrl(linkId)`-helper en een `deleteUrlValue` die vanuit de template wordt meegegeven — duidelijk bedoeld voor deze functie, maar nooit aangesloten.
- **User impact:** Een redacteur die één foutieve koppeling tussen meerdere wil verwijderen, klikt op de "×" en ziet in plaats daarvan het hele woord (opnieuw) geselecteerd worden — verwarrend, en de enige werkende weg is: alle koppelingen van dat woord opnieuw samenstellen en volledig opnieuw opslaan. Dit is foutgevoelig bij woorden met veel koppelingen.
- **Fix:** Voeg `data-action="click->word-linker#deleteLink"` toe aan `.link-chip` (met `event.stopPropagation()` zodat het niet doorbubbelt naar `selectSource`), en implementeer `deleteLink()` in de controller die de al bestaande `#deleteUrl(linkId)` aanroept.

#### [Ernst 3] Contrast van gedempte tekst voldoet niet aan WCAG AA in lichte modus
- **Principe:** Accessibility (H13)
- **Locatie:** `app/assets/styles/app.css:21` (`--color-ink-muted: #8c7c5e`), `:33` (`--color-none: #c0b090`), beide gebruikt op achtergrond `--bg: #f6efe1`
- **Issue:** Berekend contrast van `--color-ink-muted` op `--bg` is **≈3,6:1** (WCAG AA vereist 4,5:1 voor normale tekst); `--color-none` op dezelfde achtergrond is **≈1,9:1** — ver onder elke drempel. `--color-ink-muted` wordt gebruikt voor transliteraties (`.word-translit`, `.src-translit`), veldhints en de legenda-tekst; `--color-none` voor "geen koppeling"-labels (`.no-link-badge`) en woorden zonder koppelmethode. De donkere modus is blijkens het codecommentaar wél expliciet op 4,5:1 gecontroleerd (`app.css:53-57`) — de oorspronkelijke lichte-modus-tokens klaarblijkelijk niet.
- **User impact:** Slechtziende gebruikers en gebruikers in fel omgevingslicht kunnen transliteraties, hints en de completeness-indicator ("—" voor geen koppeling) nauwelijks lezen. Voor redacteuren is dat laatste functioneel relevant: ze moeten juist kunnen zien welke woorden nog geen koppeling hebben.
- **Fix:** Verdonker beide tokens in het lichte thema tot ze ≥4,5:1 halen (bijv. `--color-ink-muted` richting `#6b5c3f`, `--color-none` richting `#8a7a5a`) en herbereken de dark-mode-tegenhangers zijn al in orde.

#### [Ernst 3] Opslaan van een koppeling herlaadt de hele pagina i.p.v. een gerichte update
- **Principe:** Visibility of System Status (H1), Aesthetic and Minimalist Design (H8)
- **Locatie:** `app/assets/controllers/word_linker_controller.js:118-124` (`saveLinks()`, passage-weergave-tak)
- **Issue:** In de Strong's-weergave doet de app het al goed: na opslaan wordt alléén het versblok vervangen (`#refreshVerseBlock()`) en de voortgangsbalk bijgewerkt (`#refreshProgressBar()`) — geen page reload. In de gewone verskoppel-weergave (`passage.html.twig`) gebeurt na een succesvolle save echter `setTimeout(() => window.location.reload(), 600)`: de hele pagina herlaadt na 600ms, inclusief opnieuw laden van fonts, CSS en alle DOM-state (geopende paneel, scrollpositie).
- **User impact:** Elke opslag-actie voelt trager en "brozer" aan dan nodig; bij een lange koppelsessie (tientallen woorden per uur, blijkens de op de homepage getoonde voortgang van honderdduizenden woorden) is dit een merkbare frictie die per opgeslagen woord terugkeert.
- **Fix:** Pas hetzelfde patroon toe als in de Strong's-weergave: vervang alleen het `.src-word`-blok (dat gebeurt al gedeeltelijk via `#updateSourceWordDOM`) zonder de `window.location.reload()`-fallback, of laad het versblok via een gerichte fetch zoals `#refreshVerseBlock()` al doet.

#### [Ernst 3] Opslaan-knop wordt niet uitgeschakeld tijdens het verzoek — dubbele indieningen mogelijk
- **Principe:** Error Prevention (H5), Visibility of System Status (H1)
- **Locatie:** `app/assets/controllers/word_linker_controller.js:90-129` (`saveLinks`), `app/templates/linking/passage.html.twig:191-194` (`.btn-save`)
- **Issue:** `saveLinks()` doet een `fetch(...)` zonder de knop te disablen of een spinner te tonen tijdens het wachten op antwoord. Een gebruiker die dubbelklikt (of op een trage verbinding twee keer klikt omdat er niets zichtbaar gebeurt) vuurt de opslag-request twee keer af.
- **User impact:** Potentieel dubbele/afwijkende koppelingen in de database bij een trage verbinding, en voor de gebruiker geen enkel signaal *tijdens* het opslaan dat er iets gebeurt — alleen ná afloop verschijnt statustekst.
- **Fix:** Zet `event.currentTarget.disabled = true` bij de start van `saveLinks()` (en `confirmAllProposals()`, waar wél al een `#confirmPending`-vlag bestaat maar de knop zelf niet visueel disabled wordt) en herstel bij fout.

#### [Ernst 3] Volledig gedupliceerde, losstaande CSS in login- en registratiepagina
- **Principe:** Consistency and Standards (H4)
- **Locatie:** `app/templates/security/login.html.twig:12-150` en `app/templates/security/register.html.twig:12-87` — bijna identieke `<style>`-blokken, los van `app.css`
- **Issue:** Beide auth-pagina's bevatten een eigen, volledig losstaande set CSS-variabelen (`--color-parchment`, `--color-card`, `--color-accent`, …) die inhoudelijk overlapt met maar niet identiek is aan de tokens in `app.css` (`--bg`, `--card-warm`, `--accent`, …). Ze laden zelfs een ander (kleiner) lettertype-subset dan de rest van de site. Deze pagina's hebben geen `data-theme`-ondersteuning: een gebruiker die donkere modus heeft ingesteld op elke andere pagina, ziet op `/login` en `/register` altijd het lichte thema — een onverwachte omslag op precies het moment van in-/uitloggen.
- **User impact:** Merkbeleving breekt op de meest kritieke conversiemomenten (registreren, inloggen); toekomstige themawijzigingen (bijv. een kleuraanpassing) moeten op drie plekken los worden doorgevoerd, met een reëel risico dat auth-pagina's stilletjes uit de pas gaan lopen.
- **Fix:** Laat login/registratie ook `base.html.twig` of in ieder geval `app.css` + de gedeelde designtokens gebruiken (desnoods met een minimale, aparte layout-template zonder navigatie), en respecteer `data-theme` net als de rest van de site.

#### [Ernst 3] Geen aangepaste, gestileerde foutpagina's (404/500)
- **Principe:** Error Recovery (H9), Consistency and Standards (H4)
- **Locatie:** geen `app/templates/bundles/TwigBundle/Exception/*.html.twig` of vergelijkbare custom error-templates aanwezig in de repository
- **Issue:** Er zijn geen custom Symfony-foutpagina's gevonden. Standaard toont Symfony in productie een generieke, merkloze foutpagina zonder navigatie terug naar de site.
- **User impact:** Een bezoeker die een verkeerde of verouderde link volgt (bijv. een gedeelde versverwijzing naar een boek/hoofdstuk dat niet bestaat) komt in een doodlopende weg zonder duidelijke weg terug — geen "Terug naar Bijbel"-link, geen herkenbare huisstijl, geen uitleg in het Nederlands zoals de rest van de site consequent biedt.
- **Fix:** Voeg `templates/bundles/TwigBundle/Exception/error404.html.twig` en `error.html.twig` toe die `base.html.twig` extenden, met een Nederlandse foutmelding en een link terug naar `app_home`.

#### [Ernst 3] Koppelmethode wordt uitsluitend via kleur onderscheiden voor visueel vergelijkbare methoden
- **Principe:** Accessibility (H13), Perceptibility (H14)
- **Locatie:** `app/assets/styles/app.css:828-832` (`.dutch-word[data-method]`), `:1902-1912` (`.method-border-*`)
- **Issue:** "Handmatig" (groen `#1a7a3a`), "Hint" (teal `#2e7d6e`) en "Eigennaam" (blauw `#1e4d7a`) worden alle drie als een **solide** onderstreping/rand getoond en verschillen alléén in kleurtint. Alleen "Positioneel" en de auto-methoden krijgen een ander lijnpatroon (`dashed`). Voor gebruikers met deuteranopie/protanopie (rood-groen kleurenblindheid, de meest voorkomende vorm) zijn groen en blauw-groen op dit formaat (2px lijn) moeilijk te onderscheiden.
- **User impact:** Een kleurenblinde redacteur kan tijdens het controleren van koppelingen niet betrouwbaar zien of een woord handmatig, via een hint, of als eigennaam is gekoppeld zonder elke keer de tooltip te openen (die op mobiel toch al niet werkt, zie hierboven) — foutgevoelig bij kwaliteitscontrole, precies de taak waar deze indicator voor bedoeld is.
- **Fix:** Geef minimaal de drie "solide" methoden ook een eigen lijnstijl (bv. dubbele lijn, stippellijn met andere afstand, of een klein icoon/letter-badge) naast de kleur, zodat vorm plus kleur samen het onderscheid dragen.

#### [Ernst 3] Meldingen bij falende bulk-actie tonen ruwe JS-foutmeldingen aan de gebruiker
- **Principe:** Match Between System and Real World (H2), Error Recovery (H9)
- **Locatie:** `app/assets/controllers/word_linker_controller.js:126-128` (`catch (err) { this.#setStatus(\`Fout bij opslaan: ${err.message}\`) }`), ook in `confirmAllProposals()` regel 180-181
- **Issue:** Bij een mislukte fetch (netwerkfout, timeout, serverfout) wordt `err.message` — de ruwe JavaScript/fetch-foutmelding (bijv. "Failed to fetch", "NetworkError when attempting to fetch resource") — direct getoond in de statusbalk. Dit is systeemtaal, geen mensentaal, en bevat geen concreet herstelvoorstel.
- **User impact:** Een redacteur die een netwerkstoring heeft, ziet Engelse technische jargon in plaats van een Nederlandse melding met een handelingsperspectief ("Opslaan mislukt — controleer je internetverbinding en probeer opnieuw"), wat extra verwarring geeft in een verder volledig Nederlandstalige interface.
- **Fix:** Vertaal/normaliseer de foutmelding vóór weergave naar een vaste, begrijpelijke Nederlandse tekst, en log de technische `err.message` alleen naar de console (zoals al gebeurt met `console.warn` in `confirmAllProposals`) voor debugging.

#### [Ernst 2] Geen enkele `aria-live`-regio in de hele applicatie
- **Principe:** Accessibility (H13), Visibility of System Status (H1)
- **Locatie:** applicatiebreed — geen treffers voor `aria-live`, `role="alert"` of `aria-describedby` in `app/templates/**` of `app/assets/**`
- **Issue:** Statusupdates zoals "✓ Opgeslagen: geen koppeling", "Bezig met opslaan (3/12)…" of foutmeldingen worden met `textContent` in `.linking-status`-elementen gezet, maar dat element heeft geen `aria-live="polite"` (of `role="status"`). Flash-meldingen (`app.flashes`) in bijvoorbeeld het admin-gebruikersoverzicht hebben evenmin een live-region of `role="alert"`.
- **User impact:** Screenreadergebruikers krijgen geen enkele aankondiging wanneer een koppeling is opgeslagen, een bulk-bevestiging is voltooid, of een gebruiker succesvol is verwijderd — ze moeten zelf actief de pagina aftasten om te ontdekken of hun actie is gelukt.
- **Fix:** Voeg `aria-live="polite"` toe aan `.linking-status` (`data-word-linker-target="status"`) en `role="alert"` aan `.alert`-containers in flash-berichten.

#### [Ernst 2] Zeer kleine tik-doelen voor de kernwoorden op mobiel
- **Principe:** Affordances and Signifiers (H11), Accessibility (H13)
- **Locatie:** `app/assets/styles/app.css:818-824` (`.dutch-word { padding: .05em .1em }`), `:1946-1951` (`.nl-word { padding: .05em .1em }`), `:1875-1880` (`.src-word { padding: .5rem 3px }`)
- **Issue:** De klikbare woord-elementen — het hart van de koppelinterface — hebben paddings die ver onder de aanbevolen 44×44px-tikdoel van WCAG 2.5.5 blijven. Voor korte woorden (bijv. lidwoorden, korte Hebreeuwse partikels) is het effectieve tikgebied vaak niet groter dan het lettertype zelf plus een paar pixels padding.
- **User impact:** Op een telefoon is het foutgevoelig om precies het bedoelde woord te raken tussen dicht opeengepakte tekst, zeker in de Hebreeuwse/Griekse kolom waar diakrieten en korte woorden dicht bij elkaar staan. Verkeerde selecties tijdens het koppelen kosten redacteuren tijd en verhogen het risico op abusievelijk foutieve koppelingen.
- **Fix:** Vergroot de effectieve tikzone met `padding` of een onzichtbare `::before`-hitbox tot minimaal ~8px verticaal/horizontaal per woord op mobiele breakpoints, zonder de visuele tekstgrootte te vergroten.

#### [Ernst 2] Bulk-bevestiging van voorstellen heeft geen tussentijdse annuleeroptie
- **Principe:** User Control and Freedom (H3)
- **Locatie:** `app/assets/controllers/word_linker_controller.js:135-195` (`confirmAllProposals`)
- **Issue:** Na de initiële `confirm()`-dialoog doorloopt de functie sequentieel élk voorstel met een `await fetch(...)` per item, zonder enige manier om het proces halverwege te stoppen. Bij verzen met veel voorgestelde koppelingen kan dit een merkbare tijd duren.
- **User impact:** Een redacteur die na het starten van de bulk-actie een vergissing beseft (bijv. verkeerde vertaling geselecteerd) moet de hele reeks laten uitlopen — er is geen "stop"-knop, alleen de vooraf-bevestiging.
- **Fix:** Toon tijdens het lopen een zichtbare "Annuleren"-knop die een `AbortController` gebruikt om de resterende `fetch`-aanroepen te stoppen.

#### [Ernst 2] Wachtwoord-bevestigingsveld valideert niet inline
- **Principe:** Error Prevention (H5)
- **Locatie:** `app/templates/security/register.html.twig:133-150` (`password` / `confirm_password`)
- **Issue:** Er is geen client-side check die vergelijkt of `password` en `confirm_password` overeenkomen vóór verzending; dit wordt kennelijk pas server-side gecontroleerd (geen `oninput`/`pattern`-koppeling tussen de twee velden).
- **User impact:** Een gebruiker die een tikfout maakt in het bevestigingsveld ontdekt dat pas ná het versturen van het formulier — met alle velden opnieuw invullen als gevolg als het formulier de wachtwoordvelden bij fout leegt (gebruikelijk Symfony-gedrag bij herrendering van een form met errors).
- **Fix:** Voeg een lichte JS-check toe die bij `blur`/`input` op `confirm_password` een inline melding toont wanneer de waarden niet overeenkomen, vóór de gebruiker op "Account aanmaken" klikt.

#### [Ernst 2] Geen `prefers-reduced-motion`-ondersteuning bij globale thema-transitie
- **Principe:** Flexibility and Efficiency (H7), Perceptibility (H14)
- **Locatie:** `app/assets/styles/app.css:111-115` (`*, *::before, *::after { transition: background-color .35s ease, color .35s ease, border-color .35s ease; }`)
- **Issue:** Deze globale transitie-regel geldt voor élk element op elke pagina en wordt niet binnen een `@media (prefers-reduced-motion: no-preference)`-blok geplaatst. Er is nergens in `app.css` een `prefers-reduced-motion`-query aanwezig.
- **User impact:** Gebruikers die in hun besturingssysteem bewegingsreductie hebben ingeschakeld (vaak om reden van migraine, vestibulaire aandoeningen of gewoon voorkeur) krijgen alsnog de volledige overgangsanimatie bij elke thema-wissel en bij elke class-toggle die kleur raakt.
- **Fix:** Wrap de transitieregel in `@media (prefers-reduced-motion: no-preference) { ... }`, met een instant-fallback (`transition: none`) daarbuiten.

#### [Ernst 2] Contextuele hulp bij de koppelmethode-legenda is alleen op de detailpagina zichtbaar, niet vanaf het beginscherm
- **Principe:** Help and Documentation (H10)
- **Locatie:** `app/templates/linking/home.html.twig` (geen link naar `docs/koppelgids.md` of enige inline uitleg van de methodiek/kleurcodering)
- **Issue:** De uitgebreide koppelgids (`docs/koppelgids.md`) bestaat, maar wordt nergens vanuit de "Woorden koppelen"-startpagina of de koppelinterface zelf gelinkt. Nieuwe redacteuren komen voor het eerst op `linking/home.html.twig` zonder enige verwijzing naar deze documentatie.
- **User impact:** Een nieuwe redacteur moet zelf weten dat er een handleiding bestaat (en waar) voordat die voor het eerst gaat koppelen; de legenda op de verspagina zelf (`.linking-legend`) legt de kleuren uit, maar niet de bredere workflow (wanneer kies je "hint" versus "handmatig"?).
- **Fix:** Voeg een "Hulp nodig? Bekijk de koppelgids"-link toe op `linking/home.html.twig`, en eventueel een `?`-icoon bij de legenda dat naar het relevante hoofdstuk van de gids linkt.

#### [Ernst 1] Native `confirm()`-dialogen breken de merkbeleving
- **Principe:** Consistency and Standards (H4), Aesthetic and Minimalist Design (H8)
- **Locatie:** `app/templates/admin/users/index.html.twig:49` (`onsubmit="return confirm(...)"`), `app/assets/controllers/word_linker_controller.js:151` (`confirm(...)` in `confirmAllProposals`)
- **Issue:** Destructieve/impactvolle acties (gebruiker verwijderen, bulk-bevestiging) gebruiken de kale browser-`confirm()`-dialoog, die qua typografie en styling volledig loskoppelt van de zorgvuldig opgebouwde "manuscript"-vormgeving van de rest van de site.
- **User impact:** Cosmetisch, maar wel een merkbare stijlbreuk op precies de momenten die het meest aandacht verdienen (een onomkeerbare verwijdering).
- **Fix:** Vervang door een gestileerde bevestigingsmodal die past bij het bestaande `.nav-panel`/paneel-patroon (inclusief Escape-afhandeling, zoals al elders in de codebase correct is geïmplementeerd — zie Sterke punten).

#### [Ernst 1] Wisselende terminologie voor dezelfde vertaling tussen pagina's
- **Principe:** Consistency and Standards (H4)
- **Locatie:** `app/templates/bible/verse_frame.html.twig:194-197` ("Statenvertaling (Jongbloed) SV(JB)") vs. `app/templates/linking/passage.html.twig:137` (toont enkel `translation.name`, geen vaste afkorting-conventie zichtbaar in template)
- **Issue:** Op de leespagina wordt consequent zowel de volledige naam als de afkorting getoond (bijv. "Statenvertaling (Jongbloed) SV(JB)"), terwijl de koppelpagina alleen `translation.name` rendert. Voor gebruikers die tussen beide schermen wisselen ontstaat een net iets andere labeling-conventie.
- **User impact:** Klein, maar vermindert de herkenbaarheid ("is dit dezelfde vertaling als waar ik net was?") bij het wisselen tussen lees- en koppelmodus.
- **Fix:** Hergebruik één Twig-macro/functie voor het renderen van vertalingslabels op alle pagina's.

#### [Ernst 1] Homepage toont geen call-to-action voor niet-ingelogde bezoekers
- **Principe:** Recognition Over Recall (H6), Aesthetic and Minimalist Design (H8)
- **Locatie:** `app/templates/bible/home.html.twig:41-98` — de volledige boekenlijst (`testament-grid`) wordt uitsluitend getoond `{% if app.user %}`
- **Issue:** Een anonieme bezoeker ziet na de hero en de coverage-statistieken... niets meer. Geen boekenlijst, geen "Log in om te beginnen"-knop, geen uitleg wat er te doen valt zonder account.
- **User impact:** Een nieuwe, niet-ingelogde bezoeker die via een zoekmachine op de homepage belandt, ziet een hero-tekst en wat statistiek-kaarten, en vervolgens een lege pagina zonder duidelijke volgende stap — terwijl inloggen/registreren wel mogelijk is via de header.
- **Fix:** Voeg onder de coverage-stats een expliciete call-to-action toe voor uitgelogde bezoekers ("Log in of registreer om de bijbelboeken te doorbladeren →"), zodat de pagina nooit in een doodlopende leegte eindigt.

---

### Sterke punten

Deze zaken zijn goed doordacht en verdienen behoud, geen wijziging:

1. **Donkere modus is aantoonbaar zorgvuldig gebouwd.** Het `[data-theme="dark"]`-blok in `app.css:53-96` bevat een expliciete toelichting dat alle kleuren tegen zowel `--bg` als `--card-warm` op minimaal WCAG AA (4,5:1) zijn gecontroleerd, inclusief aparte, lichtere varianten van de koppelmethode-kleuren zodat ze op donkere achtergrond leesbaar blijven. Het thema wordt bovendien via een inline `<script>` vóór de eerste render toegepast (`base.html.twig:16`) om Flash-of-Unstyled-Content bij donkere modus te voorkomen — een detail dat vaak wordt vergeten. Dit toont een niveau van zorgvuldigheid (H13, H14) dat de rest van de tokens (zie de contrast-bevinding hierboven) nog niet overal evenaart.
2. **Het boek/hoofdstuk-navigatiepaneel is een voorbeeld van correct toegankelijk paneelgedrag.** `nav_panel_controller.js` sluit netjes op Escape, beheert `aria-expanded`/`aria-hidden` correct bij open/dicht, en scrollt het actieve boek automatisch in beeld bij openen. Dit patroon (Escape-afhandeling, ARIA-state-synchronisatie) ontbreekt op andere plekken in de app (zie de contrast/keyboard-bevindingen) en zou als sjabloon kunnen dienen om die te verbeteren.
3. **De koppelmethode-encodering is functioneel goed doordacht.** Het gebruik van zowel kleur áls lijnstijl (`solid` vs. `dashed`) om automatische van handmatige koppelingen te onderscheiden (bijv. `.method-border-auto_source_pivot` met `dashed`), gecombineerd met een consistente legenda op elke pagina die koppelmethoden toont, is een goed voorbeeld van Recognition over Recall — een nieuwe gebruiker hoeft de betekenis van elk stipje niet te onthouden, de legenda staat er steeds bij.
4. **De responsive dekking is breder dan gemiddeld voor een dergelijke datadichte tool.** Met bijna twintig losse `@media`-breekpunten (van 560px tot 1000px, `app.css`) is expliciet nagedacht over tussenliggende schermformaten (tablets, kleine laptops), niet alleen de klassieke mobiel/desktop-tweedeling — inclusief een aparte horizontaal scrollbare hoofdstuk-strip en vertaal-pillen specifiek voor mobiel (`chapter_view.html.twig:189-223`) in plaats van simpelweg de desktop-navigatie te verkleinen.
5. **Turbo Frames worden doelgericht ingezet om onnodige volledige reloads te vermijden**, bijvoorbeeld bij het laden van het Strong's-detailpaneel (`turbo-frame id="strongs-panel"`) en bij het verversen van een verstoken na een koppelactie in de Strong's-weergave (`#refreshVerseBlock()`). Dit toont dat het team het patroon kent en elders consequent toepast — de page-reload-bevinding hierboven is dus een inconsistentie tussen twee delen van dezelfde app, geen ontbrekende vaardigheid.

---

### Vervolgstappen

- Wil je dat ik een van deze bevindingen verder uitwerk of laat zien met een concreet codevoorbeeld?
- Klaar om te beginnen met fixen? Run `/frontend-design-audit:improve`, of ik implementeer nu direct de hoogste-prioriteit items.
- Liever de snelle route? Run `/frontend-design-audit:quick` om ernst 3-4 bevindingen automatisch te laten oplossen.
