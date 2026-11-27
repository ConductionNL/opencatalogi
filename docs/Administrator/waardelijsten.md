---
description: >-
  Waar de waardelijsten en de weigeringsgronden van OpenCatalogi vandaan komen,
  en hoe een integrator elke URI op één plek vindt.
---

# Waardelijsten en weigeringsgronden

## Waar de TOOI- en DiWoo-lijsten staan

De informatiecategorieën, de soorten handeling, de documentsoorten en de talen
staan in het vocabulaireregister van OpenRegister, niet in OpenCatalogi zelf. Er is
geen meegeleverde kopie meer: elke keuzelijst, elke controle bij het opslaan en elk
sitemapveld leest dezelfde lijst uit dat register.

| Lijst | Schema in het register | Bron (versie die DiWoo 0.9.8 noemt) |
| --- | --- | --- |
| Informatiecategorieën | `https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen` | `scw_woo_informatiecategorieen`, versie 4 |
| Documentsoorten | `https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten` | `ccw_plooi_documentsoorten`, versie 7 |
| Soort handeling | `https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen` | `ccw_plooi_documenthandelingen`, versie 2 |
| Talen | `https://identifier.overheid.nl/tooi/def/thes/xtrn/taal` | `scw_talen`, versie 1 |
| Organisaties | `https://identifier.overheid.nl/tooi/set/rwc_..._compleet` (gemeenten, provincies, waterschappen, ministeries, zbo's) | de TOOI-registers van organisaties |

De bronnen zijn de JSON-downloads van de TOOI-publicatie op
`repository.officiele-overheidspublicaties.nl/waardelijsten/`.

Een informatiecategorie houdt in OpenCatalogi haar eigen code (`infocat001` tot en
met `infocat018`) en haar eigen kop, zoals "Jaarplannen en jaarverslagen". Of een
categorie bestaat, en of ze vervallen is, bepaalt het register. Het label van TOOI
zelf ("jaarplan of jaarverslag") wordt ook herkend.

### Dagelijkse verversing

De achtergrondtaak `TooiSchemeRefresh` draait eenmaal per dag. Per lijst downloadt
ze de bron en importeert die in het vocabulaireregister. Een nieuwe waarde van TOOI
is zo de volgende dag te kiezen, zonder nieuwe release van OpenCatalogi.

- De bronnen staan in de app-instelling `tooi_scheme_sources`, een JSON-lijst van
  `{key, scheme, url}`. Zonder die instelling gelden de bronnen uit de tabel hierboven.
- Per lijst bewaart de taak het resultaat in `tooi_scheme_refresh`: het moment,
  hoeveel waarden nieuw, bijgewerkt of vervallen zijn, en een eventuele fout. Dat
  moment staat ook als `lastRefreshAt` bij elke regel van `GET /api/value-lists`.
- Een download die mislukt of niet te lezen is, importeert niets. Een import die
  meer dan een derde van de geldige waarden van een lijst zou laten vervallen, wordt
  ook geweigerd: een afgebroken antwoord mag geen lijst leegmaken waar publicaties
  naar verwijzen. In beide gevallen blijft de lijst zoals ze was, staat de reden in
  `tooi_scheme_refresh` en ontstaat een incident van het soort `value-list-refresh`.
- De informatiecategorieën worden alleen ververst zolang OpenRegister
  audittrails bijhoudt. Elke wijziging van een categorie staat dan in de audittrail,
  met het oude en het nieuwe label. Staan audittrails uit, dan blijft de lijst van
  categorieën staan en meldt de taak waarom; de andere lijsten worden gewoon ververst.

### Vervallen waarden

Laat TOOI een waarde vervallen, dan blijft ze werken voor de publicaties die haar al
hebben: de sitemap noemt ze nog en een oude publicatie blijft geldig. Als nieuwe
waarde wordt een vervallen waarde geweigerd.

### Als het register niet te lezen is

Kan OpenCatalogi het vocabulaireregister niet lezen, bijvoorbeeld omdat
OpenRegister niet bereikbaar is, dan valt het nergens op een kopie terug:

- de sitemap laat de categorie, de documentsoort, de soort handeling of de taal
  weg en meldt dat het veld niet is opgelost;
- een publicatie met een documentsoort wordt bij het opslaan geweigerd;
- `GET /api/value-lists` noemt de vier lijsten in `unavailable`;
- `GET /api/woo/documentsoorten` antwoordt met 503 `value-list-unavailable`.

Op een nieuwe installatie staan de documentsoorten en de talen pas in het register
na de eerste run van `TooiSchemeRefresh`. Tot die tijd zijn die lijsten leeg. Draai
de taak direct met `occ background-job:execute --force-execute <id>` om niet op cron
te wachten.

## Weigeringsgronden

De weigeringsgronden van de Woo (artikel 5.1 en 5.2) zijn van dossiq. Is dossiq
geïnstalleerd, dan leest OpenCatalogi de lijst daar: een medewerker kiest bij een
beoordeling alleen een grond uit die lijst, en een opgeslagen beoordeling met een
andere code wordt geweigerd. Kan dossiq zijn lijst niet lezen, dan wordt het
opslaan geweigerd met de reden; OpenCatalogi valt dan niet terug op een eigen kopie.

Zonder dossiq gebruikt OpenCatalogi een vaste kopie van de lijst die dossiq bij een
release meelevert (`lib/Settings/woo-refusal-grounds.snapshot.json`). Die kopie dient
één doel: een grond noemen bij een gelakte passage van een publicatiebatch. U kunt de
gronden dan kiezen, maar niet wijzigen. De Woo-instellingen zeggen dit onder
**Weigeringsgronden**. Een Woo-verzoek gebruikt de kopie nooit.

Bij de upgrade zet de herstelstap `MapStoredRefusalGrounds` de gronden die al op
beoordelingen staan om naar de codes van dossiq. Vijf eerdere codes hadden een
andere betekenis dan de code met dezelfde spelling in de huidige lijst; die worden
op betekenis omgezet (bijvoorbeeld 5.1.2.f, milieu, wordt 5.1.2.g). Een code zonder
eenduidige betekenis, zoals 5.2.e, blijft staan, wordt gemarkeerd in
`unmappedGrounds` en staat in de uitvoer van de upgrade. Een tweede run verandert niets.

## Alle waardelijsten op één plek

`GET /index.php/apps/opencatalogi/api/value-lists` geeft elke waarde van elke lijst
die OpenCatalogi gebruikt, zonder inloggen en met CORS: de informatiecategorieën,
soort handeling, documentsoorten en talen van TOOI en DiWoo, de DCAT-lijsten
(HVD-categorieën en datathema's) en de weigeringsgronden. Elke regel heeft
`scheme`, `schemeUri`, `code`, `label`, `uri`, `status` en `lastRefreshAt`.
Filter op één lijst met `?scheme=documentsoorten`. Een lijst die niet gelezen kan
worden staat in `unavailable` en wordt niet als lege lijst gegeven.
