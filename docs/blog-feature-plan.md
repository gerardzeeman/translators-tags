# Blogmodule — implementatieplan

Status: **plan, nog niet geïmplementeerd**
Branch: `feature/blog-module`

## Doel

Een blogfunctie toevoegen aan Alef-Omega:

- `/blog/` — overzicht van geplaatste blogs, nieuwste eerst.
- `/blog/maken/` — blogs schrijven in Markdown.
- `/blog/{slug}/` — losse blogpost, bijv. titel "Voorbeeld" → `/blog/voorbeeld/`.
- In de Markdown-tekst kun je twee soorten "modules" embedden:
  1. Een **bijbelvers in de grondtaal** (Hebreeuws/Grieks), met opties.
  2. Een **stuk tekst uit de Institutio** (Latijn/Nederlands), met opties.

Beslissingen die al vastliggen (bevestigd door gebruiker):

- **Zichtbaarheid is per blog instelbaar**: elke blog heeft een zichtbaarheid
  `publiek` (iedereen, ook niet-ingelogd) of `alleen ingelogd` (bestaande
  `ROLE_USER`-muur).
- **Nieuwe rol `ROLE_BLOGGER`** mag blogs aanmaken/bewerken op `/blog/maken/`.
- **Concept → publiceren-flow**: een nieuwe blog is een concept totdat hij
  expliciet gepubliceerd wordt; alleen gepubliceerde blogs verschijnen op
  `/blog/`.
- **Gedeeltelijke selectie**: bij zowel het bijbelvers- als het Institutio-
  embed kan niet alleen een heel vers/hele sectie gekozen worden, maar ook een
  deel ervan (een woordbereik in een vers; een zin, of een deel van een zin,
  in een Institutio-sectie).
- **Afbeeldingen** kunnen in een blog gezet worden via upload vanaf de eigen
  computer, niet alleen door een URL in te typen.

---

## 1. Datamodel

Nieuwe Doctrine-entiteit `Blog` (volgt het patroon van `User`/`Book`: ORM-entity
+ migratie in `app/migrations`, in tegenstelling tot de Bijbel/Institutio-data
die los via DBAL-repositories loopt — blogs zijn app-eigen content, dus ORM
past hier beter).

```
Blog
├── id            int, PK
├── title         string(255)
├── slug          string(255), unique, index
├── contentMd     text                     — de ruwe Markdown-brontekst
├── status        string: 'draft' | 'published'
├── visibility    string: 'public' | 'logged_in'
├── author        ManyToOne → User
├── createdAt     datetime_immutable
├── updatedAt     datetime_immutable
└── publishedAt   datetime_immutable, nullable
```

**Slug-generatie**: titel → lowercase, spaties/leestekens → `-`, NL-diakrieten
getranslitereerd (bv. "ë" → "e"). Bij botsing wordt `-2`, `-3`, ... achter de
slug geplakt. Slug wordt éénmalig bepaald bij het aanmaken (op basis van de
titel op dat moment) en verandert niet meer automatisch als de titel later
wijzigt — anders breken bestaande links. Handmatig aanpassen van de slug kan
op de bewerkpagina, met dezelfde botsingscontrole.

---

## 2. Rollen & toegang

`config/packages/security.yaml`:

```yaml
role_hierarchy:
    ROLE_BLOGGER: [ROLE_VIEWER]
    ROLE_ADMIN:   [..., ROLE_BLOGGER]   # admins kunnen ook altijd bloggen

access_control:
    - { path: ^/blog/maken,      roles: ROLE_BLOGGER }
    - { path: ^/blog/.+/bewerken, roles: ROLE_BLOGGER }
    - { path: ^/blog,            roles: PUBLIC_ACCESS }   # zichtbaarheid per blog, zie hieronder
    - { path: ^/,                roles: ROLE_USER }        # bestaande catch-all, ongewijzigd
```

Volgorde is belangrijk: de specifieke `/blog/maken` en `/blog/.../bewerken`
regels moeten vóór de generieke `^/blog` regel staan, anders wint de bredere
regel.

