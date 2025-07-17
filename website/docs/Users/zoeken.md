# Zoeken in OpenCatalogi

OpenCatalogi biedt krachtige zoek- en filtermogelijkheden om publicaties te vinden en te verkennen. Het systeem ondersteunt gefedereerd zoeken over meerdere catalogi en geavanceerde facettering voor nauwkeurige filtering.

## Zoeken naar Publicaties

### Basis Zoeken

Gebruik de zoekbalk om te zoeken naar publicaties op basis van titel, beschrijving, of inhoud:

```
GET /index.php/apps/opencatalogi/api/publications?_search=mijn zoekterm
```

### Paginering

Resultaten kunnen worden gepagineerd met de volgende parameters:

```
GET /index.php/apps/opencatalogi/api/publications?_limit=20&_page=2
```

### Sorteren

Sorteer resultaten op verschillende velden:

```
GET /index.php/apps/opencatalogi/api/publications?_order[title]=ASC
GET /index.php/apps/opencatalogi/api/publications?_order[@self.published]=DESC
```

## Facettering (Geavanceerd Filteren)

Facettering stelt u in staat om resultaten te filteren op basis van verschillende eigenschappen. Het systeem onderscheidt twee typen facetten:

### 1. Metadata Facetten (@self)

Deze facetten zijn gebaseerd op systeem metadata:

#### Register Facet
Filter op register (data bron):
```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][register][type]=terms
```

Antwoord bevat facet informatie:
```json
{
  'facets': {
    '@self': {
      'register': {
        'type': 'terms',
        'buckets': [
          {'key': '5', 'results': 3, 'label': 'Test'}
        ]
      }
    }
  }
}
```

#### Schema Facet
Filter op schema (data structuur):
```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][schema][type]=terms
```

#### Datum Facetten
Filter op datum velden met histogrammen:
```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][published][type]=date_histogram&_facets[@self][published][interval]=month
```

Resultaat:
```json
{
  'facets': {
    '@self': {
      'published': {
        'type': 'date_histogram',
        'interval': 'month',
        'buckets': [
          {'key': '2025-06', 'results': 3}
        ]
      }
    }
  }
}
```

#### Federatie Facetten
Filter op catalogus bron (lokaal of extern):
```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][catalog][type]=terms
```

Filter op organisatie:
```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][organisation][type]=terms
```

### 2. Object Veld Facetten (object_fields)

Deze facetten zijn gebaseerd op de inhoud van publicaties:

```
GET /index.php/apps/opencatalogi/api/publications?_facets[UserTitle][type]=terms
```

### Facet Ontdekking

Ontdek beschikbare facetten dynamisch:

```
GET /index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=0
```

Antwoord bevat volledige facet metadata:
```json
{
  'facetable': {
    '@self': {
      'register': {
        'type': 'categorical',
        'description': 'Register that contains the object',
        'facet_types': ['terms'],
        'has_labels': true,
        'sample_values': [
          {'value': '5', 'label': 'Test', 'count': 3}
        ]
      },
      'published': {
        'type': 'date',
        'description': 'Date and time when the object was published',
        'facet_types': ['date_histogram', 'range'],
        'intervals': ['day', 'week', 'month', 'year'],
        'date_range': {
          'min': '2025-06-29 19:06:04',
          'max': '2025-06-29 19:27:51'
        }
      },
      'catalog': {
        'type': 'categorical',
        'description': 'Catalog source of the publication',
        'facet_types': ['terms'],
        'has_labels': true,
        'sample_values': [
          {'value': 'local', 'label': 'Local OpenCatalogi instance', 'count': 1},
          {'value': 'directory.opencatalogi.nl', 'label': 'directory.opencatalogi.nl', 'count': 1}
        ]
      }
    },
    'object_fields': {
      'UserTitle': {
        'type': 'string',
        'description': 'Object field: UserTitle',
        'sample_values': ['ultra nice'],
        'facet_types': ['terms'],
        'cardinality': 'low'
      }
    }
  }
}
```

### Facet Filtering

Filter resultaten op basis van facet waarden:

```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][register][type]=terms&_facets[@self][register][filter][5]=true
```

### Meerdere Facetten Combineren

