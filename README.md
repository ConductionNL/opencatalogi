---
description: >-
  Welkom bij de gebruikersdocumentatie voor de OpenCatalogi Nextcloud App. Veel
  succes met het gebruik van de app.
---

# Welkom

***

Deze documentatie richt zich op het gebruik van onze beheerapplicatie, speciaal ontworpen voor het beheren van publicaties en catalogi binnen het federatief netwerk. De OpenCatalogi Nextcloud App is een eenvoudig te installeren en krachtige oplossing voor publicatiebeheer:

* [**Quickstart**](installatie/instructies.md) voor een test/demo-omgeving
* [**Quickstart** ](developers/installatie-van-nextcloud-development-omgeving.md)voor een development-omgeving

Onze app ondersteunt de Common Ground-aanpak, waardoor je snel toegang hebt tot bestaande IT-oplossingen die je kunt hergebruiken om de ontwikkeltijd te verkorten en de kosten te verlagen. In deze gids vind je stapsgewijze instructies, nuttige tips en best practices om je te helpen bij het optimaal beheren van je federatief netwerk, zoals publicaties of softwarecomponenten.

Deze documentatie is bedoeld voor diverse doelgroepen:

* **Gebruikers:** iedereen die wil delen binnen het netwerk.
* **Developers:** Ontwikkelaars die bijdragen aan de OpenCatalogi-projecten en behoefte hebben aan gedetailleerde technische informatie en API-documentatie.
* **Beheerders:** Professionals die verantwoordelijk zijn voor het beheren en onderhouden van het federatief netwerk voor publicaties en componenten.

Voor meer informatie over OpenCatalogi en onze gemeenschappelijke inspanningen, bezoek onze [documentatie-pagina](https://documentatie.opencatalogi.nl) of de officiële website op [OpenCatalogi.nl](https://opencatalogi.nl).

Veel succes met het gebruik van de app. Voor vragen of bijdragen, neem gerust contact met ons op via [support@conduction.nl](mailto:support@conduction.nl).

## Robots.txt and sitemap.xml

This app has a robots.txt that exposes public endpoints for search engine indexing and service discovery.
The public endpoints are fetchable publications for a specific catalog. Each catalog has its own sitemap which can link to other public urls on its own.

### Endpoints
| Endpoint | Description |
|-----------|--------------|
| `{domain}/apps/opencatalogi/robots.txt` | Lists all catalog sitemap URLs. |
| `{domain}/apps/opencatalogi/{catalogiSlug}/sitemap.xml` | Sitemap for a specific catalog. |

### Examples

Robots.txt:

```
User-agent: *
Disallow:
Sitemap: https://cloud.example.com/index.php/apps/opencatalogi/catalogSlug/sitemap.xml
Sitemap: https://cloud.example.com/index.php/apps/opencatalogi/catalogSlug/sitemap.xml
```
Sitemap.xml:
```
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://cloud.example.com/index.php/apps/opencatalogi/catalogSlug/publications</loc>
    <lastmod>2025-10-29</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
```
