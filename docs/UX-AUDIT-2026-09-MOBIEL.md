# Mobiele UX-audit — Alef–Omega

**Datum:** 2026-09-10
**Scope:** De volledige applicatie, kritisch bekeken vanuit het perspectief van een bezoeker op een telefoon.
**Methode:** Playwright + Chromium tegen de echte, draaiende app (dev-container), geëmuleerd als iPhone SE (375px) en iPhone 13 (390px), met touch-emulatie. Voor elke pagina gemeten: `window.innerWidth` vs. daadwerkelijke content-breedte, tik-doelgroottes van interactieve elementen, en visuele screenshots. Dit is geen aanvulling op `UX-AUDIT-2026-09.md` maar een aparte, mobiel-specifieke doorlichting — sommige punten overlappen bewust met eerdere bevindingen die op mobiel extra zwaar wegen.

---

### Hoe dit rapport te lezen

Bevindingen zijn ingedeeld naar prioriteit: **Kritiek** (breekt de pagina voor een deel van de bezoekers), **Belangrijk** (werkt, maar voelt op een telefoon merkbaar kapot/onhandig aan), **Klein** (irritant, geen blokkade). Elke bevinding is gemeten, niet aangenomen — de exacte cijfers staan erbij.

---

## Kritiek

### 1. `/strongs/{nummer}` is op elk apparaat een kale, ongestijlde pagina zonder navigatie

**Gemeten:** geen `<meta name="viewport">` aanwezig; de pagina bestaat uit ongestileerde platte tekst zonder header, zonder lettertypen, zonder terug-link.

Deze route is bedoeld om **binnen** een Turbo-Frame geladen te worden (`data-turbo-frame="strongs-panel"`, zie `bible/verse_frame.html.twig`). Wie er *rechtstreeks* naartoe navigeert — een gedeelde link, een bladwijzer, een zoekmachine-resultaat, of simpelweg een lange druk op een Strong's-nummer die "open in nieuw tabblad" triggert — krijgt de kale binnenkant van dat paneel te zien, zonder pagina-shell. Op een telefoon (geen hover, geen adresbalk-context zoals op desktop) is dit een doodlopende weg: geen manier om terug te komen bij de bijbeltekst.

**Fix:** laat de Strong's-controller detecteren of het verzoek van een Turbo Frame komt (Symfony: check de `Turbo-Frame`-request-header) en render in het andere geval de volledige pagina (extend `base.html.twig`, inclusief navigatie en een "terug naar vers"-link).

### 2. Verskoppelpagina (los vers, "volgend vers →") rendert 20% breder dan het scherm

**Gemeten:** op een iPhone 13 (390px) meet `window.innerWidth` op `/book/GEN/1/1/SV` **468px** in plaats van 390px — de pagina laadt zichtbaar uitgezoomd, tekst ~17% kleiner dan bedoeld.

**Root cause:** `.verse-compare-grid { grid-template-columns: 1fr 1fr }` valt bij `max-width:1000px` terug op `grid-template-columns: 1fr` — maar een CSS Grid-track van `1fr` heeft standaard een impliciete `min-width: auto`, niet `0`. De grondtekstwoorden in `.source-words` (flex-wrap) hebben samen genoeg minimale breedte om die ene kolom (en dus de hele pagina) breder te duwen dan het scherm, ondanks de "stack to 1 column"-regel.

**Fix:** `.verse-compare-grid, .compare-col { min-width: 0; }` (of `grid-template-columns: minmax(0, 1fr)`), zodat de kolom wél mag krimpen tot het beschikbare scherm.

### 3. Tabellen zonder scroll-wrapper laten de hele pagina uitzoomen (Gebruikersbeheer, Historische alignment-bibliotheek)

**Gemeten:** `/admin/users` → `window.innerWidth` = **1098px** (i.p.v. 390); `/link/translations/historical/library` → **638px**. Beide tabellen (`.admin-table`, `.hist-align-overview-table`) hebben geen `overflow-x: auto`-container om zich heen.

Dit is geen simpele horizontale scrollbalk op de tabel — de **hele pagina** (header, knoppen, alle tekst) zoomt mee uit, omdat er niets is dat de tabel begrenst. Een beheerder die op de telefoon een gebruiker wil verwijderen moet eerst pinch-zoomen en dan nauwkeurig een piepklein "Verwijderen"-linkje raken.

**Fix:** wrap elke brede tabel in `<div class="table-scroll" style="overflow-x:auto">…</div>` — dit begrenst de tabel zelf tot een scrollbare strook zonder de rest van de pagina op te blazen. Eén herbruikbare CSS-klasse volstaat voor beide tabellen (en toekomstige).

---

## Belangrijk

### 4. De hamburgerknop zelf is kleiner dan het aanbevolen tikdoel

