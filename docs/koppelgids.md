# Stap-voor-stap koppelgids

## Wat is koppelen?

Het doel van dit systeem is om elk woord in een Nederlandse bijbelvertaling te verbinden met het Hebreeuwse of Griekse woord waaruit het is vertaald. Zo kun je voor elk Nederlands woord direct zien welk bronwoord eraan ten grondslag ligt, en omgekeerd: voor elk bronwoord zie je hoe verschillende vertalingen het weergeven.

Er zijn twee soorten koppelingen:

- **Bronkoppelingen** – verbinden een Hebreeuws of Grieks woord met een woord in de Statenvertaling (SV). Dit zijn de primaire, handmatig geverifieerde koppelingen.
- **Vertalingskoppelingen** – verbinden een woord in de SV met het overeenkomstige woord in de Herziene Statenvertaling (HSV). Omdat de HSV en SV nauw verwant zijn, kan het systeem deze koppelingen grotendeels automatisch afleiden.

De HSV heeft geen eigen directe bronkoppelingen. In plaats daarvan erft zij de bronkoppelingen van de SV via de vertalingskoppelingen. Als de SV-koppeling voor een vers klopt, en de vertalingskoppeling SV↔HSV klopt, dan weet het systeem automatisch welk HSV-woord bij welk Hebreeuws of Grieks woord hoort.

---

## Deel 1 – Eenmalige voorbereiding

### Stap 1 – Zet de database op

Zorg dat Docker actief is en dat de PostgreSQL-container draait:

```
docker start bible_postgres
```

Als de container nog niet bestaat, maak hem aan via de instructies in de `README`.

### Stap 2 – Voer de migrations uit

De migrations voegen de benodigde tabellen en kolommen toe aan de database. Voer ze in volgorde uit:

```
docker cp db\schema.sql bible_postgres:/tmp/schema.sql
docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/schema.sql

docker cp db\migrate_add_inter_translation_links.sql bible_postgres:/tmp/migrate.sql
docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate.sql
```

De tweede migration voegt toe:
- het veld `is_filler` aan vertaalwoorden (voor HSV-aanvulwoorden zoals *maar* en *toch*)
- de tabel `inter_translation_links` voor SV↔HSV-koppelingen
- de velden `family` en `source_lang_authority` aan vertalingen

### Stap 3 – Importeer de bronbestanden (Hebreeuws en Grieks)

De Hebreeuwse tekst (TAHOT) en Griekse tekst (Textus Receptus) worden ingeladen via de ingestscripts:

```
cd ingest
python parse_tahot.py          # Hebreeuws Oude Testament
python parse_greek_nt.py       # Grieks Nieuwe Testament
```

Dit vult de tabellen `hebrew_words` en `greek_words`.

### Stap 4 – Importeer de Statenvertaling (SV)

```
python parse_statenvertaling.py
```

Dit vult `translation_verses` en `translation_words` voor de SV.

### Stap 5 – Importeer de Herziene Statenvertaling (HSV)

```
python parse_herziene_statenvertaling.py
```

Dit scrapet de HSV-tekst van de website en slaat hem op. Woorden die in de HSV cursief staan (aanvulwoorden zonder direct bronwoord, zoals *maar* of *toch*) worden automatisch gemarkeerd met `is_filler = true`.

> **Let op:** als je de HSV al eerder hebt geïmporteerd vóór de migration in stap 2, moet je de import opnieuw uitvoeren zodat de `is_filler`-gegevens correct worden opgeslagen.
>
> Je kunt ook één boek opnieuw importeren om te testen:
> ```
> python parse_herziene_statenvertaling.py --book PRO
> ```

---

## Deel 2 – Bronkoppelingen aanleggen (SV ↔ Hebreeuws/Grieks)

Dit is het hoofdwerk: elk Hebreeuws of Grieks woord verbinden met de Nederlandse vertaling ervan in de SV. Dit doe je handmatig, vers voor vers.

### Stap 6 – Open de koppelinterface

Ga in de browser naar de applicatie (standaard `https://localhost`) en klik in het menu op **Koppelen**.

Je ziet een overzicht van alle bijbelboeken. Kies een boek, een hoofdstuk en een vers om te beginnen.

### Stap 7 – Koppel woorden in een vers

Op de koppelpagina zie je twee kolommen:

- **Links:** de Hebreeuwse of Griekse woorden van het vers, inclusief transliteratie, Strong's-nummer en morfologische code.
- **Rechts:** de Nederlandse woorden van de SV.

