# Directory

De directory vormt het overzicht van alle andere (externe) catalogi die bij jouw installatie bekend zijn. Een Catalogus die bij jouw installatie bekend is noemen een listing (als in is gelist op jouw directory).

## Opzetten federatief netwerk

Directories worden tussen installaties onderling uitgewisseld en geupdate. Je hoeft dus nooit handmatig catalogi van andere toe te voegen aan jouw catalogus.

## Een andere installatie toevoegen op hostnaam

In **Directory toevoegen** kun je de volledige directory-URL invullen, bijvoorbeeld `https://cloud.example.org/index.php/apps/opencatalogi/api/directory`, maar de hostnaam alleen is ook genoeg: `cloud.example.org`.

OpenCatalogi leest dan de openbare Nextcloud-capabilities van die installatie (`/ocs/v2.php/cloud/capabilities`, zonder in te loggen). Daar publiceert OpenRegister het directory-adres onder `opencatalogi.discovery.links.directory`. Publiceert de andere installatie dat niet, bijvoorbeeld een oudere versie, dan gebruikt OpenCatalogi het standaardpad `/index.php/apps/opencatalogi/api/directory`.

Dezelfde controles als bij het synchroniseren blijven gelden: lokale en interne adressen worden geweigerd, behalve hosts die je zelf in `local_federation_hosts` hebt toegestaan.

## Listing

Bij een listing kan je de volgende zaken aanpassen.