**Gemeten:** `.nav-hamburger` is **38×30px** — de enige toegang tot navigatie, zoekfunctie-vervanging en het thema op elke mobiele pagina, onder de aanbevolen 44×44px.

**Fix:** vergroot de klikbare padding rond de drie streepjes (de visuele grootte van het icoon hoeft niet te veranderen, alleen de hitbox).

### 5. De "×"-verwijderchip op de koppelpagina is te klein om precies te raken

**Gemeten:** `.link-chip` (de "×" om één koppeling te verwijderen, zie vorige sessie) meet op mobiel nog steeds **12×21px** — de eerdere tikdoel-vergroting gold voor `.src-word`/`.nl-word` zelf, niet voor de losse chips ertussenin. Bij een woord met 2-3 koppelingen naast elkaar (zoals vaak het geval) is het risico op het per ongeluk raken van de verkeerde chip reëel — precies de functie die in de vorige sessie is gerepareerd, wordt hier weer lastig te bedienen.

**Fix:** vergroot de tikzone van `.link-chip` op mobiel (bijv. via een grotere onzichtbare `padding` of `::before`-hitbox) zonder de visuele "×" groter te maken.

### 6. Het Strong's-linkje in de woord-popup is kleiner dan het tikdoel

**Gemeten:** `.word-strongs` (bijv. "H7225" in de popup op de verskoppelpagina) meet **~54×22px** — breedte is prima, hoogte zit op de helft van de richtlijn.

**Fix:** verticale padding verhogen op mobiele breakpoints; lage prioriteit, het is een secundaire link binnen een al-geopende popup.

---

## Klein

### 7. Login-/wachtwoordveld-checkbox ("Onthoud mij") is een kaal 13×13px-vinkje

Standaard OS-checkbox-rendering; het `<label>`-tekst ernaast is gelukkig ook klikbaar, wat het grootste deel van het probleem al opvangt. Overweeg alsnog een iets groter aangepast selectievakje met CSS als je toch de auth-pagina's ooit herontwerpt (zie ook de bevinding over gedupliceerde auth-CSS uit het vorige rapport).

### 8. Kleine badge/link-elementen op de boekenlijst en gebruikersbeheer-tabel

Enkele decoratieve of secundaire linkjes (hoofdstukaantal-badge op de boekindex, "Bewerken"/"Verwijderen" in de admin-tabel) vallen net onder de 24px-grens. Op zichzelf klein bier vergeleken met punt 3 hierboven (die dezelfde tabel al veel dringender treft), maar het loont om na fix #3 opnieuw te meten of deze dan vanzelf groot genoeg worden (ze zitten nu al verdrukt door de te-brede-pagina van bevinding 3).

---

## Sterke punten (bevestigd, niet aannemen)

1. **Viewport-meta correct op de hoofdpaginas.** Home, boekenlijst, hoofdstukweergave, koppel-startpagina en Strong's-koppelpagina renderen allemaal exact op `window.innerWidth = 390/375` zoals bedoeld — geen ongewenste uitzoom. De problemen hierboven zijn uitzonderingen, niet de regel.
2. **Pinch-zoom is nergens geblokkeerd.** Geen `user-scalable=no` of `maximum-scale=1` gevonden — gebruikers die willen inzoomen kunnen dat altijd, ook als een van bovenstaande bugs dat eigenlijk al nodig maakt.
3. **Het hamburgermenu zelf is degelijk gebouwd.** Dropdowns ("Editor ▾", "Beheer ▾", "Vertalingen ▾") klappen bij tik netjes *inline* open binnen het menu (geen onzichtbare hover-afhankelijkheid), met ruime tikdoelen per item. Het boek/hoofdstuk-kiespaneel (kolom met boeken + hoofdstukraster) werkt vlot en overzichtelijk op een smal scherm.
4. **De dedicated mobiele UI voor de hoofdstukweergave** (horizontale hoofdstukstrip, vertaal-pillen) is een bewuste, goede aanpassing — geen kale verkleinde desktopnavigatie, maar een op maat gemaakt mobiel patroon.
5. **De uit de vorige sessie doorgevoerde fixes houden stand op mobiel**: focus/tooltip-gedrag, de gestileerde bevestigingsdialoog en de homepage-CTA zijn ook op een telefoon-viewport correct getest.

---

## Aanbevolen volgorde

1. Bevinding 1 (Strong's-pagina) en 3 (tabellen) eerst — beide zijn functionele blokkades, niet alleen esthetisch.
2. Bevinding 2 (verskoppelpagina-overflow) — raakt gewone lezers die vers-voor-vers doorklikken.
3. Bevindingen 4–6 (tikdoelen) — kleine, losse CSS-aanpassingen, samen te pakken.
4. Bevinding 7–8 — meenemen bij een toekomstige designpas, geen aparte actie nodig.
