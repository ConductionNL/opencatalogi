# Publiccode

OpenCatalogi leest `publiccode.yml`-bestanden van GitHub in en maakt er componenten van. Je vindt ze in de catalogus Componenten, in de zoekfunctie en in de publieke API. Bezoekers hoeven daarvoor niet in te loggen.

Een `publiccode.yml` beschrijft een stuk software: naam, repository, licentie, status, wie het onderhoudt. De standaard staat op [yml.publiccode.tools](https://yml.publiccode.tools).

## Zo vindt de harvest je component

1. Een geplande flow zoekt elke nacht om 03:15 op GitHub naar bestanden met de naam `publiccode.yml`.
2. GitHub geeft per zoekvraag hooguit 1.000 resultaten. Daarom splitst de harvest de zoekvraag in 24 delen op bestandsgrootte.
3. Per gevonden bestand haalt de harvest het bestand op en leest het als YAML.
4. Een bestand zonder `name` of zonder `url` slaat de harvest over. Een bestand dat geen geldige YAML is ook.
5. De harvest schrijft het component weg. Draait de harvest opnieuw, dan werkt hij hetzelfde component bij. Er komt geen tweede bij.

De harvest draait alleen als een beheerder hem heeft ingericht en aangezet. Zie [Voor beheerders](#voor-beheerders).

## Voor onderhouders: je component laten vinden

1. Zet een `publiccode.yml` in de root van je repository, op de standaardbranch. GitHub doorzoekt alleen die branch.
2. Vul minimaal `name` en `url` in. Zonder die twee velden slaat de harvest het bestand over.
3. Commit en push. De eerstvolgende nachtelijke harvest neemt je component mee.

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

Het hele bestand blijft bewaard als `rawPubliccode`. Een veld dat OpenCatalogi nog niet apart toont, zoals de Nederlandse uitbreiding `nl`, gaat dus niet verloren. Elk component krijgt ook `harvestedFrom` (`github.com`) en `harvestedAt`, het moment van de laatste harvest.

Een component dat van GitHub verdwijnt, blijft in OpenCatalogi staan. Je ziet het aan een `harvestedAt` die niet meer meeloopt.

## Voor beheerders

De harvest heeft integriq nodig. integriq doet de aanroepen naar GitHub en bewaart het token. OpenCatalogi ziet het token nooit.

1. Installeer en activeer integriq.
2. Open in integriq de bron `github-api`. Leg het GitHub-token vast als credential met de naam `github-publiccode` en zet de bron aan. Een token zonder extra rechten is genoeg; de zoek-API van GitHub vraagt alleen dat je bent ingelogd.
3. Open in Nextcloud **Beheerdersinstellingen > OpenCatalogi**, sectie **GitHub-harvest**.
4. Kies **Inrichten**. OpenCatalogi zet de 24 zoekdelen klaar in integriq.
5. Kies **Aanzetten**. Jij wordt eigenaar van de flow. Hij draait als de gebruiker die de planning noemt (`admin`). Wil je een serviceaccount, pas dan de trigger aan in de flow-editor van OpenRegister.
6. Kies **Nu uitvoeren** om niet tot de nacht te wachten.

De sectie toont per stap of hij klaar is, en het resultaat van de laatste run.

### Hoe lang een harvest duurt

GitHub staat 10 zoekvragen per minuut toe. Een volledige harvest is hooguit 240 zoekvragen, dus minstens 24 minuten. Raakt de limiet op, dan pauzeert de run tot GitHub weer ruimte geeft en gaat dan verder waar hij was. Delen die al klaar zijn, draaien niet opnieuw. Een harvest die een paar uur duurt is dus normaal.

### Grenzen

- GitHub doorzoekt alleen de standaardbranch en alleen bestanden kleiner dan 384 KB.
- Een zoekdeel met meer dan 1.000 treffers mist de rest. De grenzen van de delen staan in `lib/Settings/publiccode-github-shards.json`. Splits daar een deel dat vol zit en kies daarna opnieuw **Inrichten**.
- Alleen GitHub. GitLab en Codeberg volgen later.

Wil je weten welke componenten er nu zijn? Open de catalogus Componenten en zoek op naam, licentie of platform.
