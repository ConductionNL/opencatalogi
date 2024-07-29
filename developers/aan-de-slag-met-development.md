# Aan de slag met development

## Bijdragen

Als nextcloud app volgen we zowiezo de [next cloud publishing guide lines](https://docs.nextcloud.com/server/19/developer\_manual/app/publishing.html#app-guidelines).

Daarbovenop hanteren we een aantal extra spelregeles

* **Features moeten zijn voorzien van gebruikers documentatie**
* **Backend code moet zijn voorzien van automatische tests**: Code de covaragde van het porject verlaagd word niet geacpeteerd, zie ook [php unit testing](https://docs.nextcloud.com/server/latest/developer\_manual/server/unit-testing.html).
* **Backend code moet zuiver zijv**: Code mag _géén_ linting errors bevaten
* **Frontend code moet zijn voorzien van automatische tests**:
* **Frontend code moet zuiver zijn**: Code mag _géén_ linting errors bevaten
* **Seperation of concern**: Voor zowel backend als frontend moet busnes logic zijn opgenomen in services. Controllers, Templates, Views, Components en Store mogen _géén_ busnes logic bevatten
* **Vier ogen princiepe**: Pull requests moeten zijn beoordeeld door een andere developer dan de maker voordat ze worden geacepteerd

## Application development

Omdat de applicatie is ontwikkeld met Nextcloud, is er uitgebreide informatie te vinden in de [Nextcloud-documentatie](https://docs.nextcloud.com/server/latest/developer\_manual/index.html) zelf. Dit geldt zowel voor de lay-out van de app als voor de vele componenten die eraan toegevoegd kunnen worden. Tijdens de ontwikkeling van de OpenCatalogi-app is het _documentation-first_ principe gehanteerd, waarbij de ontwikkelaars eerst de[ Nextcloud-documentatie](https://docs.nextcloud.com/server/latest/developer\_manual/index.html) hebben geraadpleegd.

### Gebruikers documentatie

We gebruiken gitbook voor de gebruikers documentatie, features binnen de app zouden zo veel mogenlijk direct moeten doorverwijzen naar deze documentatie.

## API Development

De ontwikkeling van de API wordt bijgehouden met de documentatietool [Stoplight.io](https://stoplight.io/), die automatisch een OpenAPI Specificatie (OAS) genereert uit de documentatie. De Stoplight voor OpenCatalogi is [hier](https://conduction.stoplight.io/docs/open-catalogi/6yuj08rgf7w44-open-catalogi-api) te vinden.

## Frontend Development

### Storage en Typing

Om gegevens deelbaar te maken tussen de verschillende vue comopenten maken we gebruik van [statemanagment](https://vuejs.org/guide/scaling-up/state-management) waarbij we het Action, State, View patroon van vue zelf volgen. Omdat de applicatie ingewikkelder begint te worden stappen we daarbij over van [simple state managment](https://vuejs.org/guide/scaling-up/state-management#simple-state-management-with-reactivity-api) naar [Pinia](https://pinia.vuejs.org/), de door vue zelf geadviseerde opvolger van [vuex](https://vuejs.org/guide/scaling-up/state-management#pinia).

Omdat Pinia vanuit zichzelf al typing ondersteund en daarop testbaar is vervalt daarmee ook de noodzaak om in de voorkant te werken met typescript, de ontwikkeling daarvan is dan ook gestopt.

### Modals

* only one modal may be active at all times
* modals should be abstract and reachable form anywhere
* modals should be places in te src/modals folder
* modals should be triggerd through the state
* modals schould be importerd through /src/modals/Modals.vue

### Views

* Views must have the same file name as the exported name and is a correlation to the map the file is in.
* For example, if the file is a detail page, and it is in the directory `publications` the file must be named `PublicationDetail.vue`.