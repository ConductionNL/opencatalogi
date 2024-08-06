# De OpenCatalogi-app toevoegen aan Nextcloud

We gaan er voor deze stap vanuit dat je reeds een werkende lokale Nextcloud-omgeving hebt met daarin de code voor de app, heb je die nog niet kijk dan eerst onder Installatie van [Nextcloud Demo/Test-omgeving](installatie-van-nextcloud-demo-test-omgeving.md) of[Installatie van Nextcloud Development](../assets/instructies.md)-omgeving

## De app activeren

De code voor de app staat er nu, maar in Nextcloud moeten apps geactiveerd worden voor gebruik. Hiervoor kan er geklikt worden op het profiel icoontje rechts bovenin. Kies voor "Apps".

![next cloud app menu](../assets/activate-app-menu.png)

In het volgende scherm, zie je aan de linkerzijde een navigatie menu. Klik op "Disabled apps". Je ziet een overzicht met de apps die in de "apps-extra"-directory zitten.

![nextcloud uitgeschakelde apps](../assets/nc\_disabled\_apps.png)

Zodra je klikt op "enable" verschijnt deze links bovenin, naast het Nextcloud-logo. Dit zijn de shortcut-icoontjes voor de geactiveerde applicaties.

Klik op het icoontje van OpenCatalogi en je zit in de app!

![Nexcloud app menu](<../assets/nc\_app\_menu (1).png>)

## De app configuren

* Open de OpenCatalogi-app via de navigeerbalk links bovenin het scherm. Het zal het meest rechtse icoontje zijn.

![OpenCatalogi instellingen](../assets/oc\_instellingen\_1.png)

* Vul bij 'instellingen' -> 'configuratie' de benodigde gegevens in:
  * Een MongoDB API met sleutel en clusternaam.
  * Voor het activeren van het zoekendpoint: een Elasticsearch met API key en index.