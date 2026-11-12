# Directory

De directory vormt het overzicht van alle andere (externe) catalogi die bij jouw installatie bekend zijn. Een Catalogus die bij jouw installatie bekend is noemen een listing (als in is gelist op jouw directory).

## Opzetten federatief netwerk

Directories worden tussen installaties onderling uitgewisseld en geupdate. Je hoeft dus nooit handmatig catalogi van andere toe te voegen aan jouw catalogus.

### Welke directories de synchronisatie ophaalt

De uurlijkse synchronisatie haalt alleen de vertrouwde directories op, plus de standaard-directory. Een directory wordt vertrouwd als een beheerder hem toevoegt (een directory toevoegen, of een listing aanmaken met een directory-URL). Directories die je installatie alleen via een andere installatie heeft leren kennen, worden wel opgeslagen maar niet zelf opgehaald; hun catalogi komen binnen via de vertrouwde directory die ze doorgeeft.

Bekende beperking (apart opgevolgd): elke vertrouwde directory die zo'n listing doorgeeft, kan die listing bijwerken. Je installatie onthoudt niet welke directory een listing als eerste doorgaf.

Bij de eerste update naar deze werkwijze worden alle directories die de synchronisatie daarvoor al ophaalde eenmalig vertrouwd.

Een listing verwijderen laat de directory vertrouwd. Wil je een directory niet meer laten ophalen, haal hem dan uit de lijst `trusted_directory_urls` (een JSON-lijst van URL's):

```bash
occ config:app:get opencatalogi trusted_directory_urls
occ config:app:set opencatalogi trusted_directory_urls --value '["https://voorbeeld.nl/apps/opencatalogi/api/directory"]'
```

## Listing

Bij een listing kan je de volgende zaken aanpassen.
