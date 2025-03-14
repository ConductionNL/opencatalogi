"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[1975],{6652:(e,n,t)=>{t.r(n),t.d(n,{assets:()=>s,contentTitle:()=>d,default:()=>g,frontMatter:()=>o,metadata:()=>a,toc:()=>l});const a=JSON.parse('{"id":"handleidingen/Architectuur_old","title":"Architectuur","description":"Bodyless","source":"@site/docs/handleidingen/Architectuur_old.md","sourceDirName":"handleidingen","slug":"/handleidingen/Architectuur_old","permalink":"/docs/handleidingen/Architectuur_old","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/opencatalogi/tree/main/website/docs/handleidingen/Architectuur_old.md","tags":[],"version":"current","frontMatter":{},"sidebar":"tutorialSidebar","previous":{"title":"Architecture","permalink":"/docs/handleidingen/Architectuur_en"},"next":{"title":"Features","permalink":"/docs/handleidingen/Features"}}');var i=t(4848),r=t(8453);const o={},d="Architectuur",s={},l=[{value:"Bodyless",id:"bodyless",level:2},{value:"Federatieve netwerk",id:"federatieve-netwerk",level:2},{value:"Datamodel",id:"datamodel",level:2},{value:"Hoe vormt OpenCatalogi een gefedereerd netwerk?",id:"hoe-vormt-opencatalogi-een-gefedereerd-netwerk",level:2},{value:"Hoe maakt OpenCatalogi gebruik van een gefedereerd netwerk?",id:"hoe-maakt-opencatalogi-gebruik-van-een-gefedereerd-netwerk",level:2}];function c(e){const n={a:"a",blockquote:"blockquote",code:"code",h1:"h1",h2:"h2",header:"header",img:"img",li:"li",p:"p",strong:"strong",ul:"ul",...(0,r.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(n.header,{children:(0,i.jsx)(n.h1,{id:"architectuur",children:"Architectuur"})}),"\n",(0,i.jsx)(n.h2,{id:"bodyless",children:"Bodyless"}),"\n",(0,i.jsx)(n.p,{children:"In de meest simpele opzet is een OpenCatalogi-installatie een stateless/platte React frontend die gegevens weergeeft uit het OpenCatalogi federatieve netwerk."}),"\n",(0,i.jsx)(n.p,{children:"Als er geen aanvullende business logica van toepassing is (zoals rollen en rechten) en de data uit het federatieve netwerk direct wordt weergegeven is er geen noodzaak voor een backend."}),"\n",(0,i.jsx)(n.h2,{id:"federatieve-netwerk",children:"Federatieve netwerk"}),"\n",(0,i.jsx)(n.h2,{id:"datamodel",children:"Datamodel"}),"\n",(0,i.jsx)(n.p,{children:"Het datamodel van OpenCatalogi is gebaseerd op Public Code, een Europese standaard voor het beschrijven van opensource-projecten. Dit model vertaald naar een OpenAPI-beschrijving in lijn met de NL API-strategie. Deze is standaard is tevens aangevuld met elementen uit de huidige Common Ground catalogus en developer.overheid om te komen tot een overkoepeld datamodel voor opensource in Nederland."}),"\n",(0,i.jsx)(n.p,{children:"Lees meer:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:(0,i.jsx)(n.a,{href:"https://conduction.stoplight.io/docs/publiccode",children:"Het volledige datamodel"})}),"\n",(0,i.jsx)(n.li,{children:(0,i.jsx)(n.a,{href:"https://github.com/OpenCatalogi/.github/discussions/10",children:"Afwijkingen ten opzichte van publiccode"})}),"\n"]}),"\n",(0,i.jsx)(n.p,{children:"Het systeem is verdeeld in verschillende lagen. Laag 5 is de interactielaag, Laag 4 is de logische laag en Laag 1 is de datalaag."}),"\n",(0,i.jsx)(n.p,{children:"Laag 5 (Interactie) bevat de gebruikersinterface en de beheerdersinterface. Deze interfaces zijn respectievelijk ondergebracht in React Container 1 en React Container 2. De gebruiker en beheerder communiceren met deze interfaces via webbrowsers. De interactie van de gebruiker via de browser is anoniem, terwijl de interactie van de beheerder JWT-claims bevat."}),"\n",(0,i.jsx)(n.p,{children:"Laag 4 (Logica) is de kern van het systeem en bestaat uit meerdere componenten. De NGINX-container bevat de Nginx-grens die de Web Gateway uitvoert, die is ondergebracht in de Gateway Container. De Gateway Container bevat ook de OpenCatalogi-plugin en de ORM (Object-Relationele Mapping). De Gateway implementeert deze plug-ins en communiceert met het identiteitscomponent in de Azure-cloud. De Gateway maakt ook indexen naar MongoDB, caches naar Redis en slaat gegevens op in de ORM."}),"\n",(0,i.jsx)(n.p,{children:"De Redis Container bevat het Redis-component en de MongoDB Container bevat de MongoDB-database. De Gateway logt naar Loki en rapporteert aan Prometheus. De OpenCatalogi-plugin wisselt informatie uit met de externe catalogus op basis van PKI (Public Key Infrastructure)."}),"\n",(0,i.jsx)(n.p,{children:"Laag 1 (Data) bevat een Database Service die verschillende databasesystemen bevat zoals PostgreSQL, MsSQL, MySQL en Oracle. De ORM slaat gegevens op in deze databases."}),"\n",(0,i.jsx)(n.p,{children:"Het systeem is ondergebracht in een Kubernetes-cluster. Het ingress-component maakt de gebruikersinterface, de beheerdersinterface en het Nginx-component beschikbaar. Het ingress-component communiceert met F5 extern alleen voor openbare eindpunten en objecten, en met F5 intern voor alle eindpunten. Het communiceert ook met het Hipp-component voor catalogusuitwisseling."}),"\n",(0,i.jsx)(n.p,{children:"De externe catalogus communiceert met het Hipp-component met behulp van PKIO. Het Hipp-component valt buiten de scope van het systeem."}),"\n",(0,i.jsx)(n.p,{children:"De Azure-cloud bevat het ADFS-component dat fungeert als een identiteitsprovider."}),"\n",(0,i.jsx)(n.p,{children:"Ten slotte omvat het systeem een externe catalogusacteur die communiceert met het Hipp-component, en een beheerdersacteur die communiceert met het F5 intern-component via een browser met JWT-claims. Er is ook een gebruikersacteur die communiceert met het F5 extern-component via een anonieme browser."}),"\n",(0,i.jsxs)(n.p,{children:[(0,i.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/oc_user.svg",alt:"OpenCatalogi User diagram",title:"OpenCatalogi User diagram"}),"\n",(0,i.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/oc_admin.svg",alt:"OpenCatalogi Admin diagram",title:"OpenCatalogi Admin diagram"}),"\n",(0,i.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/oc_extern.svg",alt:"OpenCatalogi Extern diagram",title:"OpenCatalogi Extern diagram"})]}),"\n",(0,i.jsx)(n.h2,{id:"hoe-vormt-opencatalogi-een-gefedereerd-netwerk",children:"Hoe vormt OpenCatalogi een gefedereerd netwerk?"}),"\n",(0,i.jsx)(n.p,{children:"Elke OpenCatalogi-installatie (aangeduid als een Catalogus) onderhoudt een directorylijst van andere bekende installaties (of catalogi). Wanneer een nieuwe installatie aan het netwerk wordt toegevoegd, moet deze op de hoogte zijn van, of ten minste \xe9\xe9n bestaande installatie vinden. Deze bestaande installatie verstrekt zijn directory aan de nieuwe installatie, waardoor deze op de hoogte wordt gebracht van alle andere bekende installaties. Tijdens dit proces wordt de nieuwe installatie ook toegevoegd aan de directory van de bestaande installatie, die als referentie wordt gebruikt."}),"\n",(0,i.jsx)(n.p,{children:"Vervolgens communiceert de nieuwe installatie met alle andere installaties die vermeld staan in zijn directory. Het doel van deze communicatie is tweeledig: het aankondigen van zijn toevoeging aan het netwerk en informeren of ze op de hoogte zijn van andere installaties die nog niet zijn opgenomen in de directory van de nieuwe installatie."}),"\n",(0,i.jsx)(n.p,{children:"Dit onderzoekproces wordt regelmatig herhaald. Omdat elke installatie zijn eigen directory bijhoudt, blijft het netwerk robuust en operationeel, zelfs als een individuele installatie niet beschikbaar is."}),"\n",(0,i.jsx)(n.p,{children:(0,i.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/createnetwork.svg",alt:"Sequence Diagram network creation",title:"Sequence Diagram network creation"})}),"\n",(0,i.jsx)(n.h2,{id:"hoe-maakt-opencatalogi-gebruik-van-een-gefedereerd-netwerk",children:"Hoe maakt OpenCatalogi gebruik van een gefedereerd netwerk?"}),"\n",(0,i.jsxs)(n.p,{children:[(0,i.jsx)(n.strong,{children:"Live gegevens"}),":\nTelkens wanneer een query wordt uitgevoerd naar het ",(0,i.jsx)(n.code,{children:"/search"})," eindpunt van een OpenCatalogi-installatie, zoekt het antwoorden in zijn eigen MongoDB-index op basis van bepaalde filters. Tegelijkertijd controleert het ook zijn directory van bekende catalogi om andere catalogi te vinden die mogelijk de gevraagde gegevens bevatten en waar de oorspronkelijke catalogus toegang toe heeft. De query wordt ook asynchroon naar deze catalogi verzonden, en de reacties worden gecombineerd, tenzij een vooraf gedefinieerde time-outdrempel wordt bereikt."]}),"\n",(0,i.jsx)(n.p,{children:(0,i.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/live.svg",alt:"Live data Diagram",title:"Live data Diagram"})}),"\n",(0,i.jsxs)(n.p,{children:[(0,i.jsx)(n.strong,{children:"Ge\xefndexeerde gegevens"}),":\nOpenCatalogi geeft de voorkeur aan het indexeren van gegevens wanneer de bron dit toestaat. Tijdens elke uitvoer van netwerksynchronisatie (zoals uitgelegd in 'Hoe vormt OpenCatalogi een gefedereerd netwerk?'), worden alle gegevens die kunnen worden ge\xefndexeerd, ge\xefndexeerd als de bron is ingesteld op indexering. Het is belangrijk op te merken dat wanneer een object wordt gedeeld vanuit een andere catalogus, er een cloudgebeurtenisabonnement wordt gemaakt. Dit betekent dat wanneer het object wordt bijgewerkt in die catalogus, de wijzigingen ook vrijwel direct worden bijgewerkt in de lokale installatie."]}),"\n",(0,i.jsxs)(n.blockquote,{children:["\n",(0,i.jsx)(n.p,{children:":note:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Bronnen worden pas gebruikt door een catalogus als de beheerder hiervoor akkoord heeft gegeven"}),"\n",(0,i.jsx)(n.li,{children:"Bronnen kunnen zelf voorwaarden stellen aan het gebruikt (bijvoorbeeld alleen met PKI-certificaat, of aan de hand van API-sleutel)"}),"\n"]}),"\n"]})]})}function g(e={}){const{wrapper:n}={...(0,r.R)(),...e.components};return n?(0,i.jsx)(n,{...e,children:(0,i.jsx)(c,{...e})}):c(e)}},8453:(e,n,t)=>{t.d(n,{R:()=>o,x:()=>d});var a=t(6540);const i={},r=a.createContext(i);function o(e){const n=a.useContext(r);return a.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function d(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:o(e.components),a.createElement(r.Provider,{value:n},e.children)}}}]);