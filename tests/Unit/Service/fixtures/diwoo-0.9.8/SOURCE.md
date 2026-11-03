Verbatim copies of the DiWoo metadata schema v0.9.8, the version
https://standaarden.overheid.nl/diwoo/metadata declares current (checked 2026-11-03).
The two sitemaps are the example sitemaps the standard publishes on
https://standaarden.overheid.nl/diwoo/metadata/doc/0.9.8/handleiding-voorbeelden.html.

| file | source | sha256 |
|---|---|---|
| diwoo-metadata.xsd | https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xsd/diwoo/diwoo-metadata.xsd | 0a332f65c98f5bb2b4fb64c52c2f72867966a6e5bf1a5a5ea3870716fc6ea212 |
| diwoo-metadata-lijsten.xsd | https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xsd/diwoo/diwoo-metadata-lijsten.xsd | c17fd0daa9eb37c44c774ac73271679a8fc2189805823dcfba6a86c2fd42b308 |
| MDTO-XML1.0.1.xsd | https://www.nationaalarchief.nl/mdto/MDTO-XML1.0.1.xsd | edad88821a1023a9eafca0fa399ee7735ef03481aa186e07bbdaabdc123def34 |
| sitemap-alleenverplicht.xml | https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xml/sitemap-alleenverplicht.xml | a7a0354d60667de9fc85567d379b67d407ce04a78e44d4107530828e33928b32 |
| sitemap-uitgebreid.xml | https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xml/sitemap-uitgebreid.xml | 2224a9d841e54dff52447d9ac9c65c893f70bdb0d5c665dfb675d4c4881c0b61 |

The files are not edited: the test maps the MDTO import URL to the local copy
through libxml's external entity loader, and checks every hash before it validates.
