"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[5781],{28453:(e,n,i)=>{i.d(n,{R:()=>r,x:()=>o});var a=i(96540);const l={},t=a.createContext(l);function r(e){const n=a.useContext(t);return a.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function o(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(l):e.components||l:r(e.components),a.createElement(t.Provider,{value:n},e.children)}},64087:(e,n,i)=>{i.r(n),i.d(n,{assets:()=>s,contentTitle:()=>o,default:()=>h,frontMatter:()=>r,metadata:()=>a,toc:()=>d});const a=JSON.parse('{"id":"handleidingen/Installatie","title":"Installatie","description":"1. Introductie","source":"@site/docs/handleidingen/Installatie.md","sourceDirName":"handleidingen","slug":"/handleidingen/Installatie","permalink":"/docs/handleidingen/Installatie","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/opencatalogi/tree/main/website/docs/handleidingen/Installatie.md","tags":[],"version":"current","frontMatter":{},"sidebar":"tutorialSidebar","previous":{"title":"Frontend","permalink":"/docs/handleidingen/Frontend"},"next":{"title":"Waarom we overstappen naar Nextcloud","permalink":"/docs/handleidingen/Nextcloud"}}');var l=i(74848),t=i(28453);const r={},o="Installatie",s={},d=[{value:"Introductie",id:"introductie",level:2},{value:"Publiceren",id:"publiceren",level:2},{value:"Publiceren van Componenten",id:"publiceren-van-componenten",level:3},{value:"Publiceren van Organisatie",id:"publiceren-van-organisatie",level:3},{value:"Publiceren Frontend (Portaal)",id:"publiceren-frontend-portaal",level:3},{value:"Gebruiken als SAAS",id:"gebruiken-als-saas",level:2},{value:"Lokaal Installeren",id:"lokaal-installeren",level:2},{value:"Kubernetes/Haven",id:"kuberneteshaven",level:3},{value:"Linux",id:"linux",level:3}];function c(e){const n={a:"a",blockquote:"blockquote",code:"code",em:"em",h1:"h1",h2:"h2",h3:"h3",header:"header",img:"img",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",ul:"ul",...(0,t.R)(),...e.components};return(0,l.jsxs)(l.Fragment,{children:[(0,l.jsx)(n.header,{children:(0,l.jsx)(n.h1,{id:"installatie",children:"Installatie"})}),"\n",(0,l.jsxs)(n.ol,{children:["\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#introductie",children:"Introductie"})}),"\n",(0,l.jsxs)(n.li,{children:[(0,l.jsx)(n.a,{href:"#publiceren",children:"Publiceren"}),"\n",(0,l.jsxs)(n.ul,{children:["\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#publiceren-van-componenten",children:"Publiceren van Componenten"})}),"\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#publiceren-van-organisatie",children:"Publiceren van Organisatie"})}),"\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#publiceren-frontend-portaal",children:"Publiceren Frontend (Portaal)"})}),"\n"]}),"\n"]}),"\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#gebruiken-als-saas",children:"Gebruiken als SAAS"})}),"\n",(0,l.jsxs)(n.li,{children:[(0,l.jsx)(n.a,{href:"#lokaal-installeren",children:"Lokaal Installeren"}),"\n",(0,l.jsxs)(n.ul,{children:["\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#kuberneteshaven",children:"Kubernetes/Haven"})}),"\n",(0,l.jsx)(n.li,{children:(0,l.jsx)(n.a,{href:"#linux",children:"Linux"})}),"\n"]}),"\n"]}),"\n"]}),"\n",(0,l.jsx)(n.h2,{id:"introductie",children:"Introductie"}),"\n",(0,l.jsx)(n.p,{children:"Je hebt geen lokale installatie van OpenCatalogi nodig om het te benutten. Met een GitHub-organisatie kun je eenvoudig openbare data toevoegen en weergeven via het federaal netwerk."}),"\n",(0,l.jsx)(n.h2,{id:"publiceren",children:"Publiceren"}),"\n",(0,l.jsx)(n.h3,{id:"publiceren-van-componenten",children:"Publiceren van Componenten"}),"\n",(0,l.jsxs)(n.p,{children:["De snelste manier is de repository-URL aanmelden via deze ",(0,l.jsx)(n.a,{href:"https://opencatalogi.nl/documentation/usage",children:"link"})]}),"\n",(0,l.jsx)(n.p,{children:"Een abonnement op het publiceren kan ook. Hierdoor wordt de repository door OpenCatlogi bekeken voor wijzigingen. Zie hieronder voor de instructies."}),"\n",(0,l.jsxs)(n.p,{children:["Om componenten (informatie) te publiceren op OpenCatalogi, bieden we een ",(0,l.jsx)(n.a,{href:"https://github.com/marketplace/actions/create-or-update-publiccode-yaml",children:"GitHub-workflow"}),". Voeg simpelweg het workflow-bestand toe aan de repository die je wilt publiceren."]}),"\n",(0,l.jsxs)(n.blockquote,{children:["\n",(0,l.jsxs)(n.ol,{children:["\n",(0,l.jsxs)(n.li,{children:["Maak binnen de repository van uw component een directory aan met de naam ",(0,l.jsx)(n.code,{children:".github"})," (als u deze nog niet heeft)."]}),"\n",(0,l.jsxs)(n.li,{children:["Maak binnen deze directory een map ",(0,l.jsx)(n.code,{children:"workflows"})," aan, die zelf binnen een ",(0,l.jsx)(n.code,{children:".github"})," map hoort te zitten. Plaats daarin deze ",(0,l.jsx)(n.a,{href:"https://github.com/OpenCatalogi/.github/blob/main/.github/workflows/openCatalogi.yaml",children:"workflow.yaml"}),"."]}),"\n",(0,l.jsx)(n.li,{children:"Commit en push het workflow-bestand naar jouw repository."}),"\n"]}),"\n"]}),"\n",(0,l.jsx)(n.p,{children:"Lees meer over de configuratie-opties van de workflow."}),"\n",(0,l.jsx)(n.p,{children:(0,l.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/installation_publiccode.svg",alt:"Publiceren van Componenten",title:"Publiceren van Componenten"})}),"\n",(0,l.jsx)(n.h3,{id:"publiceren-van-organisatie",children:"Publiceren van Organisatie"}),"\n",(0,l.jsxs)(n.p,{children:["Om organisatiegegevens te publiceren op OpenCatalogi, is er eveneens een ",(0,l.jsx)(n.a,{href:"https://github.com/marketplace/actions/create-or-update-publiccode-yaml",children:"GitHub-workflow"})," beschikbaar. Voeg het workflow-bestand toe aan de .github-repository van de organisatie die je wilt publiceren."]}),"\n",(0,l.jsxs)(n.blockquote,{children:["\n",(0,l.jsxs)(n.ol,{children:["\n",(0,l.jsxs)(n.li,{children:["Maak binnen uw GitHub-organisatie een repository aan met de naam ",(0,l.jsx)(n.code,{children:".github"})," (als u deze nog niet heeft)."]}),"\n",(0,l.jsxs)(n.li,{children:["Maak binnen deze repository een map ",(0,l.jsx)(n.code,{children:"workflows"})," aan, die zelf binnen een ",(0,l.jsx)(n.code,{children:".github"})," map hoort te zitten. Plaats daarin deze ",(0,l.jsx)(n.a,{href:"https://github.com/OpenCatalogi/.github/blob/main/.github/workflows/openCatalogi.yaml",children:"workflow.yaml"}),"."]}),"\n",(0,l.jsx)(n.li,{children:"Commit en push het workflow-bestand naar jouw repository."}),"\n"]}),"\n"]}),"\n",(0,l.jsx)(n.p,{children:"Lees meer over de configuratie-opties van de workflow."}),"\n",(0,l.jsx)(n.p,{children:(0,l.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/installation_publicorganisation.svg",alt:"Publiceren van Organisatie",title:"Publiceren van Organisatie"})}),"\n",(0,l.jsx)(n.h3,{id:"publiceren-frontend-portaal",children:"Publiceren Frontend (Portaal)"}),"\n",(0,l.jsxs)(n.p,{children:["Om je eigen OpenCatalogi-portaal te publiceren, bieden we een ",(0,l.jsx)(n.a,{href:"https://github.com/marketplace/actions/create-an-open-catalogi-page",children:"GitHub-workflow aan"}),". Voeg het workflow-bestand toe aan de .github-repository van de organisatie die je wilt publiceren. Publiceer vervolgens handmatig de gegenereerde GitHub Page."]}),"\n",(0,l.jsxs)(n.blockquote,{children:["\n",(0,l.jsxs)(n.ol,{children:["\n",(0,l.jsx)(n.li,{children:"Maak binnen uw github organisaite een repositry aan met de naam .github (als us deze nog niet heeft)"}),"\n",(0,l.jsxs)(n.li,{children:["Maak binnen deze repository een map ",(0,l.jsx)(n.code,{children:".github"})," aan met daarin een map ",(0,l.jsx)(n.code,{children:"workflows"}),"en plaats daarin deze ",(0,l.jsx)(n.a,{href:"https://github.com/OpenCatalogi/.github/blob/main/.github/workflows/openCatalogi.yaml",children:"workflow.yaml"})]}),"\n",(0,l.jsxs)(n.li,{children:["Ga binnen de repository naar instellingen(Settings) -> pagina's(Pages)  en selecteer onder Build en deploy bij ",(0,l.jsx)(n.strong,{children:"Branch"})," ",(0,l.jsx)(n.code,{children:"gh-pages"})]}),"\n"]}),"\n"]}),"\n",(0,l.jsx)(n.p,{children:"Lees meer over de configuratie-opties van de workflow."}),"\n",(0,l.jsx)(n.p,{children:(0,l.jsx)(n.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/installation_frontend.svg",alt:"Publiceren van Frontend",title:"Publiceren van Frontend"})}),"\n",(0,l.jsx)(n.h2,{id:"gebruiken-als-saas",children:"Gebruiken als SAAS"}),"\n",(0,l.jsxs)(n.p,{children:["Als je vertrouwelijke data wilt beheren in OpenCatalogi, kun je de catalogus als SAAS afnemen. Voor alle deelnemersvan OpenCatalogi biedt ",(0,l.jsx)(n.a,{href:"https://www.conduction.nl",children:"Conduction"})," een SAAS-installatie aan. Lees hier meer over deelname aan OpenCatalogi."]}),"\n",(0,l.jsxs)(n.p,{children:["Als je niet wilt deelnemen aan de OpenCatalogi-coalitie maar wel gebruik wilt maken van de SAAS-oplossing, neem dan contact op met ",(0,l.jsx)(n.a,{href:"mailto:info@conduction.nl",children:"Conduction"}),"."]}),"\n",(0,l.jsx)(n.h2,{id:"lokaal-installeren",children:"Lokaal Installeren"}),"\n",(0,l.jsx)(n.p,{children:"Natuurlijk kun je als gebruiker van open-source software OpenCatalogi altijd lokaal installeren. Er zijn twee installatieroutes beschikbaar."}),"\n",(0,l.jsx)(n.h3,{id:"kuberneteshaven",children:"Kubernetes/Haven"}),"\n",(0,l.jsxs)(n.p,{children:["OpenCatalogi is een Common Ground-applicatie opgebouwd uit losse componenten. Deze componenten zijn ondergebracht in afzonderlijke ",(0,l.jsx)(n.a,{href:"https://kubernetes.io/docs/concepts/containers/",children:"Kubernetes-containers"}),". Voor een volledige installatie zijn meerdere containers vereist."]}),"\n",(0,l.jsxs)(n.p,{children:["Er zijn momenteel twee beproefde installatiemethoden. De primaire methode is via een ",(0,l.jsx)(n.a,{href:"https://helm.sh/",children:"Helm"}),"-installatie op Kubernetes. We bieden ook een voorgedefinieerde Helm-repository aan."]}),"\n",(0,l.jsx)(n.p,{children:"Haal de voorgedefinieerde repository op met:"}),"\n",(0,l.jsx)(n.pre,{children:(0,l.jsx)(n.code,{className:"language-cli",children:"helm repo add open-catalogi https://raw.githubusercontent.com/OpenCatalogi/web-app/development/helm/index.yaml\n"})}),"\n",(0,l.jsx)(n.p,{children:"Installeer vervolgens met:"}),"\n",(0,l.jsx)(n.pre,{children:(0,l.jsx)(n.code,{className:"language-cli",children:"helm install [my-opencatalogi] open-catalogi/opencatalogi\n"})}),"\n",(0,l.jsxs)(n.p,{children:["Meer informatie over installatie via Helm vind je op de ",(0,l.jsx)(n.a,{href:"https://helm.sh/",children:"Helm-website"}),". Meer details over de installatieopties zijn beschikbaar op ",(0,l.jsx)(n.a,{href:"https://artifacthub.io/packages/helm/opencatalogi/commonground-gateway?modal=values",children:"Artifact Hub"}),"."]}),"\n",(0,l.jsx)(n.h3,{id:"linux",children:"Linux"}),"\n",(0,l.jsx)(n.p,{children:(0,l.jsx)(n.em,{children:"De Linux-installatie-instructies volgen nog."})})]})}function h(e={}){const{wrapper:n}={...(0,t.R)(),...e.components};return n?(0,l.jsx)(n,{...e,children:(0,l.jsx)(c,{...e})}):c(e)}}}]);