# Algoritmeregister

Het Algoritmeregister op [algoritmes.overheid.nl](https://algoritmes.overheid.nl) is van de organisatie, niet van de leverancier. De organisatie publiceert de melding en blijft verantwoordelijk voor de inhoud.

In de praktijk schrijft de leverancier het concept en dient de organisatie het in. Deze pagina geeft dat concept, zodat u het kunt overnemen en aanpassen. De veldnamen volgen publicatiestandaard 1.0, zoals het register die zelf gebruikt.

## Wat OpenCatalogi zelf doet

OpenCatalogi bevat geen model. Er wordt niets getraind, niets voorspeld en niets gescoord.

Wat de publicatiepijplijn wel doet, is geautomatiseerd beslissen op basis van regels die u zelf instelt:

- de informatiecategorie bepalen waaronder een publicatie in de DiWoo-sitemap verschijnt
- bepalen of een publicatie openbaar zichtbaar is, op basis van de leesregels van het schema
- dagelijks beoordelen of de bewaartermijn van een publicatie is verstreken
- de DiWoo-metadata van een document samenstellen uit de velden van de publicatie

Dit zijn beslisregels, geen lerende modellen. Veel organisaties plaatsen zulke regels onder de publicatiecategorie "Overige algoritmes". Of een melding nodig is, beslist uw eigen CIO of privacy officer, niet deze pagina.

## Wanneer u een eigen melding nodig heeft

OpenCatalogi staat zelden alleen. Draait u een van deze onderdelen, dan hoort daar een aparte melding bij, met een eigen beschrijving van werking en risico:

- **anonymiq**: herkent persoonsgegevens in documenten met een taalmodel. Dit is het onderdeel met een model erin. Vergelijkbare meldingen staan al in het register onder "Anonimiseren".
- **hermiq**: beantwoordt vragen over gepubliceerde stukken. Een melding hoort hier bij het taalmodel dat u kiest.
- **De AI Chat Companion van Nextcloud**: OpenCatalogi stelt zoekresultaten beschikbaar aan deze assistent via een alleen-lezen koppeling. Het model zit in de assistent en in de backend die u daarvoor kiest. Die backend meldt u, niet OpenCatalogi.

Neem geen eigenschap over die u niet heeft. Een melding die een model beschrijft dat niet draait, is onjuist en valt op bij een audit.

## Het concept

Neem het onderstaande over in het invoerformulier van het register. De regel achter elk veld is een voorstel. Vervang alles tussen vierkante haken.

### Algemene informatie

| Veld | Label in het register | Voorstel |
| --- | --- | --- |
| `name` | Naam | Publicatiepijplijn Woo |
| `organization` | Organisatie | [uw organisatie] |
| `department` | Afdeling | [de afdeling die de Woo-publicaties beheert] |
| `description_short` | Korte beschrijving | De pijplijn bepaalt onder welke informatiecategorie een document openbaar wordt, of het zichtbaar mag zijn en wanneer de bewaartermijn verstrijkt. |
| `publication_category` | Publicatiecategorie | Overige algoritmes |
| `status` | Status | In gebruik |
| `area` | Thema | Organisatie en bedrijfsvoering |
| `begin_date` | Begindatum | [jaar en maand van ingebruikname, bijvoorbeeld 2026-03] |
| `contact_email` | Contactgegevens | [het e-mailadres van uw Woo-contactpunt] |
| `website` | Website | [de openbare URL van uw catalogus] |
| `provider` | Leverancier | Conduction B.V. |
| `publiccode` | Publiccode | https://github.com/ConductionNL/opencatalogi |

### Verantwoord gebruik

| Veld | Label in het register | Voorstel |
| --- | --- | --- |
| `goal` | Doel en impact | Documenten die onder de Wet open overheid vallen, komen volledig en op tijd openbaar, in de juiste informatiecategorie. |
| `impact` | Impact | De pijplijn beslist niet over personen. Een onjuiste categorie maakt een document moeilijker vindbaar en is daarmee het belangrijkste risico. |
| `proportionality` | Afwegingen | Handmatig indelen en handmatig opschonen is bij dit volume niet houdbaar. De regels zijn zichtbaar en aanpasbaar, zodat een fout te herleiden is naar een regel. |
| `human_intervention` | Menselijke tussenkomst | Een medewerker stelt elke publicatie vast voordat die openbaar wordt. De pijplijn publiceert niets op eigen initiatief. |
| `risks` | Risicobeheer | [beschrijf hoe u een onjuiste indeling opmerkt en herstelt] |
| `monitoring` | Monitoring | De zelfcontrole op harvester-gereedheid draait dagelijks en meldt onbereikbare sitemaps en afwijkende DiWoo-metadata. |
| `lawful_basis` | Wettelijke basis | Wet open overheid |
| `lawful_basis_link` | Links naar wettelijke basis | https://wetten.overheid.nl/BWBR0045754/ |
| `competent_authority` | Verantwoordelijke | [het verantwoordelijke organisatieonderdeel] |
| `objection_procedure` | Bezwaarprocedure | [verwijs naar uw eigen bezwaarprocedure] |

### Impacttoetsen

| Veld | Label in het register | Voorstel |
| --- | --- | --- |
| `dpia` | DPIA | [Ja of Nee, naar uw eigen besluit] |
| `dpia_description` | Toelichting DPIA | [verwijs naar uw eigen DPIA of leg uit waarom er geen nodig was] |
| `iama` | IAMA | [Ja of Nee, naar uw eigen besluit] |
| `iama_description` | Toelichting IAMA | [idem] |

Vul deze velden zelf in. Een leverancier kan niet verklaren dat uw organisatie een toets heeft gedaan.

### Werking

| Veld | Label in het register | Voorstel |
| --- | --- | --- |
| `source_data` | Gegevens | De pijplijn verwerkt de publicatie en de bijlagen die een medewerker aanlevert. Welke persoonsgegevens daarin staan, hangt af van het document. |
| `source_data_link` | Links naar gegevens | [verwijs naar uw eigen verwerkingsregister] |
| `methods_and_models` | Technische werking | De pijplijn gebruikt geen model. Een publicatie krijgt een informatiecategorie uit het veld `wooCategory` van het schema. Zichtbaarheid volgt uit de leesregels van dat schema. De bewaartermijn wordt dagelijks vergeleken met de publicatiedatum. Per document wordt DiWoo-metadata samengesteld en in een sitemap aangeboden aan de Woo-index van KOOP. |
| `performance_standard` | Prestatienorm | Niet van toepassing. De regels zijn deterministisch, dus er is geen nauwkeurigheidspercentage te geven. |
| `documentation` | Documentatie | https://opencatalogi.nl/docs |
| `application_url` | Applicatie | [de openbare URL van uw catalogus] |

## Na het indienen

Controleer na publicatie of uw melding op de juiste organisatiepagina staat. Werk de melding bij zodra u een nieuw onderdeel in gebruik neemt, zoals anonymiq.
