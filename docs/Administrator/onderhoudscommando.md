---
description: >-
  Routineonderhoud van OpenCatalogi vanaf de commandoregel, met occ.
---

# Onderhoud vanaf de commandoregel

Deze commando's voeren werk uit dat anders op een vast schema of via een knop draait. Elk commando roept dezelfde dienst aan als de achtergrondtaak of de knop, dus het resultaat is hetzelfde.

Elk commando schrijft standaard één regel per onderdeel. Met `--output=json` krijgt u het resultaat als JSON, voor gebruik in een script. De exitcode is 0 bij succes, 1 als een onderdeel mislukte of een controle niet slaagde, en 2 bij verkeerd gebruik.

Voer de commando's uit als de gebruiker van de webserver, bijvoorbeeld `sudo -u www-data php occ ...`.

## De federatiedirectory synchroniseren

```bash
# Alle bekende directories
php occ opencatalogi:directory:sync

# Eén directory, bijvoorbeeld nadat die verhuisd is
php occ opencatalogi:directory:sync --url=https://catalogus.voorbeeldgemeente.nl/index.php/apps/opencatalogi/api/directory
```

Met `--url` toont het commando elke listing die het heeft toegevoegd of bijgewerkt.

## Deze directory aankondigen

```bash
php occ opencatalogi:directory:broadcast
```

Het commando toont per directory of de aankondiging is aangekomen.

## De bewaartermijnen beoordelen

```bash
# Toont per publicatie wat er zou gebeuren, en verandert niets
php occ opencatalogi:retention:evaluate

# Voert de beoordeling uit zoals de dagelijkse taak dat doet
php occ opencatalogi:retention:evaluate --apply
```

Gebruik eerst de versie zonder `--apply` nadat u de standaardtermijnen heeft gewijzigd.

## De gereedheid voor de Woo-index controleren

```bash
php occ opencatalogi:woo:readiness
```

Het commando toont elke controle met het resultaat en de reden. Het rapport wordt opgeslagen zoals bij de knop in de instellingen. Het commando eindigt met exitcode 1 als het oordeel niet "ready" is, zodat een deploymentscript erop kan stoppen. Zonder catalogus die voor de Woo-index is ingeschakeld voert het geen controle uit en eindigt het met exitcode 1.

## De cache van catalogi opnieuw opbouwen of legen

```bash
# Eén catalogus
php occ opencatalogi:catalog:cache rebuild woo
php occ opencatalogi:catalog:cache clear woo

# Alle catalogi, met het resultaat als JSON
php occ opencatalogi:catalog:cache rebuild --all --output=json
```

## Als de app-id verandert

De commando's beginnen met de app-id `opencatalogi`. Verandert de app-id, dan veranderen de namen van de commando's mee.