`ROLE_BLOGGER` wordt, net als de bestaande rollen, toekenbaar via
`/admin/users` (uitbreiden van `AdminUserController::ASSIGNABLE_ROLES`).

**Per-blog zichtbaarheid** wordt *niet* via `access_control` afgedwongen (dat
kan niet per database-rij), maar in `BlogController::show()`:

- blog niet gevonden of niet gepubliceerd → 404 (tenzij eigenaar/admin, die
  mag een concept altijd zien als voorbeeld).
- `visibility: logged_in` + bezoeker niet ingelogd → redirect naar login
  (zelfde `app_login`-route, met `?_target_path` terug naar de blog).
- overige gevallen → gewoon tonen.

Op `/blog/` (index) worden voor niet-ingelogde bezoekers alleen
`published` + `public` blogs getoond; voor ingelogde bezoekers ook
`published` + `logged_in`.

---

## 3. Routes & controller

Nieuwe `BlogController`:

| Methode | Route | Naam | Rol |
|---|---|---|---|
| GET | `/blog/` | `app_blog_index` | publiek (gefilterd, zie boven) |
| GET | `/blog/maken/` | `app_blog_new` | `ROLE_BLOGGER` |
| POST | `/blog/maken/` | `app_blog_create` | `ROLE_BLOGGER` |
| GET | `/blog/{slug}/bewerken/` | `app_blog_edit` | `ROLE_BLOGGER` |
| POST | `/blog/{slug}/bewerken/` | `app_blog_update` | `ROLE_BLOGGER` |
| POST | `/blog/{slug}/publiceren/` | `app_blog_publish` | `ROLE_BLOGGER` |
| GET | `/blog/{slug}/` | `app_blog_show` | publiek (per-blog check) |

Formulieren volgen het bestaande patroon in `AdminUserController` (platte
HTML-forms + handmatige `isCsrfTokenValid()`-check), niet het Symfony Form
component — dat wordt nergens anders in deze codebase gebruikt.

Eigenaarschap: een `ROLE_BLOGGER`-gebruiker kan (in eerste instantie) alleen
eigen blogs bewerken; `ROLE_ADMIN` kan alle blogs bewerken. Dit is een keuze
die makkelijk te verruimen is als co-auteurschap gewenst blijkt.

---

## 4. Markdown → HTML

Nieuwe dependency: `league/commonmark` (niet aanwezig in `composer.json`,
wordt toegevoegd). Rendering gebeurt in een nieuwe `BlogMarkdownRenderer`
service, niet inline in de controller, zodat dezelfde renderer te gebruiken is
voor zowel de opgeslagen blog als een live voorbeeldweergave in de editor.

**Veiligheid**: CommonMark wordt geconfigureerd met `html_input: 'strip'`
(ruwe HTML in de Markdown-brontekst wordt genegeerd, niet doorgelaten).
Blogauteurs zijn weliswaar ingelogde `ROLE_BLOGGER`-gebruikers, maar omdat een
blog `public` gezet kan worden — dus door willekeurige anonieme bezoekers
gelezen kan worden — behandelen we de Markdown-inhoud niet als vertrouwde HTML.
Dit voorkomt stored-XSS via de blogtekst.

---

## 5. Embedmodules — ontwerp

### 5.1 Syntax in de Markdown-brontekst

Beide modules worden weggeschreven als een **fenced code block** met een
herkenbare info-string, en simpele `sleutel: waarde`-regels erin (geen volledige
YAML-parser nodig, alleen line-based key:value parsing — voorkomt een extra
dependency). Dit blijft leesbaar en met de hand aan te passen, in
tegenstelling tot bijvoorbeeld een base64-blob:

````
```bijbelvers
boek: Genesis
hoofdstuk: 1
vers: 1
woord_van: 1
woord_tot: 5
aantal_verzen: 1
toon_vertaling: ja
vertaling: HSV
alleen_vertaling: nee
highlight_links: ja
layout: naast-elkaar
```
````

````
```institutie
referentie: Inst. 1.1.1
zin_van: 1
zin_tot: 1
teken_van:
teken_tot:
aantal: 1
taal: beide
layout: naast-elkaar
```
````

