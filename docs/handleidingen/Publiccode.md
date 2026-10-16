# Publiccode

OpenCatalogi leest `publiccode.yml`-bestanden in en maakt er componenten van. Het haalt ze uit twee bronnen: alle bestanden die GitHub vindt, en het OSS-register van [developer.overheid.nl](https://developer.overheid.nl), de lijst van open-sourcerepositories die Nederlandse overheden publiceren. Je vindt de componenten in de catalogus Componenten, in de zoekfunctie en in de publieke API. Bezoekers hoeven daarvoor niet in te loggen.

Een `publiccode.yml` beschrijft een stuk software: naam, repository, licentie, status, wie het onderhoudt. De standaard staat op [yml.publiccode.tools](https://yml.publiccode.tools).

## Zo vindt de GitHub-harvest je component

1. Een geplande flow zoekt elke nacht om 03:15 op GitHub naar bestanden met de naam `publiccode.yml`.
2. GitHub geeft per zoekvraag hooguit 1.000 resultaten. Daarom splitst de harvest de zoekvraag in 24 delen op bestandsgrootte.
3. Per gevonden bestand haalt de harvest het bestand op en leest het als YAML.
4. Een bestand zonder `name` of zonder `url` slaat de harvest over. Een bestand dat geen geldige YAML is ook.
5. De harvest schrijft het component weg. Draait de harvest opnieuw, dan werkt hij hetzelfde component bij. Er komt geen tweede bij.

De harvest draait alleen als een beheerder hem heeft ingericht en aangezet. Zie [Voor beheerders](#voor-beheerders).

## Zo vindt de developer.overheid.nl-harvest je component

1. Een geplande flow leest elke nacht om 04:15 het OSS-register van developer.overheid.nl, en neemt alle repositories mee die een `publiccode.yml` hebben.
2. Per repository haalt de harvest het volledige bestand op bij de forge waar het staat: github.com of gitlab.com.
3. Staat de repository ergens anders (Codeberg, een eigen GitLab, een andere forge), dan neemt de harvest de versie die het register zelf van het bestand heeft gelezen. Daarin ontbreken een paar velden, zoals `categories`, `softwareVersion` en `releaseDate`.
4. Een bestand dat niet te lezen is, of geen `name` of `url` heeft, slaat de harvest over. Een fork waarvan het bestand naar de oorspronkelijke repository wijst, ook: die is al een component.
5. De harvest schrijft het component weg, met erbij hoe het register de repository vermeldt: de organisatie, de pagina in het register en wanneer het register de repository het laatst las.

### Eén component per repository

Vinden beide harvests dezelfde repository, dan wordt het één component, geen twee. Beide leiden de identiteit af van de `url` in het bestand. Elke harvest werkt alleen de velden bij die hij zelf schrijft: wat de GitHub-harvest invulde blijft staan als de developer.overheid.nl-harvest langskomt, en andersom.

Je ziet aan een component of het register het vermeldt: dan heeft het een blok `developerOverheid`.

## Voor onderhouders: je component laten vinden

1. Zet een `publiccode.yml` in de root van je repository, op de standaardbranch. GitHub doorzoekt alleen die branch.
2. Vul minimaal `name` en `url` in. Zonder die twee velden slaat de harvest het bestand over.
3. Commit en push. De eerstvolgende nachtelijke harvest neemt je component mee.

Staat je repository in het OSS-register van developer.overheid.nl, dan vindt ook de developer.overheid.nl-harvest hem, ook als hij niet op GitHub staat.

Een repository met meer componenten (een monorepo) mag meer `publiccode.yml`-bestanden hebben, elk in een eigen map. Elk bestand wordt een eigen component.

> [!Warning]
> GitHub vindt een bestand pas als de repository in de zoekindex staat. Een nieuwe of weinig gebruikte repository staat er soms nog niet in. Zoek dan zelf op github.com naar `repo:<organisatie>/<repository> publiccode` en wacht tot je bestand verschijnt.

Een GitHub-workflow kan het bestand voor je aanmaken en bijwerken: [create or update publiccode.yaml](https://github.com/marketplace/actions/create-or-update-publiccode-yaml#usage).

### Voorbeeld

```yaml
publiccodeYmlVersion: "0.4"
name: OpenCatalogi
url: "https://github.com/ConductionNL/opencatalogi"
softwareVersion: "0.7.34"
releaseDate: "2026-05-26"
developmentStatus: stable
platforms:
  - web
categories:
  - content-management
description:
  nl:
    shortDescription: "Framework voor gefedereerde catalogi in Nextcloud"
legal:
  license: EUPL-1.2
maintenance:
  type: internal
  contacts:
    - name: Conduction Development Team
      email: info@conduction.nl
localisation:
  localisationReady: true
  availableLanguages:
    - nl
    - en
```

## Wat OpenCatalogi uit het bestand overneemt

| Component | Uit `publiccode.yml` |
|---|---|
| Naam | `name` |
| Repository | `url` |
| Landingspagina | `landingURL` |
| Versie en releasedatum | `softwareVersion`, `releaseDate` |
| Platforms en categorieën | `platforms`, `categories` |
| Status en type | `developmentStatus`, `softwareType` |
| Beschrijvingen, per taal | `description` |
| Onderhoud | `maintenance.type`, en `maintenance.contractors` met `maintenance.contacts` samen als onderhouders |
| Licentie en eigenaar | `legal.license`, `legal.repoOwner` |
| Talen | `localisation.availableLanguages` |
| Italiaanse conformiteit | de onderdelen van `it.conforme` die op `true` staan |

Het hele bestand blijft bewaard als `rawPubliccode`. Een veld dat OpenCatalogi nog niet apart toont, zoals de Nederlandse uitbreiding `nl`, gaat dus niet verloren. Elk component krijgt ook `harvestedFrom`, de forge waar het bestand vandaan kwam (`github.com`, `gitlab.com` of een andere host), en `harvestedAt`, het moment van de laatste harvest.

Een component dat de developer.overheid.nl-harvest vond, krijgt ook `developerOverheid`:

| Veld | Wat het is |
|---|---|
| `id` | Het id van de repository in het register |
| `organisation.label`, `organisation.uri` | De organisatie die de repository publiceert |
| `registerUrl` | De pagina van de repository op oss.developer.overheid.nl |
| `lastCrawledAt` | Wanneer het register de repository het laatst las |
| `forkType` | Wat voor fork het is, als het een fork is |
| `archived` | Of het register de repository als gearchiveerd markeert |

Haalt de harvest het bestand niet bij de forge maar uit het register, dan schrijft hij geen `rawPubliccode`: de versie van het register is niet het bestand zelf.

Een component dat van GitHub of uit het register verdwijnt, blijft in OpenCatalogi staan. Je ziet het aan een `harvestedAt` die niet meer meeloopt.

## Voor beheerders

### GitHub-harvest

De harvest heeft integriq nodig. integriq doet de aanroepen naar GitHub en bewaart het token. OpenCatalogi ziet het token nooit.

1. Installeer en activeer integriq.
2. Open in integriq de bron `github-api`. Leg het GitHub-token vast als credential met de naam `github-publiccode` en zet de bron aan. Een token zonder extra rechten is genoeg; de zoek-API van GitHub vraagt alleen dat je bent ingelogd.
3. Open in Nextcloud **Beheerdersinstellingen > OpenCatalogi**, sectie **GitHub-harvest**.
4. Kies **Inrichten**. OpenCatalogi zet de 24 zoekdelen klaar in integriq.
5. Kies **Aanzetten**. Jij wordt eigenaar van de flow. Hij draait als de gebruiker die de planning noemt (`admin`). Wil je een serviceaccount, pas dan de trigger aan in de flow-editor van OpenRegister.
6. Kies **Nu uitvoeren** om niet tot de nacht te wachten.

De sectie toont per stap of hij klaar is, en het resultaat van de laatste run.

### Hoe lang een GitHub-harvest duurt

GitHub staat 10 zoekvragen per minuut toe. Een volledige harvest is hooguit 240 zoekvragen, dus minstens 24 minuten. Raakt de limiet op, dan pauzeert de run tot GitHub weer ruimte geeft en gaat dan verder waar hij was. Delen die al klaar zijn, draaien niet opnieuw. Een harvest die een paar uur duurt is dus normaal.

### Grenzen van de GitHub-harvest

- GitHub doorzoekt alleen de standaardbranch en alleen bestanden kleiner dan 384 KB.
- Een zoekdeel met meer dan 1.000 treffers mist de rest. De grenzen van de delen staan in `lib/Settings/publiccode-github-shards.json`. Splits daar een deel dat vol zit en kies daarna opnieuw **Inrichten**.
- Alleen GitHub. Repositories op GitLab, Codeberg en andere forges vindt de developer.overheid.nl-harvest, als het register ze vermeldt.

### developer.overheid.nl-harvest

Ook deze harvest heeft integriq nodig. integriq doet de aanroepen naar het register en naar de forges, en bewaart de API-sleutel. OpenCatalogi ziet de sleutel nooit en zoekt hem ook niet op.

1. Installeer en activeer integriq. Je hebt de bronnen `developer-overheid-oss`, `github-raw` en `gitlab-raw` nodig; die levert integriq mee.
2. Vraag je eigen API-sleutel aan op [apis.developer.overheid.nl/apis/key-aanvragen](https://apis.developer.overheid.nl/apis/key-aanvragen). Een sleutel met alleen leesrechten is genoeg. Elke installatie vraagt haar eigen sleutel aan.
3. Leg de sleutel in OpenRegister vast als credential met de naam `developer-overheid-oss` en provider `generic-apikey`. Zet `openconnector` bij de apps die hem mogen gebruiken.
4. Open in integriq de bron `developer-overheid-oss` en zet hem aan. De sectie in OpenCatalogi linkt naar de bron.
5. Open in Nextcloud **Beheerdersinstellingen > OpenCatalogi**, sectie **developer.overheid.nl-harvest**.
6. Kies **Inrichten**. OpenCatalogi zet de synchronisatie die het register leest klaar in integriq.
7. Kies **Aanzetten**. Jij wordt eigenaar van de flow. Hij draait als de gebruiker die de planning noemt (`admin`).
8. Kies **Nu uitvoeren** om niet tot de nacht te wachten. Op een installatie zonder cron is dit de manier om de harvest te draaien.

De sectie toont of integriq, de bronnen, de synchronisatie en de flow klaar zijn, en het resultaat van de laatste run, met het aantal stappen dat mislukte.

Het register leest de harvest per 100 repositories, tot 10 pagina's. Groeit het register voorbij 1.000 repositories, dan stopt de run met een fout in plaats van stil repositories over te slaan. Verhoog dan `maxPages` in `lib/Settings/publiccode-developer-overheid-sync.json` en kies opnieuw **Inrichten**.

### Grenzen van de developer.overheid.nl-harvest

- Alleen github.com en gitlab.com leveren het volledige bestand. Voor elke andere host gebruikt de harvest wat het register van het bestand heeft gelezen.
- Gearchiveerde repositories toont het register standaard niet, dus de harvest leest ze ook niet.
- Een component dat het register niet meer vermeldt, houdt zijn blok `developerOverheid`.

Wil je weten welke componenten er nu zijn? Open de catalogus Componenten en zoek op naam, licentie of platform.
