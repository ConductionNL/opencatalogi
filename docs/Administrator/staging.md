---
description: >-
  Met de stagingmodus oefent een organisatie de hele Woo-keten zonder dat er iets
  de instantie verlaat.
---

# Stagingmodus

In stagingmodus kan een medewerker de Woo-keten van begin tot eind oefenen: publiceren, de sitemap bekijken, de gereedheidscheck draaien en aanbieden aan de landelijke index. Er gaat niets naar buiten. Elke overdracht wordt bewaard als proefrun.

## Aanzetten

1. Ga naar **Beheer > OpenCatalogi** en open de sectie **Instantiemodus**.
2. Kies **Staging** en bevestig.

De wijziging komt in het auditlog van Nextcloud, met wie hem deed, de oude en de nieuwe waarde. Zolang de instantie in staging staat, toont elke pagina van OpenCatalogi een melding.

Terugzetten gaat op dezelfde manier: kies **Productie** en bevestig.

## Wat staging doet

- **Overdrachten worden proefruns.** Aanbieden aan de landelijke index en de Woo-index, de aanmelding bij de Woo-index, levering aan PLOOI, intrekkingen, broadcasts naar federatiepartners en de aankondiging bij een andere directory worden niet verstuurd. Ze worden bewaard onder **Proefruns**, met het kanaal, het moment, de gebruiker, de reden en wat er verstuurd zou zijn.
- **Er wordt niets als geleverd gemarkeerd.** Een proefrun zet geen PLOOI-status, geen leverdatum, geen bevestiging van een intrekking en geen antwoord op de aanmelding. Na terugzetten naar productie staat er dus niets dat beweert een landelijk kanaal te hebben bereikt.
- **De openbare kant is dicht voor anonieme bezoekers.** Openbare routes antwoorden met 503 en `noindex`, en robots.txt verbiedt alles. Wie ingelogd is, ziet de openbare kant zoals een lezer die in productie zou zien.
- **Bij twijfel geldt staging.** Kan de modus niet worden gelezen, dan wordt er niets verstuurd en noemt de proefrun de reden.

## Wat staging niet doet

- **Proefruns worden niet nagestuurd.** Na terugzetten naar productie blijven ze staan om na te lezen. Wat echt moet worden aangeboden, publiceert u opnieuw.
- **Webhooks van OpenRegister en synchronisaties van integriq lopen door.** Die horen bij die apps. De sectie Instantiemodus noemt de ingeschakelde webhooks en synchronisaties bij naam, zodat u weet wat er nog wel naar buiten kan gaan. Zet ze zo nodig in die apps zelf uit.
- **Er is geen aparte database.** Staging werkt op dezelfde gegevens als de instantie zelf.
- **Staging geldt voor de hele instantie,** niet per catalogus. De status van een catalogus houdt zijn eigen betekenis.
