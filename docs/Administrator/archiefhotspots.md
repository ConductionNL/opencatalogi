---
description: >-
  Een archiefhotspot is een onderwerp waarvan elke publicatie blijvend wordt
  bewaard. Zo wijst een beheerder er een aan, en dit doet het daarna.
---

# Archiefhotspots

Een archiefhotspot is een onderwerp waarover alles bewaard moet blijven, bijvoorbeeld omdat het op een archiefhotspotlijst staat. Wijst een beheerder een onderwerp aan als archiefhotspot, dan wordt elke publicatie onder dat onderwerp blijvend bewaard: ook publicaties die al voor het aanwijzen onder het onderwerp waren ingedeeld.

## Aanwijzen

Alleen een beheerder van Nextcloud kan een onderwerp aanwijzen of de aanwijzing intrekken.

1. Open het onderwerp.
2. Zet **Archiefhotspot** aan.
3. Vul de **Reden voor de archiefhotspot** in, bijvoorbeeld de lijst die het onderwerp noemt.
4. Sla op.

Zonder reden wordt het opslaan geweigerd. Een medewerker die geen beheerder is, kan de aanwijzing niet wijzigen; de rest van het onderwerp wel.

Bij het aanwijzen gebeurt het volgende:

- **Archiefhotspot sinds** krijgt het moment van aanwijzen. Dat veld kunt u niet zelf invullen.
- De waardering van het onderwerp in OpenRegister wordt **blijvend bewaren**. De waardering die het onderwerp daarvoor had, wordt bewaard.
- Het auditlog krijgt een regel `theme.archiveHotspot.marked` met wie het deed en de reden.

## Intrekken

Zet **Archiefhotspot** uit, vul de reden in en sla op. Het onderwerp krijgt de waardering terug die het had voor het aanwijzen, of geen waardering als het er geen had. Het auditlog krijgt een regel `theme.archiveHotspot.unmarked`.

## Wat een archiefhotspot doet

- **Vernietiging door OpenRegister.** Een publicatie neemt de waardering van haar onderwerpen over. Is een van haar onderwerpen een archiefhotspot, dan houdt de vernietigingscontrole van OpenRegister de publicatie tegen en noemt het onderwerp als reden. Dat geldt ook voor publicaties die al voor het aanwijzen onder het onderwerp vielen. Aan de publicatie zelf wordt niets veranderd.
- **De eigen bewaartermijnen van OpenCatalogi.** De dagelijkse bewaarcontrole depubliceert of archiveert een publicatie onder een archiefhotspot niet. Ze telt haar als **heldByHotspot**. Kan een onderwerp van de publicatie niet worden gelezen, dan doet de controle ook niets en telt de publicatie als **heldUnresolved**: bij twijfel blijft de publicatie staan.
- **Het bewaarrapport.** In het bewaarrapport staat bij zo'n publicatie in de kolom met de genomen actie: *Kept permanently: archive hotspot* gevolgd door de naam van het onderwerp.

## Wat een archiefhotspot niet doet

- **Publicaties blijven zichtbaar of onzichtbaar zoals ze waren.** Aanwijzen publiceert niets en trekt niets in.