**Werkwijze:**
1. Klik op een Hebreeuws of Grieks woord aan de linkerkant. Het woord wordt gemarkeerd.
2. Klik op het bijbehorende Nederlandse woord of de bijbehorende woorden aan de rechterkant. Je kunt meerdere woorden selecteren als één bronwoord in meerdere Nederlandse woorden is vertaald (of omgekeerd).
3. Klik op **Opslaan**.
4. Herhaal voor elk bronwoord in het vers.

**Speciale gevallen:**
- Heeft een bronwoord geen Nederlandse tegenhanger (bijv. een onvertaald woordje)? Klik op **Geen koppeling** in plaats van een Nederlands woord te selecteren.
- Heeft een Nederlands woord geen bronwoord? Laat het ongeselecteerd.

De kleur van de Nederlandse woorden geeft de status van de koppeling aan:
- **Groen** – handmatig gekoppeld
- **Blauw-grijs** – automatisch gekoppeld op basis van eerder handwerk (hint)
- **Amber** – positioneel gekoppeld (zwakste methode)
- **Grijs** – nog geen koppeling

### Stap 8 – Navigeer door de verzen

Gebruik de pijlknoppen boven in de koppelinterface om naar het volgende of vorige vers te gaan. Je kunt ook direct naar een specifiek vers springen via het selectieformulier op de startpagina.

### Stap 9 – Koppelen via Strong's-nummer (alternatieve methode)

Als je alle voorkomens van een bepaald Hebreeuws of Grieks woord tegelijk wilt koppelen, gebruik dan de Strong's-koppelinterface via **Koppelen Strong's** in het menu.

1. Typ een Strong's-nummer in (bijv. `H1` voor het Hebreeuwse woord *vader*).
2. Je ziet alle verzen waarin dit woord voorkomt, met de huidige koppelingen.
3. Koppel vers voor vers op dezelfde manier als hierboven.

Bovenaan zie je een voortgangsbalk: hoeveel voorkomens zijn al handmatig gekoppeld.

---

## Deel 3 – Vertalingskoppelingen aanleggen (SV ↔ HSV)

Zodra de SV-bronkoppelingen voor een gedeelte klaar zijn, kun je de vertalingskoppelingen aanleggen. Dit zorgt ervoor dat de HSV de bronkoppelingen van de SV erft.

### Stap 10 – Automatisch koppelen

Voer het automatische koppelscript uit:

```
php bin/console app:link:translations:auto
```

Het script werkt in drie stappen per vers:

1. **Bron-pivot** – HSV-woorden die via de SV hetzelfde bronwoord delen, worden direct aan elkaar gekoppeld. Dit is de betrouwbaarste methode.
2. **Volgordeuitlijning** – de resterende woorden worden uitgelijnd op basis van gelijkenis in tekst en volgorde (Needleman-Wunsch algoritme).
3. **Positioneel** – als laatste redmiddel worden woorden gekoppeld op basis van hun positie in het vers.

Opties:
- `--dry-run` – laat zien wat het script zou doen zonder iets op te slaan.
- `--book PRO` – verwerk alleen het boek Spreuken (handig om te testen).
- `--family SV` – verwerk alleen het vertaalfamiliepaar met de opgegeven familienaam.
- `--reset` – verwijder bestaande automatische koppelingen en begin opnieuw.

> **Geheugengebruik:** het script verwerkt de volledige Bijbel (~31.000 verzen) en gebruikt streaming om geheugen te besparen. Als je toch een foutmelding krijgt over geheugengebruik:
> ```
> php -d memory_limit=512M bin/console app:link:translations:auto
> ```

### Stap 11 – Vertalingskoppelingen controleren en corrigeren

Ga in het menu naar **Vertalingen koppelen**. Je ziet een overzicht van de beschikbare vertaalparen (bijv. SV ↔ HSV).

Klik op **Beginnen** om vers voor vers te controleren.

Op de koppelpagina zie je:
- **Links:** de SV-woorden van het vers.
- **Rechts:** de HSV-woorden, inclusief aanvulwoorden in cursief (gemarkeerd met \*).
- **Onderaan:** een tabel met de huidige koppelingen en hun methode en betrouwbaarheidscore.

**Werkwijze bij correctie:**
1. Kijk de koppelingstabel door.
2. Is een koppeling fout? Klik op de **×**-knop achter die rij om hem te verwijderen.
3. Klik dan op het juiste SV-woord (links) en vervolgens op het juiste HSV-woord (rechts). De koppeling wordt automatisch opgeslagen als *handmatig*.
4. Wil je alle automatische koppelingen voor dit vers verwijderen en opnieuw beginnen? Klik op **Auto-links resetten**.

