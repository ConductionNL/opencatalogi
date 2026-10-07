# Publicaties

Publicaties zijn onderdeel van de [Open Catalogi Standaard](https://github.com/OpenCatalogi/.github/blob/main/docs/Standaard.md) en gebaseerd op het [publication object](https://conduction.stoplight.io/docs/open-catalogi/9bebd6bf4fe35-publication). Publicaties kennen eigenschappen zoals gedefinieerd in een publicatietype en kunnen worden gekoppeld aan bijlagen

## Publicaties toevoegen

Publicaties voeg je toe op de publicatiepagina, met de knop om een publicatie toe te voegen. Die pagina open je via:

* Een catalogus in het hoofdmenu (links): direct onder Dashboard staat elke catalogus waar je toegang toe hebt, onder zijn eigen titel
* De knop "Publicaties bekijken" op de detailpagina van een catalogus

De knop opent een formulier dat is opgebouwd uit het schema dat de pagina op dat moment toont. Je vult de publicatiedetails in en slaat op; er wordt daarbij niet om een catalogus of publicatietype gevraagd. De publicatie komt in het register dat de pagina op dat moment toont.

De publicatiepagina toont alleen de publicaties van de gekozen catalogus: de objecten in de registers en schema's die bij die catalogus zijn ingesteld. Heeft de catalogus meer dan één combinatie van register en schema, dan kies je bovenaan de pagina welke combinatie je ziet. Heeft de catalogus geen register of schema ingesteld, dan meldt de pagina dat en kun je vandaar de catalogus openen om dat in te stellen.

Eigenschappen en bijlagen kunnen worden toegevoegd nadat de publicatie is toegevoegd.

## Publicaties zoeken

Boven de publicatielijst staat een zoekbalk. Typ een deel van een titel, samenvatting of beschrijving, en de lijst toont alleen de publicaties waarin die tekst voorkomt. De andere tekstvelden van een publicatie doen ook mee, datumvelden niet. Hoofdletters en kleine letters maken geen verschil.

Haakjes, dubbele aanhalingstekens, het sterretje (`*`) en de woorden `AND`, `OR` en `NOT` in hoofdletters werken als zoekoperatoren. Een haakje of aanhalingsteken zonder tegenhanger is daarom geen geldige zoekopdracht.

De server doorzoekt alle publicaties, niet alleen de rijen die op het scherm staan. De lijst, en dus ook de zoekopdracht, omvat de publicaties van alle catalogi, niet alleen die van één catalogus. De zoekopdracht start pas als je even stopt met typen, dus niet bij elke toetsaanslag. Een nieuwe zoekterm brengt je terug naar pagina 1, en de teller boven de lijst toont hoeveel publicaties er gevonden zijn.

Maak je de zoekbalk leeg, dan zie je de volledige lijst weer.

De zoekterm blijft staan terwijl je bladert en wanneer je wisselt tussen de kaart- en de tabelweergave. De zoekterm staat ook in de adresbalk (URL), zodat je na het herladen of via een gekopieerde link dezelfde resultaten ziet.

Vindt de zoekbalk niets, dan meldt de lijst "Geen publicaties gevonden".

## Publicaties beheren

De gebruikersbeheerinterface werkt intuïtief. In het hoofdmenu aan de linkerkant staat elke catalogus waar je toegang toe hebt; de catalogus die je open hebt is gemarkeerd. Hoe je een publicatie toevoegt, staat hierboven.

Na het opslaan is de publicatie zichtbaar op de publicatiepagina van de catalogus. Om de publicatie aan te passen, te depubliceren of andere acties uit te voeren, klik je op de blauwe "Actie"-knop rechtsboven bij de getoonde publicatie, of de drie puntjes rechts van de publicatie zelf.\\

Onder is een voorbeeld van een publicatie en de Actie-mogelijkheden.


## Publiceren en terugtrekken

Op de pagina van een publicatie staat het blok Publicatiestatus. Daar zie je of de publicatie een concept is, gepland, openbaar, teruggetrokken of gearchiveerd. Je ziet alleen de knoppen die op dat moment kunnen.

- **Nu publiceren** maakt een concept of geplande publicatie meteen openbaar.
- **Terugtrekken** vraagt om een reden. De publicatie verdwijnt meteen van de publieke site. Elk landelijk kanaal dat haar had, krijgt een intrekking: altijd de Woo-index, en PLOOI als ze daar was afgeleverd. De melding noemt elk kanaal dat nog niet heeft bevestigd.
- **Opnieuw publiceren** maakt een teruggetrokken publicatie weer openbaar. Eerdere intrekkingen blijven in de geschiedenis staan.

Wil je één document terugtrekken? Kies het dan in het venster Terugtrekken. Alleen dat document verdwijnt van de publieke site en uit de sitemap. De rest van de publicatie blijft openbaar.

Archiveren blijft de laatste stap voor bewaren. Het is geen manier om terug te trekken.

Je hebt het recht nodig om de publicatie te wijzigen. Zonder dat recht weigert de server, en er gebeurt niets.

## Eigenschappen

@todo

## Bijlagen

Publicaties hebben vaak bijlagen, zoals een verslag of een besluit. Deze zijn eenvoudig toe te voegen door op de Actie-knop te klikken bij een geselecteerde publicatie, of de drie bolletjes naast een publicatie. Dit opent de Bijlage toevoegen modal.

<div>

<figure><img src="../assets/bijlage_toevoegen_drie_bolletjes.png" alt="" /><figcaption><p>bijlage toevoegen via drie bolletjes</p></figcaption></figure>

<figure><img src="../assets/bijlage_toevoegen_actieknop.png" alt="" /><figcaption><p>bijlage toevoegen via de actie-knop</p></figcaption></figure>

</div>

In de `Bijlage toevoegen`-modal worden er gevraagd om een aantal velden. Er zijn twee mogelijkheden een bijlage toe te voegen. De eerste manier is via een  `Toegangs URL`. Dit zorgt ervoor dat het bestand vanuit een andere plek automatisch gedownload wordt.  Een `Titel` is dan verplicht.&#x20;

De tweede manier is door zelf een bestand up te loaden. De bestandsnaam wordt dan meegegeven.&#x20;