Optiebetekenis (rechtstreeks uit de vraag overgenomen):

| Optie | Waarden | Betekenis |
|---|---|---|
| `toon_vertaling` | ja/nee | vertaling onder/naast de grondtekst tonen |
| `vertaling` | SV / HSV / ... | welke vertaling, alleen relevant als `toon_vertaling: ja` |
| `alleen_vertaling` | ja/nee | als ja: alleen de vertaling tonen, geen grondtekst (overrides `toon_vertaling`) |
| `highlight_links` | ja/nee | koppelingen tussen grondtaalwoorden en vertaalwoorden highlighten (hergebruikt bestaande hover/click-logica uit `source_word_controller.js` / `dutch_word_controller.js`) |
| `woord_van` / `woord_tot` | int ≥ 1, 1-based | **nieuw**: beperkt het vers tot een woordbereik (positie in de grondtekst-woordenlijst van dat vers), i.p.v. het hele vers. Standaard: hele vers. Alleen geldig als `aantal_verzen: 1` (zie § 5.2). |
| `aantal_verzen` | int ≥ 1 | meerdere hele verzen na elkaar tonen, startend bij `boek/hoofdstuk/vers` |
| `layout` | naast-elkaar / onder-elkaar | grondtekst en vertaling naast elkaar (kolommen) of onder elkaar |
| `taal` (Institutio) | latijn / nederlands / beide | welke taal/talen getoond worden |
| `zin_van` / `zin_tot` (Institutio) | int ≥ 1, 1-based | **nieuw**: beperkt de sectie tot een bereik van zinnen (op basis van de bestaande zin-voor-zin-uitlijning, zie § 5.2), i.p.v. de hele sectie. Standaard: alle zinnen. |
| `teken_van` / `teken_tot` (Institutio) | int, tekenpositie binnen de geselecteerde zin(nen) | **nieuw, optioneel**: knipt een deel van een zin uit (bv. een bijzin), verfijnder dan `zin_van`/`zin_tot`. Alleen bruikbaar als `taal: latijn` óf `taal: nederlands` (één taal tegelijk) — bij `taal: beide` is deze optie niet beschikbaar. De tekenpositie verwijst naar de tekst van de gekozen taal (zie § 5.2). |
| `aantal` (Institutio) | int ≥ 1 | meerdere hele secties na elkaar, startend bij `referentie` (niet te combineren met `zin_van`/`zin_tot`) |

### 5.2 Renderer

Een CommonMark-extensie (`BibleVerseEmbedExtension`, `InstitutioEmbedExtension`)
herkent deze fenced blocks aan hun info-string, parseert de key:value-body, en
vervangt het blok bij render-time door HTML — opgebouwd via `TwigEnvironment`
met een nieuw, compact partial-template per module:

- `templates/blog/embed/_bijbelvers.html.twig`
- `templates/blog/embed/_institutie.html.twig`

Deze partials zijn *nieuw en op zichzelf staand* (geen pagina-chrome zoals nav
of statistieken) maar hergebruiken zoveel mogelijk bestaande rendering-
bouwstenen:

- Voor bijbelverzen: dezelfde databron als `BibleController::verse()`
  (`PassageRepository::fetchPassageBatch()` + `MorphologyParser`), en
  dezelfde HTML-structuur/CSS-klassen als in `templates/bible/verse_frame.html.twig`
  voor grondtekst-woorden + links, zodat highlighting-styling/JS herbruikbaar
  is. `aantal_verzen > 1` itereert met de bestaande verse-count/navigatie-
  helpers uit `BibleController` om chapter-overgangen correct af te handelen.
  **Woordbereik** (`woord_van`/`woord_tot`): `source_words` is al een
  geordende array per vers (zie `BibleController::verse()`), dus het bereik is
  simpelweg een array-slice op positie. De grondtekst toont dan alleen die
  woorden; als `toon_vertaling: ja` (of `alleen_vertaling: ja`) wordt de
  vertaling beperkt tot de vertaalwoorden die aan *een van de geselecteerde
  grondtaalwoorden* gekoppeld zijn (dezelfde `dutch_links`/`word_links`-data
  die de reguliere verspagina al gebruikt) — er wordt dus niets nieuws
  berekend, alleen het bestaande koppelmodel als filter toegepast. Omdat een
  woordbereik per definitie bij één vers hoort, is `woord_van`/`woord_tot`
  alleen geldig bij `aantal_verzen: 1`; de renderer negeert het (met een
  zichtbare melding in de embed-output) als beide tegelijk gezet zijn.