Combineer meerdere facetten voor nauwkeurige filtering:

```
GET /index.php/apps/opencatalogi/api/publications?_facets[@self][register][type]=terms&_facets[@self][published][type]=date_histogram&_facets[@self][published][interval]=month
```

## Federatie en Aggregatie

### Gefedereerd Zoeken

Zoek automatisch over alle geconfigureerde catalogi:

```
GET /index.php/apps/opencatalogi/api/publications?_aggregate=true
```

### Lokaal Zoeken

Zoek alleen in de lokale catalogus:

```
GET /index.php/apps/opencatalogi/api/publications?_aggregate=false
```

### Federation Endpoint

Gebruik het speciale federation endpoint voor volledige federatie functies:

```
GET /index.php/apps/opencatalogi/api/federation/publications
```

## Gebruikersinterface

### Zoeksidebar

De zoeksidebar bevat drie tabbladen:

1. **Zoeken**: Basis zoekfuncties, snelle filters, en sorteermogelijkheden
2. **Facetten**: Dynamische facet ontdekking en configuratie
3. **Resultaten**: Statistieken, federatie informatie, en selectie beheer

### Facet Component

Het facet component stelt u in staat om:

- Beschikbare facetten te ontdekken
- Facet typen te selecteren (terms, date_histogram, range)
- Facet configuratie aan te passen (intervals voor datum facetten)
- Actieve facetten te beheren
- Filter resultaten te bekijken en toe te passen

### Weergavemodi

Kies tussen twee weergavemodi:

- **Kaarten**: Visuele kaarten met publicatie informatie
- **Tabel**: Compacte tabelweergave met sorteer- en selectiemogelijkheden

## API Referentie

### Parameters

| Parameter | Beschrijving | Voorbeeld |
|-----------|--------------|-----------|
| `_search` | Zoekterm | `?_search=publicatie` |
| `_limit` | Aantal resultaten per pagina | `?_limit=20` |
| `_page` | Paginanummer | `?_page=2` |
| `_order` | Sorteervolgorde | `?_order[title]=ASC` |
| `_facets` | Facet configuratie | `?_facets[@self][register][type]=terms` |
| `_facetable` | Facet ontdekking | `?_facetable=true` |
| `_aggregate` | Federatie schakelaar | `?_aggregate=true` |

### Facet Typen

| Type | Beschrijving | Velden |
|------|--------------|---------|
| `terms` | Categoriale facetten | Alle tekst en ID velden |
| `date_histogram` | Datum histogrammen | Datum velden (created, updated, published) |
| `range` | Bereik filters | Numerieke en datum velden |

### Federatie Bronnen

Het systeem ondersteunt automatische federatie met:

- **Lokale catalogus**: Uw eigen OpenCatalogi instantie
- **directory.opencatalogi.nl**: Centrale OpenCatalogi directory
- **Gemeentelijke catalogi**: Dimpact, Rotterdam, etc.
- **Aangepaste bronnen**: Via configuratie toegevoegde bronnen

## Tips en Best Practices

1. **Gebruik facet ontdekking** om beschikbare filters te vinden voordat u facetten configureert
2. **Combineer zoektermen met facetten** voor de meest nauwkeurige resultaten
3. **Gebruik datum histogrammen** om trends over tijd te visualiseren
4. **Schakel aggregatie in** om over alle beschikbare catalogi te zoeken
5. **Monitor federatie prestaties** bij grote resultaatsets

## Veelgestelde Vragen

**Q: Waarom zie ik geen resultaten van externe catalogi?**
A: Controleer of aggregatie is ingeschakeld (`_aggregate=true`) en of de externe catalogi beschikbaar zijn.

**Q: Hoe voeg ik nieuwe facetten toe?**
A: Facetten worden automatisch ontdekt op basis van schema configuratie. Markeer velden als `facetable` in het schema.

**Q: Kunnen facetten worden gecombineerd?**
A: Ja, u kunt meerdere facetten combineren door verschillende `_facets` parameters toe te voegen.

**Q: Hoe werkt federatie paginering?**
A: Het systeem verzamelt gegevens van alle bronnen, dedupliceert resultaten, en past vervolgens paginering toe op de gecombineerde dataset.
