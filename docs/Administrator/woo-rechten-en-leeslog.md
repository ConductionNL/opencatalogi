---
description: >-
  Rechten per groep voor de Woo-taken, het leeslog van niet-openbare Woo-records
  en de schakelaar die OpenCatalogi buiten zoekmachines houdt.
---

# Woo-rechten, leeslog en zoekmachines

## Rechten per groep

Acht Woo-taken zijn een eigen recht. Een beheerder heeft ze altijd. Een andere gebruiker heeft een recht alleen als een van zijn groepen het gekregen heeft.

| Recht | Wat het toestaat |
| --- | --- |
| `openInspection` | Een terinzagelegging openen op een publicatie |
| `openCommentPeriod` | Een zienswijzetermijn openen op een publicatie |
| `depublish` | Een publicatie depubliceren en een intrekking bevestigen |
| `announceNationally` | Een publicatie aanmelden bij de landelijke index |
| `decideRetention` | Een bewaarbesluit nemen over een publicatie |
| `processWooBatch` | Een Woo-batch aanmaken en de beoordelingen erin verwerken |
| `publishWooBatch` | Een Woo-batch publiceren |
| `requestWooRegistration` | De aanmelding bij de Woo-index aanvragen en bevestigen |

De rechten staan in het autorisatieblok van het schema `catalog` in OpenRegister. Elk recht verwijst naar een lijst groepen, bijvoorbeeld `"openInspection": ["ter-inzage"]`. Een gebruiker zonder het recht krijgt een foutmelding 403 waarin het ontbrekende recht staat.

Kan OpenCatalogi de lijst niet lezen, dan krijgt niemand behalve een beheerder het recht.

Bij de upgrade krijgt elke groep die het beheer van OpenCatalogi gedelegeerd had alle acht rechten. Zo verliest niemand een taak die hij al deed.

## Leeslog

Elke keer dat iemand een Woo-beoordeling, Woo-batch, depublicatie, Woo-verzoek of publicatie leest, schrijft OpenRegister een regel in het verwerkingslog. De regel valt onder de verwerkingsactiviteit `opencatalogi-woo-records`, met als grondslag de wettelijke plicht uit de Woo. OpenCatalogi legt die activiteit zelf aan.

Je bekijkt het log in OpenRegister onder de verwerkingen. Een lezing blokkeert nooit omdat het loggen mislukt.

De Woo-gereedheidscheck meldt hoeveel lezingen van de afgelopen 30 dagen onder de algemene, ongeclassificeerde activiteit van OpenRegister vielen. Staat daar meer dan nul, dan ontbrak de Woo-activiteit of was hij ingetrokken.

## Zoekmachines

robots.txt sluit de paden voor medewerkers van OpenCatalogi uit en laat de API open. Elk antwoord van een pagina die niet openbaar is, krijgt de kop `X-Robots-Tag: noindex, nofollow`.

Voor een acceptatie- of testomgeving zet je de instelling `search_indexing` op `blocked`. Dan krijgt elk antwoord van OpenCatalogi `noindex` en sluit robots.txt alles uit. De openbare kant blijft gewoon werken.
