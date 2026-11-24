# Publicerende en verantwoordelijke organisatie

Een publicatie noemt twee organisaties. Meestal zijn dat dezelfde, maar niet altijd: een shared service centre kan publiceren voor een gemeente.

## De twee organisaties

| Veld | Wat het is |
| --- | --- |
| Organisatie | De publicerende organisatie: wie de publicatie openbaar maakt. |
| Verantwoordelijke organisatie | De organisatie die verantwoordelijk is voor de inhoud, als dat niet de publicerende organisatie is. |

Laat je **Verantwoordelijke organisatie** leeg, dan is de publicerende organisatie ook de verantwoordelijke. Beide velden verwijzen naar de gedeelde organisatie van OpenRegister (zie [Organisaties](../Administrator/organisaties.md)), dus de naam, de TOOI-identificatie en het RSIN komen van daar.

In de sitemap voor de Woo-index wordt de publicerende organisatie `diwoo:publisher` en de verantwoordelijke organisatie `diwoo:verantwoordelijke`. Heeft de verantwoordelijke organisatie geen TOOI-identificatie, dan laat de sitemap `diwoo:verantwoordelijke` weg en noemt het DiWoo-validatierapport het document met de reden. Het document blijft wel in de sitemap.

## Het RSIN reist mee

Het RSIN (Rechtspersonen en Samenwerkingsverbanden Informatienummer) van de publicerende organisatie gaat mee met elk document dat aan een openbare publicatie wordt toegevoegd:

- **Op het bestand**: OpenCatalogi schrijft labels in de metadata van het bestand in OpenRegister: `rsin:<nummer>`, `publisherName:<naam>` en `publisherTooi:<identificatie>`. Een naam of identificatie die de organisatie niet heeft, wordt weggelaten.
- **Op de publicatie**: bij het eerste document slaat OpenCatalogi het RSIN op in het veld **RSIN van de publicerende organisatie** (`publisherRsin`). Volgende documenten vinden het daar en schrijven het niet opnieuw.

Het RSIN wordt alleen gebruikt als het negen cijfers heeft en de elfproef doorstaat, bijvoorbeeld `002220647`. Is het nummer leeg of ongeldig, dan schrijft OpenCatalogi geen RSIN. Het bestand krijgt dan de labels `rsinStatus:missing` en `rsinReason:<reden>`, en de publicatie krijgt geen `publisherRsin`. Het document wordt wel gepubliceerd. Vul het RSIN in bij de organisatie en voeg het document opnieuw toe als het nummer erbij moet.

Lukt het opslaan van `publisherRsin` op de publicatie niet, dan wordt het document toch gepubliceerd en staat de reden in het logboek van Nextcloud.

Op dit moment gebeurt dit wanneer een Woo-batch wordt gepubliceerd. Een document dat je los uploadt op de pagina van een publicatie krijgt de labels nog niet.