**Over aanvulwoorden (filler words):**
HSV-woorden in cursief (bijv. *maar*, *toch*) hebben geen direct Hebreeuws of Grieks bronwoord. Ze worden gemarkeerd met een sterretje (\*) en zijn bedoeld om de Nederlandse zin vloeiender te laten lopen. Je kunt ze koppelen aan een SV-woord als er een overeenkomst is, maar het is ook normaal om ze ongekoppeld te laten.

**Betrouwbaarheidskleuren:**
- **Donkerblauw (bron-pivot)** – gekoppeld via gedeeld bronwoord; meest betrouwbaar.
- **Groen (volgorde)** – gekoppeld via tekstgelijkenis en volgorde.
- **Goud (positioneel)** – positioneel gekoppeld; minst betrouwbaar, controleer altijd.
- **Groen met ✓ (handmatig)** – door een gebruiker bevestigd.

### Propagatie: gesuggereerde koppelingen voor niet-bronvertalingen

Wanneer je in de koppelinterface een vertaling opent die **niet** de bronvertaling is (bijv. HSV), worden bronwoorden zonder directe koppeling automatisch aangevuld met **gesuggereerde koppelingen**. Dit mechanisme heet propagatie.

**Hoe het werkt:**

1. De controller stelt vast of de gekozen vertaling de bronvertaling (`source_lang_authority`) is of niet.
2. Is het geen bronvertaling, dan zoekt het systeem via de `inter_translation_links`-tabel welke HSV-woorden via de SV gekoppeld zijn aan een bepaald bronwoord.
3. Bronwoorden die nog geen directe koppeling hebben, krijgen deze gesuggereerde HSV-woorden als voorgeselecteerde koppeling meegeleverd.

**Wat de gebruiker ziet:**

- De gesuggereerde Nederlandse woorden zijn al gemarkeerd (voorgeselecteerd), zodat je de koppeling met één klik op **Opslaan** kunt bevestigen.
- Suggesties zijn visueel onderscheiden van echte koppelingen.

**Technische achtergrond:**

- Gesuggereerde koppelingen hebben `link_id = null`; er bestaat nog geen rij in `word_links`.
- De methode is `'propagated'`.
- Het veld `word['propagated'] = true` in de templatedata geeft aan dat het om een suggestie gaat.
- Zodra de gebruiker op **Opslaan** klikt, wordt een echte `word_links`-rij aangemaakt met methode `'manual'`.

---

## Deel 4 – Het resultaat bekijken

### Stap 12 – Verzen bekijken in de bijbellezer

Ga naar **Bijbelboeken** in het menu, kies een boek, hoofdstuk en vers.

Je ziet:
- Het Hebreeuwse of Griekse woord met transliteratie en Strong's-nummer.
- Gekleurde indicatoren die tonen aan welke SV- en HSV-woorden het bronwoord is gekoppeld.
- Een stippellijn voor koppelingen die via de vertalingskoppeling zijn afgeleid (HSV).
- Aanvulwoorden in de HSV-kolom staan cursief.

Klik op een Strong's-nummer om de woordenboekvermelding te zien, inclusief de Nederlandse vertaling van de definitie.

### Stap 13 – Strong's-vertalingen beheren

Gebruikers met de rol **ROLE_EDIT_STRONG_TRNL** kunnen via **Vertalen Strong's** in het menu de Nederlandse vertalingen van Strong's-definities bewerken. Typ een Strong's-nummer in, pas de velden aan en sla op.

---

## Samenvatting van de volgorde

```
1. Database opzetten en migrations uitvoeren
2. Bronbestanden importeren (Hebreeuws, Grieks)
3. SV importeren
4. HSV importeren (inclusief is_filler-markering)
          ↓
5. Handmatig SV-woorden koppelen aan bronwoorden
   (per vers of per Strong's-nummer)
          ↓
6. Automatisch SV↔HSV koppelen:
   php bin/console app:link:translations:auto
          ↓
7. Vertalingskoppelingen controleren en corrigeren
   via /link/translations/
          ↓
8. Resultaat bekijken in de bijbellezer
```

---

## Rollen en toegangsrechten

| Rol                    | Toegang                                              |
|------------------------|------------------------------------------------------|
| `ROLE_VIEWER`          | Bijbellezer bekijken                                 |
| `ROLE_VIEWER_HSV`      | HSV-paneel zichtbaar in bijbellezer (leesrol, geen schrijfrechten) |
| `ROLE_LINKER`          | Bronkoppelingen en vertalingskoppelingen bewerken voor alle vertalingen |
| `ROLE_EDIT_STRONG_TRNL`| Strong's-definities vertalen naar het Nederlands     |
| `ROLE_ADMIN`           | Alle bovenstaande rollen                             |

Rollen worden toegekend via de profielpagina door een beheerder.
