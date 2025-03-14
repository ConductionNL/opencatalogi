"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[8432],{8136:(e,n,i)=>{i.r(n),i.d(n,{assets:()=>g,contentTitle:()=>l,default:()=>c,frontMatter:()=>r,metadata:()=>a,toc:()=>s});const a=JSON.parse('{"id":"installatie/logging","title":"Audit en logging","description":"Voor logging maken we gebruik van de ingebouwde admin\\\\audit systematiek van next cloud, meer daarover kan je hier vinden.","source":"@site/docs/installatie/logging.md","sourceDirName":"installatie","slug":"/installatie/logging","permalink":"/docs/installatie/logging","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/opencatalogi/tree/main/website/docs/installatie/logging.md","tags":[],"version":"current","frontMatter":{},"sidebar":"tutorialSidebar","previous":{"title":"Installatie-instructies Nextcloud","permalink":"/docs/installatie/instructies"},"next":{"title":"On-Prem server","permalink":"/docs/installatie/on-prem-server"}}');var t=i(4848),o=i(8453);const r={},l="Audit en logging",g={},s=[{value:"System logging",id:"system-logging",level:2},{value:"Change logging",id:"change-logging",level:2},{value:"Security logging",id:"security-logging",level:2},{value:"Via Loki, Prometheus en Grafana",id:"via-loki-prometheus-en-grafana",level:2}];function d(e){const n={a:"a",h1:"h1",h2:"h2",header:"header",img:"img",li:"li",ol:"ol",p:"p",...(0,o.R)(),...e.components};return(0,t.jsxs)(t.Fragment,{children:[(0,t.jsx)(n.header,{children:(0,t.jsx)(n.h1,{id:"audit-en-logging",children:"Audit en logging"})}),"\n",(0,t.jsxs)(n.p,{children:["Voor logging maken we gebruik van de ingebouwde admin_audit systematiek van next cloud, meer daarover kan je ",(0,t.jsx)(n.a,{href:"https://docs.nextcloud.com/server/29/admin_manual/configuration_server/logging_configuration.html#admin-audit-log",children:"hier"})," vinden."]}),"\n",(0,t.jsx)(n.h2,{id:"system-logging",children:"System logging"}),"\n",(0,t.jsxs)(n.p,{children:["Als audit trails aanstaan worden automatisch alle systeemfouten gelogd, die kunnen vervolgens worden ingezien met ",(0,t.jsx)(n.a,{href:"https://github.com/nextcloud/logreader",children:"log reader"})," (admin->logging)"]}),"\n",(0,t.jsx)(n.p,{children:(0,t.jsx)(n.img,{alt:"alt text",src:i(8600).A+"",width:"1196",height:"710"})}),"\n",(0,t.jsx)(n.h2,{id:"change-logging",children:"Change logging"}),"\n",(0,t.jsx)(n.p,{children:"Wijzigings- (pogingen) worden gelogd via API calls. Dit vanwege twee reden:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsx)(n.li,{children:"Zo loggen we alle pogingen, ongeacht of ze via de functioneel beheer omgeving of een specifieke afhandel applicatie zijn gemaakt"}),"\n",(0,t.jsx)(n.li,{children:"We loggen pogingen, dus ook mislukte wijzigingen (bijvoorbeeld vanwege foutieve invoer of rechten) worden bijgehouden"}),"\n"]}),"\n",(0,t.jsxs)(n.p,{children:["Deze logs zijn generiek in te zien via ",(0,t.jsx)(n.a,{href:"https://github.com/nextcloud/logreader",children:"log reader"})," of specifiek via de functioneel beheer interface."]}),"\n",(0,t.jsx)(n.h2,{id:"security-logging",children:"Security logging"}),"\n",(0,t.jsx)(n.p,{children:"Foutieve inlogpogingen, overmatige bevragingen, ongeldige invoer etc. worden allemaal weggeschreven naar de logs zijn daarin dus terug te vinden via log reader of te exporteren naar een dashboard dat meerdere installaties volgt."}),"\n",(0,t.jsx)(n.h2,{id:"via-loki-prometheus-en-grafana",children:"Via Loki, Prometheus en Grafana"}),"\n",(0,t.jsxs)(n.p,{children:["We raden sterk aan om op SaaS-omgevingen gebruik te maken van dashboard om (verdacht) gedrag van gebruiker te volgen naar de algemene gezondheid van de installatie. Dit is zeker raadzaam binnen S",(0,t.jsx)(n.a,{href:"/docs/installatie/saas",children:"aaS-omgevingen"})," waarbij er doorgaan gebruik wordt gemaakt van \xe9\xe9n installatie per tenant (klant). Overzicht houden wordt dan snel moeilijk tot onmogelijk en op performance en security wil je proactief acteren."]}),"\n",(0,t.jsxs)(n.p,{children:["Vanuit de Nextcloud-community is er een mooie ",(0,t.jsx)(n.a,{href:"https://okxo.de/monitor-your-nextcloud-logs-for-suspicious-activities/",children:"tutorial"})," beschikbaar over hoe je de Nextcloud-audit trails kan overbrengen naar je Grafana-dashboard zodat je zicht hebt op (bijvoorbeeld) mislukte inlog pogingen."]})]})}function c(e={}){const{wrapper:n}={...(0,o.R)(),...e.components};return n?(0,t.jsx)(n,{...e,children:(0,t.jsx)(d,{...e})}):d(e)}},8453:(e,n,i)=>{i.d(n,{R:()=>r,x:()=>l});var a=i(6540);const t={},o=a.createContext(t);function r(e){const n=a.useContext(o);return a.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function l(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(t):e.components||t:r(e.components),a.createElement(o.Provider,{value:n},e.children)}},8600:(e,n,i)=>{i.d(n,{A:()=>a});const a=i.p+"assets/images/image-b0d97c54eb336eb8b74e00cffde9be58.png"}}]);