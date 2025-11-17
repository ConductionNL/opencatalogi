# Robots.txt and sitemap.xml

- https://sitemaps.org/protocol.html

Robots.txt is a textfile of public endpoints that can be crawled through by crawler bots. Its purpose is to let these bots know what pages should be indexed/crawled through and which not, so pages that should not be crawled through are also noted.

The robots.txt response looks like:

```
User-agent: *
Disallow:

Sitemap: https://cloud.example.com/index.php/apps/opencatalogi/{catalogiSlug}/sitemap.xml
Sitemap: https://cloud.example.com/index.php/apps/opencatalogi/{catalogiSlug}/sitemap.xml
```

This endpoint can be found `/apps/opencatalogi/robots.txt` and shows other sitemap.xml endpoints.
The RobotsController handles this.

The robots.txt and sitemap.xml endpoints are not accessable from the root of the domain due to app structure in Nextcloud.
If these endpoints need to be accessed from the root of the domain a proxy should be configured from another domain which can be a frontend or any other service. 

For our Woo frontend we can change that on the base of the container-setup-v1 branch of repo woo-website-template-apiv2 should be added in /pwa/docker/default.conf.template: 

location = /robots.txt {
    proxy_pass ${UPSTREAM_BASE}/index.php/apps/opencatalogi/robots.txt;

    proxy_set_header Host ${UPSTREAM_BASE};

    proxy_set_header X-Real-IP $remote_addr;

    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    proxy_set_header X-Forwarded-Proto $scheme;

}

## Woo robots.txt

The default endpoint for robots.txt is `/apps/opencatalogi/robots.txt`.

RobotsController will build a txt response that will return all known public endpoints.

If enabled on a Catalog the robots.txt can also show specific Woo informatiecategorieen endpoints.
This can be toggled on or off on the create catalog modal and is turned off by default.

This option is called hasWooSitemap on a Catalog.

![alt text](image.png)

If enabled the robots.txt will be expanded with urls foreach Woo informatiecategorie.
That expansion on the robots.txt will look like:

```
 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat001.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat002.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat003.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat004.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat005.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat006.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat007.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat008.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat009.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat010.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat011.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat012.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat013.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat014.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat015.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat016.xml  

 Sitemap: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat017.xml 
```

So the RobotsController must check if the hasWooSitemap option is enabled on the Catalog and add these urls to the robots.txt.

## General sitemap.xml

Each catalog on OpenCatalogi has its own generic sitemap.xml on `/apps/opencatalogi/{catalogSlug}/sitemap.xml`

The sitemap returns a response like:

```
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9 ">
  <url>
    <loc><https://cloud.example.com/index.php/apps/opencatalogi/{catalogiSlug}/publications</loc>>
    <lastmod>2025-10-29</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
```

The catalog currently only shows its publication endpoint if its set public. `/apps/opencatalogi/{catalogSlug}/pubications`
Other public endpoints and pages about the catalog should also be noted in this sitemap.xml.

## Woo sitemap.xml

There is also a sitemap specifically for Woo catalogs. It follows the open overheid standaard https://standaarden.overheid.nl/diwoo/metadata/doc/0.9.8/handleiding-sitemapindex-en-sitemaps.html


If hasWooSitemap toggled on the in the Catalog the robots.txt will show these endpoints.
This can be toggled on or off on the create catalog modal and is turned off by default.

It can be accessed through endpoint like: https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat001.xml

The diwoo code must be translated back to a Woo schema.

This endpoint only show the acutal endpoint in xml sitemap format where Publications and their Documents for that specific Woo category can be fetched from also in xml format.  

This endpoint must have pagination and also based on total publications must show all requestable pages in this xml. So some pre fetching must be necessary.

```
<?xml version="1.0" encoding="utf-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat001.xml/publications?page=1</loc>
        <lastmod>2025-11-11 11:32:33</lastmod>
    </sitemap>
    <sitemap>
        <loc>https://cloud.example.com/apps/opencatalogi/catalogs/{catalogSlug}/sitemaps/sitemapindex-diwoo-infocat001.xml/publications?page=2</loc>
        <lastmod>2025-11-11 11:32:33</lastmod>
    </sitemap>
</sitemapindex>
```