- Voor Institutio: `InstitutioRepository` (een kleine nieuwe methode
  `findSegmentByRef(string $ref): ?array` naast de bestaande
  `getChapter()`/`getSegmentForEdit()`, om een los segment op te zoeken zonder
  de hele edit-payload op te halen), plus dezelfde Latijn/Nederlands-weergave
  als `templates/institutio/verse_panel.html.twig`.
  **Zinsbereik** (`zin_van`/`zin_tot`): hergebruikt de bestaande
  `sentence_alignment`-rijen en de al aanwezige `resolveAlignedRows()`-logica
  in `InstitutioRepository` (nu gebruikt voor de zin-voor-zin-weergave op
  `/institutie` en de vertaal-editor) — die geeft per zin al een
  `{la_text, nl_text}`-paar terug op basis van `la_start`-tekenposities in
  `segment.text_la`. `zin_van`/`zin_tot` selecteert simpelweg een deelbereik
  van die al-berekende rijen; er is dus geen nieuwe zinsherkenning nodig.
  **Tekenbereik binnen een zin** (`teken_van`/`teken_tot`, optioneel): knipt
  een substring uit de geselecteerde zin(nen) op basis van tekenposities.
  **Alleen bruikbaar bij één taal tegelijk** (`taal: latijn` of
  `taal: nederlands`) — niet bij `taal: beide`, omdat er geen betrouwbare
  teken-naar-teken-koppeling tussen Latijn en Nederlands bestaat (de
  SimAlign-woordkoppeling is token-niveau en niet exact genoeg om een
  uitsnede in de ene taal naar de andere te vertalen — zie
  `ingest/institutio/README.md`, sectie "Word alignment"). De renderer
  negeert `teken_van`/`teken_tot` (met een zichtbare melding) als `taal:
  beide` toch gecombineerd wordt met een tekenbereik.
  Welke tekst het tekenbereik precies snijdt, hangt dus af van `taal`:
  - `taal: latijn` → tekenposities in `la_text`, gesnapt op de dichtstbijzijnde
    woordgrens via de al opgeslagen `token.char_start`/`token.char_end`
    (precies, want Latijnse tokens zijn al met char-offsets ingeladen).
  - `taal: nederlands` → tekenposities in `nl_text` (de Nederlandse
    zinstekst uit `sentence_alignment`), gesnapt op woordgrenzen die
    on-the-fly bepaald worden met een eenvoudige whitespace/
    leestekenregex — er is geen opgeslagen tokentabel voor de Nederlandse
    vertaling (die bestaat alleen voor de Latijnse brontekst), dus dit
    knippen gebeurt puur op de tekst zelf, onafhankelijk van de
    Latijn-uitlijning.

