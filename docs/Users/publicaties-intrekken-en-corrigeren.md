# Publicaties corrigeren en terugtrekken

Een openbare publicatie kan later nog veranderen. Je corrigeert een titel of vervangt een document, of je trekt de publicatie helemaal terug. OpenCatalogi houdt in beide gevallen bij wat er eerder openbaar stond, zodat een link die iemand al gedeeld of geciteerd heeft niet zomaar verdwijnt.

## Eerdere versies

Wijzig je een publicatie die openbaar is, dan bewaart OpenCatalogi eerst de versie die bezoekers tot dan toe zagen. Dat gebeurt alleen als er iets verandert wat bezoekers ook lezen: een veld dat de publieke API teruggeeft, of de set gedeelde documenten. Een interne aantekening wijzigen maakt geen nieuwe versie.

Een versie bevat:

- de velden zoals de publieke API ze op dat moment gaf, en niets wat niet openbaar was;
- een kopie van elk gedeeld document, zodat het oude document te lezen blijft ook als je het vervangt;
- het versienummer, vanaf 1, en het moment waarop de versie is vervangen.

Bezoekers lezen de versies via de publieke API:

- `GET /api/{catalogus}/{id}/versions` geeft de lijst met versienummer, datum en link;
- `GET /api/{catalogus}/{id}/versions/{versie}` geeft de velden en documenten van die versie, met `superseded: true` en de link naar de huidige versie.

Versies zijn alleen te lezen zolang de publicatie zelf openbaar is. Trek je de publicatie terug, dan geven ook de versies 404.

Lukt het bewaren van een versie niet, bijvoorbeeld omdat een document niet gekopieerd kan worden, dan wordt de wijziging geweigerd. De publicatie blijft dan zoals ze was. Probeer het later opnieuw of vraag de beheerder om in het logboek te kijken.

## Terugtrekken en het grafsteen-antwoord

Met Publicatie terugtrekken op de pagina van een publicatie haal je haar van de publieke kant. In het venster vul je in:

- **Reden**: de interne reden. Die is alleen voor medewerkers en wordt bij de intrekking en bij de bevriezing opgeslagen.
- **Openbare reden**: wat een bezoeker leest die de oude link opent, bijvoorbeeld "Gepubliceerd in strijd met de AVG". Laat je dit leeg, dan krijgt de bezoeker geen reden.
- **Titel tonen na intrekking**: staat dit aan, dan noemt het antwoord ook de titel. Standaard staat het uit, omdat de titel zelf soms de reden van intrekking is.

Opent iemand daarna de link van de publicatie, dan antwoordt de publieke API met 410 (Gone) in plaats van 404. Het antwoord bevat alleen het id, de datum van intrekking, de openbare reden en, als je dat koos, de titel. Samenvatting, beschrijving en documenten staan er nooit in.

Een concept dat nooit openbaar is geweest, blijft gewoon 404 geven. Zo verraadt een link niet dat er ooit een concept bestond.

Een teruggetrokken publicatie staat niet meer in de Woo-sitemap en niet in wat andere catalogi via de federatie ophalen.

Trek je één document terug in plaats van de hele publicatie, dan vraagt het venster niet om een openbare reden en blijft de publicatie zelf openbaar en bewerkbaar.

## Bevroren na terugtrekken

Bij het terugtrekken van een hele publicatie wordt ze ook bevroren, met de interne reden. Een bevroren publicatie kun je niet meer wijzigen:

- een nieuwe titel of een ander veld opslaan wordt geweigerd;
- een document uploaden of koppelen wordt geweigerd, in OpenCatalogi en ook via de bestanden van OpenRegister.

De melding noemt wie de publicatie bevroor en wanneer, bijvoorbeeld: "Cannot write to this object: it was frozen by alice on 2026-11-23. Unfreeze it before changing it."

Op de pagina van de publicatie zie je dat vooraf. De kaart Zichtbaarheid meldt wanneer, door wie en met welke reden de publicatie bevroren is. Bewerken staat uit. De kaart Bijlagen toont de documenten nog wel, maar biedt geen Bijlage toevoegen en geen volgorde-knoppen. Een teruggetrokken document krijgt geen knop Opnieuw publiceren.

Lukt het bevriezen niet, dan blijft de publicatie toch teruggetrokken. Het antwoord op het terugtrekken meldt dan `frozen: false` met de fout, zodat je weet dat de publicatie nog wel te wijzigen is. Vraag in dat geval de beheerder om het bevriezen te herstellen.

Moet een teruggetrokken publicatie toch weer veranderen, dan heft een beheerder eerst de bevriezing op in OpenRegister.
