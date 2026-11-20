---
description: >-
  Waar de waardelijsten en de weigeringsgronden van OpenCatalogi vandaan komen,
  en hoe een integrator elke URI op één plek vindt.
---

# Waardelijsten en weigeringsgronden

## Weigeringsgronden

De weigeringsgronden van de Woo (artikel 5.1 en 5.2) zijn van dossiq. Is dossiq
geïnstalleerd, dan leest OpenCatalogi de lijst daar: een medewerker kiest bij een
beoordeling alleen een grond uit die lijst, en een opgeslagen beoordeling met een
andere code wordt geweigerd. Kan dossiq zijn lijst niet lezen, dan wordt het
opslaan geweigerd met de reden; OpenCatalogi valt dan niet terug op een eigen kopie.

Zonder dossiq gebruikt OpenCatalogi een vaste kopie van de lijst die dossiq bij een
release meelevert (`lib/Settings/woo-refusal-grounds.snapshot.json`). Die kopie dient
één doel: een grond noemen bij een gelakte passage van een publicatiebatch. U kunt de
gronden dan kiezen, maar niet wijzigen. De Woo-instellingen zeggen dit onder
**Weigeringsgronden**. Een Woo-verzoek gebruikt de kopie nooit.

Bij de upgrade zet de herstelstap `MapStoredRefusalGrounds` de gronden die al op
beoordelingen staan om naar de codes van dossiq. Vijf eerdere codes hadden een
andere betekenis dan de code met dezelfde spelling in de huidige lijst; die worden
op betekenis omgezet (bijvoorbeeld 5.1.2.f, milieu, wordt 5.1.2.g). Een code zonder
eenduidige betekenis, zoals 5.2.e, blijft staan, wordt gemarkeerd in
`unmappedGrounds` en staat in de uitvoer van de upgrade. Een tweede run verandert niets.

## Alle waardelijsten op één plek

`GET /index.php/apps/opencatalogi/api/value-lists` geeft elke waarde van elke lijst
die OpenCatalogi gebruikt, zonder inloggen en met CORS: de informatiecategorieën,
soort handeling, documentsoorten en talen van TOOI en DiWoo, de DCAT-lijsten
(HVD-categorieën en datathema's) en de weigeringsgronden. Elke regel heeft
`scheme`, `schemeUri`, `code`, `label`, `uri`, `status` en `lastRefreshAt`.
Filter op één lijst met `?scheme=documentsoorten`. Een lijst die niet gelezen kan
worden staat in `unavailable` en wordt niet als lege lijst gegeven.