Fouten (onbekend boek, vers bestaat niet, referentie niet gevonden) renderen
een zichtbare foutmelding in de blogtekst zelf (bv. "Vers Gen 1:1 niet
gevonden") in plaats van de hele pagina te laten crashen — een blog kan zo
nooit onrenderbaar worden door een tikfout in de embed-config.

### 5.3 Invoegen via dropdowns (editor-UI)

Op `/blog/maken/` en de bewerkpagina staat naast het Markdown-tekstvak een
Stimulus-controller (`blog_editor_controller.js`) met twee knoppen:
**"Bijbelvers invoegen"** en **"Institutie-tekst invoegen"**. Elke knop opent
een `<dialog>` met de bijbehorende opties als formuliervelden:

- Bijbelvers-dialog: boek/hoofdstuk/vers-dropdowns, cascaderend net als de
  bestaande `passage_select_controller.js` / `strongs_select_controller.js`
  (die logica wordt hergebruikt, niet opnieuw uitgevonden) + checkboxes/
  selects voor de overige opties uit de tabel hierboven. Zodra een vers
  gekozen is, haalt de dialog (via een klein AJAX-endpoint dat de bestaande
  `PassageRepository`-data teruggeeft) de woorden van dat vers op en toont ze
  als aanklikbare "chips" in volgorde; de gebruiker klikt het eerste en het
  laatste woord van het gewenste bereik aan (of laat het hele vers staan) om
  `woord_van`/`woord_tot` te bepalen. Dit bereik-kiezen wordt uitgeschakeld
  zodra `aantal_verzen > 1` gezet wordt, en omgekeerd (zie beperking § 5.2).
- Institutie-dialog: boek/hoofdstuk/sectie-dropdowns (zelfde idee als de
  bestaande Institutio-navigatie in `_nav_panel.html.twig`) + taal/layout-opties.
  Na het kiezen van een sectie toont de dialog de zinnen van die sectie (via
  `findSegmentByRef()` + `resolveAlignedRows()`) als een lijst met checkboxes
  (Latijn + Nederlandse vertaling ernaast, ter oriëntatie) waaruit
  `zin_van`/`zin_tot` gekozen wordt. Zodra de `taal`-keuze in de dialog op
  "alleen Latijn" of "alleen Nederlands" staat, wordt binnen een enkel
  geselecteerde zin de bijbehorende tekst (Latijn resp. Nederlands) los
  getoond in een tekstveld waarin de gebruiker met de muis een deel kan
  selecteren (normale tekstselectie, net als op een webpagina) — die
  selectie wordt omgezet naar `teken_van`/`teken_tot`, gesnapt op
  woordgrenzen in de gekozen taal (zie § 5.2). Zet de gebruiker `taal` terug
  op "beide", dan verdwijnt dit tekstselectieveld en wordt een eventueel al
  gekozen tekenbereik weer gewist — de twee opties sluiten elkaar uit.

Bij bevestigen schrijft de controller het fenced-codeblok (met de gekozen
waarden ingevuld) op de cursorpositie in de textarea. De gebruiker hoeft de
syntax dus nooit met de hand te typen — die is puur het "opslagformaat".

### 5.4 Voorbeeldweergave (preview)

Fase-2/stretch: een "Voorbeeld"-knop die de huidige Markdown-inhoud naar een
nieuw endpoint `POST /blog/preview/` stuurt (`ROLE_BLOGGER`, geen opslag) en
de gerenderde HTML in een paneel toont, via dezelfde `BlogMarkdownRenderer`.
Niet strikt nodig voor een werkende MVP; wel aan te raden omdat embeds anders
pas na opslaan zichtbaar zijn.

---

## 6. Afbeeldingen uploaden

Standaard Markdown-afbeeldingsyntax (`![alt-tekst](url)`) wordt door
CommonMark al gewoon gerenderd, zonder extra extensie. Wat ontbreekt is een
manier om een lokaal bestand te uploaden in plaats van zelf een URL te
moeten intikken.

- **Nieuw endpoint**: `POST /blog/afbeeldingen/upload` (`ROLE_BLOGGER`,
  multipart file upload, CSRF-beveiligd net als de andere formulieren).
  Retourneert JSON `{ "url": "/uploads/blog/2026/07/<hash>.webp" }`.
- **Validatie**: alleen `image/jpeg`, `image/png`, `image/webp`, `image/gif`
  — gecontroleerd op basis van de daadwerkelijke bestandsinhoud
  (`UploadedFile::guessExtension()`/gedetecteerde mimetype), niet op de
  clientnaam of extensie. Maximumgrootte (bijv. 5 MB) wordt serverside
  afgedwongen.
- **Opslag**: `public/uploads/blog/{jaar}/{maand}/`, met een
  gegenereerde bestandsnaam (hash-gebaseerd) — de originele bestandsnaam
  wordt nooit als pad gebruikt, om path-traversal en overschrijven van
  bestaande bestanden uit te sluiten. Bestanden in `public/` worden door
  FrankenPHP/Caddy als statisch bestand geserveerd, niet als PHP uitgevoerd.
- **Editor-UI**: een derde knop/bestandsveld ("Afbeelding uploaden") in
  `blog_editor_controller.js`. Na upload wordt `![alt-tekst](url)` op de
  cursorpositie ingevoegd; de alt-tekst wordt gevraagd via een simpel
  prompt-veld (belangrijk voor toegankelijkheid), met de bestandsnaam als
  fallback.
- **Niet in scope voor v1**: een mediabibliotheek-overzicht, het opruimen van
  ge-uploade maar nooit gebruikte afbeeldingen ("orphans"), en
  beeldbewerking (bijsnijden/verkleinen) — de browser levert het bestand
  zoals het is, alleen het opslagformaat/mimetype wordt gevalideerd.

---

## 7. Fasering

1. **Basis CRUD**: `Blog`-entity + migratie, `ROLE_BLOGGER`, routes, lijst-,
   aanmaak- en bewerkpagina zonder embed-ondersteuning (platte Markdown →
   HTML, geen modules). Concept/publiceren-flow werkend.
2. **Markdown-pipeline**: `league/commonmark` toevoegen, `BlogMarkdownRenderer`
   service, XSS-afweging (`html_input: strip`) verifiëren met een test.
3. **Bijbelvers-embed**: fenced-block-extensie, `_bijbelvers.html.twig`
   partial, dropdown-picker-UI inclusief het woordbereik-kiezen
   (`woord_van`/`woord_tot`).
4. **Institutio-embed**: zelfde voor de Institutio-tekst, inclusief het
   zinsbereik (`zin_van`/`zin_tot`) en de optionele tekenbereik-uitsnede
   (`teken_van`/`teken_tot`).
5. **Afbeeldingen**: upload-endpoint + editor-integratie (zie § 6).
6. **Polish**: voorbeeldweergave, styling van `/blog/`-overzicht en losse
   blogpagina, tests (`BlogControllerTest`, markdown-renderer unit tests,
   Vitest voor de nieuwe Stimulus-controller).

---

## 8. Openstaande aannames (graag bevestigen of corrigeren)

- **Bewerken/verwijderen**: een `ROLE_BLOGGER` mag alleen eigen blogs
  bewerken; `ROLE_ADMIN` mag alles. Geen gedeeld auteurschap in de eerste
  versie.
- **`aantal_verzen`/`aantal` over hoofdstukgrenzen heen**: bij het bereiken
  van het einde van een hoofdstuk wordt automatisch doorgelopen naar het
  volgende hoofdstuk (zelfde navigatielogica als de bestaande verse-navigatie),
  tenzij dit ongewenst is.
- **Woordbereik + meerdere verzen niet combineerbaar**: `woord_van`/
  `woord_tot` werkt alleen bij een enkel vers (`aantal_verzen: 1`); voor een
  bereik van meerdere verzen worden ze altijd volledig getoond. Zie ook de
  vergelijkbare beperking bij Institutio: `zin_van`/`zin_tot` (en
  `teken_van`/`teken_tot`) zijn niet te combineren met `aantal` (meerdere
  secties).
- **Latijn/Nederlands-uitsnede bij Institutio** *(opgelost)*: `teken_van`/
  `teken_tot` is alleen bruikbaar als er één taal getoond wordt
  (`taal: latijn` of `taal: nederlands`); bij `taal: beide` is een
  tekenbereik niet beschikbaar in de picker en wordt het genegeerd als het
  toch handmatig in de Markdown gezet is. Zie § 5.2 voor hoe het tekenbereik
  per taal anders gesnapt wordt (Latijn via de opgeslagen token-offsets,
  Nederlands via woordgrenzen die live op de tekst bepaald worden).
- **Afbeeldingen**: max. 5 MB, alleen jpeg/png/webp/gif, geen
  mediabibliotheek/cleanup in v1 (zie § 6) — pas aan als andere limieten
  gewenst zijn.
- **Verwijderen van een blog**: harde delete door de eigenaar/admin, geen
  aparte prullenbak-status. Kan aangepast worden als soft-delete gewenst is.

Als een van deze aannames niet klopt, graag aangeven — dan wordt de plan vóór
implementatie bijgewerkt.