When visiting that endpoint it shows multiple publications and documents of that Woo categorie in .xml format.
So the controller must fetch all publications of catalog and schema (category) and render publications mapped to the following xml format:

```
Show woo document specific sitemap.xml
{
    "loc": "document.url",
    "lastmod": "{{ document._self.dateModified|date(\"Y-m-d H:i:s\") }}",
    "diwoo:Document.diwoo:DiWoo.diwoo:creatiedatum": "{{ document._self.dateCreated|date('Y-m-d') }}",
    "diwoo:Document.diwoo:DiWoo.diwoo:publisher.@resource": "publisher.resource",
    "diwoo:Document.diwoo:DiWoo.diwoo:publisher.#": "publisher.name",
    "diwoo:Document.diwoo:DiWoo.diwoo:format.@resource": "http://publications.europa.eu/resource/authority/file-type/{{ document.extension|upper }}",
    "diwoo:Document.diwoo:DiWoo.diwoo:format.#": "{{ document.extension|lower }}",
    "diwoo:Document.diwoo:DiWoo.diwoo:classificatiecollectie.diwoo:informatiecategorieen.diwoo:informatiecategorie.#": "object.categorie",
    "diwoo:Document.diwoo:DiWoo.diwoo:classificatiecollectie.diwoo:informatiecategorieen.diwoo:informatiecategorie.@resource": "https:\/\/identifier.overheid.nl\/tooi\/def\/thes\/kern\/{{ object.categorie|trans({'Wetten en algemeen verbindende voorschriften': 'c_139c6280', 'Overige besluiten van algemene strekking': 'c_aab6bfc7', 'Ontwerpen van wet- en regelgeving met adviesaanvraag': 'c_759721e2', 'Organisatie en werkwijze': 'c_40a05794', 'Bereikbaarheidsgegevens': 'c_89ee6784', 'Bij vertegenwoordigende organen ingekomen stukken': 'c_8c840238', 'Vergaderstukken Staten-Generaal': 'c_c76862ab', 'Vergaderstukken decentrale overheden': 'c_db4862c3', 'Agenda\\'s en besluitenlijsten bestuurscolleges': 'c_3a248e3a', 'Adviezen': 'c_99a836c7', 'Convenanten': 'c_8fc2335c', 'Jaarplannen en jaarverslagen': 'c_c6cd1213', 'Subsidieverplichtingen anders dan met beschikking': 'c_cf268088', 'Woo-verzoeken en -besluiten': 'c_3baef532', 'Onderzoeksrapporten': 'c_fdaee95e', 'Beschikkingen': 'c_46a81018', 'Klachtoordelen': 'c_a870c43d'}, '', 'en') }}",
    "diwoo:Document.diwoo:DiWoo.diwoo:documenthandelingen.diwoo:documenthandeling.diwoo:soortHandeling.#": "ontvangst",
    "diwoo:Document.diwoo:DiWoo.diwoo:documenthandelingen.diwoo:documenthandeling.diwoo:soortHandeling.@resource": "https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535",
    "diwoo:Document.diwoo:DiWoo.diwoo:documenthandelingen.diwoo:documenthandeling.diwoo:atTime": "{{ object.publicatiedatum }}"
  }
```

```mermaid
flowchart TD
    A[robots.txt] --> B1[sitemapindex-diwoo-infocat001.xml]
    A --> B2[sitemapindex-diwoo-infocat002.xml]

    %% Each category sitemap points to paginated sitemaps
    B1 --> C1[sitemapindex-diwoo-infocat001.xml/publications?page=1]
    B1 --> C2[sitemapindex-diwoo-infocat001.xml/publications?page=2]

    B2 --> C3[sitemapindex-diwoo-infocat002.xml/publications?page=1]
    B2 --> C4[sitemapindex-diwoo-infocat002.xml/publications?page=2]


    %% Paginated sitemaps contain multiple documents
    C1 --> D1[Document 1]
    C1 --> D2[Document 2]
    C2 --> D3[Document 3]
    C2 --> D4[Document 4]

    C3 --> D5[Document 5]
    C3 --> D6[Document 6]

    C4 --> D7[Document 7]
    C4 --> D8[Document 8]
