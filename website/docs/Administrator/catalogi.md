# Catalogi

Een catalogus is een verzameling van publicaties. Publicaties behoren dus altijd tot één catalogus en iedere catalogus tot één organisatie. Het is echter wel mogelijk om in één catalogus meerdere metadatatypen te ondersteunen. Binnen de softwarecatalogus worden bijvoorbeeld publiccodes (componenten, code, etc.) als diensten beschikbaar gesteld en binnen de Woo meerdere KOOP/TOOI-typen.

> Catalogi zijn onderdeel van de [OpenCatalogi-Standaard](https://github.com/OpenCatalogi/.github/blob/main/docs/Standaard.md) en gebaseerd op het [catalogue object](https://conduction.stoplight.io/docs/open-catalogi/pk8bsjw0539dv-catalogue).

## Inhoud van een catalogus bekijken

De organisatie-eigen catalogi (waartoe een gebruiker toegang heeft) zijn opgenomen in het hoofdmenu. Als je op een catalogus klikt, worden de publicaties binnen de catalogus zichtbaar en kan er in de catalogus worden gezocht. Ook kunnen via het actiemenu (rechts naast de zoekbalk) extra publicaties worden toegevoegd.

## Catalogi beheren

Catalogi kunnen worden beheerd via het menu-item Instellingen -> Catalogi en dan de drie bolletjes te selecten voor de opties.&#x20;

* **Configuratie**: Onder de configuratie van een catalogus kan worden aangegeven
  * Of deze actief is (anders wordt de catalogus niet getoond in het navigatiemenu en de zoekresultaten)
  * Of deze openbaar is (anders wordt de catalogus alleen getoond aan de geselecteerde gebruikersgroepen)
* **Rollen selecteren**: Hier kunnen de rollen worden geselecteerd die toegang hebben tot de catalogus indien deze **niet** openbaar is.
* **Metadata selecteren**: Hier kunnen de metadatatypen worden aangegeven die worden geaccepteerd door deze catalogus, dat kunnen zowel [interne metadatatypen](metadata.md) zijn als [externe metadatatypen](directory.md). Deze laatste moeten dan wel zijn geactiveerd via de directory.

> \[warning] Het is niet mogelijk om een catalogus te verwijderen als deze nog publicaties bevat.

## Publicaties verplaatsen

Publicaties kunnen van de ene naar de andere catalogus worden verplaatst, mits het metadatatype van de publicatie actief is op de catalogus waar de publicatie heen wordt verplaatst.

## Performance-geoptimaliseerde catalog filtering

### Overzicht

Open Catalogi implementeert een geavanceerd cachingsysteem voor catalogusfiltering dat de prestaties van de publicatie-endpoints aanzienlijk verbetert. Dit systeem gebruikt een 'warmup' strategie waarbij catalogusgegevens worden geaggregeerd en opgeslagen in een cache, in plaats van real-time databasequery's uit te voeren.

### Hoe het werkt

#### Cache Strategie
Het systeem gebruikt Nextcloud's IAppConfig als persistente cache voor het opslaan van geaggregeerde catalogusdata:

- **Cached Registers**: JSON array met alle unieke registers uit alle catalogi
- **Cached Schemas**: JSON array met alle unieke schemas uit alle catalogi  
- **Cache Timestamp**: Tijdstempel wanneer cache laatst werd opgebouwd

#### Automatische Cache Management
De cache wordt automatisch beheerd via event listeners:

1. **Cache Warmup**: Bij app-initialisatie wordt de cache opgebouwd
2. **Cache Invalidation**: Wanneer een catalogus wordt aangemaakt, bijgewerkt of verwijderd, wordt de cache automatisch geleegd
3. **Cache Rebuild**: Bij een cache miss wordt de cache automatisch opnieuw opgebouwd

#### Publications API Performance
De Publications Controller gebruikt nu gecachte catalogusfilters:

- **Geen database queries** voor catalogusfiltering
- **Snelle filtering** op registers en schemas
- **Backwards compatibility** met bestaande API's
- **Automatische fallback** bij cache problemen

### Technische Implementatie

#### Services Betrokken
- **CatalogiService**: Beheert cache warmup en invalidation
- **PublicationsController**: Gebruikt gecachte filters voor API endpoints
- **Event Listeners**: Detecteren catalogus wijzigingen voor cache invalidation

#### Methoden
- `warmupCatalogCache()`: Bouwt de cache op door alle catalogi te aggregeren
- `getCachedCatalogFilters()`: Haalt gefilterde data op uit cache
- `invalidateCatalogCache()`: Leegt de cache bij wijzigingen
- `addCachedCatalogFilters()`: Voegt gecachte filters toe aan search queries

### Prestatie Voordelen

- **Geen database overhead**: Catalogusfiltering gebruikt geen database queries meer
- **Snelle response times**: Cache wordt direct uit IAppConfig gelezen
- **Schaalbaar**: Prestaties blijven consistent ongeacht aantal catalogi
- **Automatic optimization**: Cache wordt automatisch up-to-date gehouden

### Monitoring en Troubleshooting

#### Logbestanden
Het systeem logt belangrijke events:
```
OpenCatalogi: Invalidated catalog cache due to catalog creation: [catalogID]
OpenCatalogi: Failed to invalidate catalog cache for catalog update: [catalogID]
OpenCatalogi: Exception during catalog cache invalidation: [error]
```

#### Cache Status Controleren
Administrators kunnen de cache status controleren via de configuratie-instellingen waar de volgende keys worden opgeslagen:
- `cached_catalog_registers`
- `cached_catalog_schemas`
- `catalog_cache_timestamp`

#### Handmatige Cache Reset
Bij problemen kan de cache handmatig worden gereset door de genoemde configuratie-keys te verwijderen. De cache wordt dan automatisch opnieuw opgebouwd bij de volgende API call.

### Best Practices

1. **Monitoring**: Houd de logs in de gaten voor cache gerelateerde errors
2. **Updates**: Bij grote catalogus wijzigingen kan de cache rebuild even duren
3. **Performance**: Het systeem is ontworpen om transparant te werken zonder administrator interventie
4. **Backwards Compatibility**: Alle bestaande API endpoints blijven volledig functioneel
