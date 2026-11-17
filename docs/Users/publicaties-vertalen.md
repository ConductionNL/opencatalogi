# Publicaties vertalen

Een publicatie en een catalogus schrijf je in het Nederlands. Daarnaast kun je de titel, de samenvatting en de beschrijving in andere talen schrijven. De andere velden, zoals de organisatie, de Woo-categorie, de datums en de bewaartermijn, zijn in elke taal gelijk.

## Welke talen er zijn

De beheerder kiest bij Instellingen, onder Publicatie-opties, welke talen redacteuren aangeboden krijgen. Standaard zijn dat Nederlands en Engels. Nederlands is de brontaal en staat er altijd bij.

## Een vertaling schrijven

1. Open de publicatie of de catalogus om te bewerken.
2. Kies bovenaan bij Taal de taal waarin je wilt schrijven. Nederlands staat er als bron.
3. Schrijf de titel, de samenvatting of de beschrijving in die taal. Een veld dat je leeg laat, valt terug op het Nederlands.
4. Sla op. Alleen de velden die je in die taal hebt gewijzigd worden opgeslagen. Het Nederlands blijft zoals het was.

In een andere taal dan Nederlands zijn de overige velden alleen-lezen. Die wijzig je in het Nederlands.

Is het Nederlands gewijzigd nadat een veld vertaald is, dan meldt het formulier dat bij die taal: "Het Nederlands is gewijzigd sinds dit is vertaald", met de velden waar het om gaat. Lees de vertaling dan na.

## Zien wat vertaald is

Op de pagina van een publicatie staat de kaart Talen. Per taal lees je daar of het de brontaal is, of de vertaling volledig is, of welke velden ontbreken of verouderd zijn. Bijvoorbeeld: "Titel en samenvatting vertaald, beschrijving ontbreekt". Met de knop Vertalen in de editor open je het bewerkformulier meteen in die taal.

## Wat een bezoeker ziet

De publieke API antwoordt in de taal die de bezoeker vraagt, met `?_lang=en` of met de `Accept-Language` van de browser. Een veld zonder vertaling in die taal krijgt de bezoeker in het Nederlands. Een onbekende taalcode geeft gewoon het Nederlands, nooit een foutmelding. Het antwoord noemt de geleverde taal in de kop `Content-Language` en de brontaal in `X-Source-Language`.

De DCAT-feed levert elke taal die een publicatie heeft als eigen waarde met een taalcode, zodat een harvester alle vertalingen ziet.

De Woo-sitemap en het Woo-index blijven de Nederlandse tekst gebruiken.
