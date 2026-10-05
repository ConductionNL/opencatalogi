# Publicaties

Publicaties zijn onderdeel van de [Open Catalogi Standaard](https://github.com/OpenCatalogi/.github/blob/main/docs/Standaard.md) en gebaseerd op het [publication object](https://conduction.stoplight.io/docs/open-catalogi/9bebd6bf4fe35-publication). Publicaties kennen eigenschappen zoals gedefinieerd in een publicatietype en kunnen worden gekoppeld aan bijlagen

## Publicaties toevoegen

Publicaties kunnen worden toegevoegd via:

* De publicatie toevoegen knop boven aan het hoofd menu (links)
* Een catalogus geselecteerd in het hoofdmenu (via het hamburgermenu achter de zoekbalk)
* Een catalogus detailpagina

Een publicatie leeft altijd binnen één catalogus en wordt gedefinieerd door één publicatietype. Omdat catalogi bepalen welke publicatietypen beschikbaar zijn voor die catalogi moet er eerst een catalogus worden gekozen voordat er een metadatatype kan worden gekozen. Daarmee wordt de volgorde bij het aanmaken van een publicatie:

1. Catalogus kiezen (indien niet opgestart vanuit een specifieke catalogus)
2. Publicatietype kiezen
3. Publicatiedetails aanvullen

Eigenschappen en bijlagen kunnen worden toegevoegd nadat de publicatie is toegevoegd.

## Publicaties beheren

De gebruikersbeheerinterface werkt intuïtief. Aan de linkerkant van de pagina bevindt zich een overzicht van catalogi. Met de blauwe knop bovenaan kun je een publicatie aanmaken. Dit opent een modal genaamd "Publicatie toevoegen". Er wordt eerst gevraagd aan welke catalogus deze behoort en welke publicatietype het heeft (metadata)

Hieronder is een voorbeeld van een ingevulde modal voor het aanmaken van een Woo-publicatie.

<div align="center">

<figure><img src="../assets/publicatie_toevoegen_modal.png" alt="" width="300" /><figcaption><p>De publicatiemodal</p></figcaption></figure>

</div>

Na het opslaan van de publicatie, is deze zichtbaar onder de catalogi "Woo". Om de publicatie aan te passen, te depubliceren of andere acties uit te voeren, klik je op de blauwe "Actie"-knop rechtsboven bij de getoonde publicatie, of de drie puntjes rechts van de publicatie zelf.\\

Onder is een voorbeeld van een publicatie en de Actie-mogelijkheden.


## Publiceren en terugtrekken

Op de pagina van een publicatie staat het blok Publicatiestatus. Daar zie je of de publicatie een concept is, gepland, openbaar, teruggetrokken of gearchiveerd. Je ziet alleen de knoppen die op dat moment kunnen.

- **Nu publiceren** maakt een concept of geplande publicatie meteen openbaar.
- **Terugtrekken** vraagt om een reden. De publicatie verdwijnt meteen van de publieke site. Elk landelijk kanaal dat haar had, krijgt een intrekking: altijd de Woo-index, en PLOOI als ze daar was afgeleverd. De melding noemt elk kanaal dat nog niet heeft bevestigd.
- **Opnieuw publiceren** maakt een teruggetrokken publicatie weer openbaar. Eerdere intrekkingen blijven in de geschiedenis staan.

Wil je één document terugtrekken? Kies het dan in het venster Terugtrekken. Alleen dat document verdwijnt van de publieke site en uit de sitemap. De rest van de publicatie blijft openbaar.

Archiveren blijft de laatste stap voor bewaren. Het is geen manier om terug te trekken.

Je hebt het recht nodig om de publicatie te wijzigen. Zonder dat recht weigert de server, en er gebeurt niets.

## Publicaties sorteren

De publicatielijst opent gesorteerd op aanmaakdatum, de nieuwste bovenaan. De pijl omlaag in de kolom *Gemaakt* laat dat zien.

Een gewone klik op een kolomkop, bijvoorbeeld *Titel*, *Status*, *Gemaakt* of *Bijgewerkt*, maakt die kolom de enige actieve sortering. Klik je nog een keer op dezelfde kolomkop, dan draait de volgorde om. Omdat *Gemaakt* al actief is, zet de eerste klik daar meteen de oudste bovenaan.

Een derde klik haalt de kolomsortering weg. De lijst staat dan in de volgorde waarin de publicaties zijn toegevoegd, en na herladen staat de nieuwste weer bovenaan.

De sortering geldt voor alle publicaties, niet alleen voor de rijen die op het scherm staan. Wie de sortering wijzigt, gaat terug naar pagina 1.

De gekozen sortering staat in het adres van de pagina. Ze blijft dus behouden tijdens het bladeren en na het herladen, en een collega die de gekopieerde link opent, ziet de lijst in dezelfde volgorde.

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
