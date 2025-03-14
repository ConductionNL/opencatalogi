"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[851],{1562:(e,t,a)=>{a.r(t),a.d(t,{assets:()=>l,contentTitle:()=>r,default:()=>d,frontMatter:()=>s,metadata:()=>n,toc:()=>c});const n=JSON.parse('{"id":"handleidingen/Architectuur_en","title":"Architecture","description":"Open Catalogue provides a way to have multiple catalogues work together as one (virtual) catalogue, allowing users to search any or all of them at the same time. It does this by combining the DCAT standard with both JSON-LD and FSC to create an API that provides data from both single and multiple catalogues. Additionally, there are multiple front-end solutions that then use this API to provide a context-related search interface to end users (e.g., citizens, public officials, journalists, or researchers).","source":"@site/docs/handleidingen/Architectuur_en.md","sourceDirName":"handleidingen","slug":"/handleidingen/Architectuur_en","permalink":"/docs/handleidingen/Architectuur_en","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/opencatalogi/tree/main/website/docs/handleidingen/Architectuur_en.md","tags":[],"version":"current","frontMatter":{},"sidebar":"tutorialSidebar","previous":{"title":"Architectuur","permalink":"/docs/handleidingen/Architectuur"},"next":{"title":"Architectuur","permalink":"/docs/handleidingen/Architectuur_old"}}');var i=a(4848),o=a(8453);const s={},r="Architecture",l={},c=[{value:"Basic Setup",id:"basic-setup",level:2},{value:"Federated Search",id:"federated-search",level:2},{value:"Keeping It All Up to Date",id:"keeping-it-all-up-to-date",level:2},{value:"Under the Hood",id:"under-the-hood",level:2},{value:"Manual Publications and ZGW",id:"manual-publications-and-zgw",level:2},{value:"The Search API",id:"the-search-api",level:2},{value:"More About the Catalogue",id:"more-about-the-catalogue",level:2},{value:"About Publications",id:"about-publications",level:2},{value:"About Metadata",id:"about-metadata",level:2},{value:"Data Governance",id:"data-governance",level:2}];function h(e){const t={a:"a",code:"code",h1:"h1",h2:"h2",header:"header",img:"img",li:"li",p:"p",ul:"ul",...(0,o.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(t.header,{children:(0,i.jsx)(t.h1,{id:"architecture",children:"Architecture"})}),"\n",(0,i.jsxs)(t.p,{children:["Open Catalogue provides a way to have multiple catalogues work together as one (virtual) catalogue, allowing users to search any or all of them at the same time. It does this by combining the ",(0,i.jsx)(t.a,{href:"https://joinup.ec.europa.eu/collection/semic-support-centre/solution/dcat-application-profile-data-portals-europe/release/300",children:"DCAT"})," standard with both ",(0,i.jsx)(t.a,{href:"https://json-ld.org/",children:"JSON-LD"})," and ",(0,i.jsx)(t.a,{href:"https://docs.fsc.nlx.io/introduction",children:"FSC"})," to create an API that provides data from both single and multiple catalogues. Additionally, there are multiple front-end solutions that then use this API to provide a context-related search interface to end users (e.g., citizens, public officials, journalists, or researchers)."]}),"\n",(0,i.jsx)(t.h2,{id:"basic-setup",children:"Basic Setup"}),"\n",(0,i.jsxs)(t.p,{children:["The basic object of Open Catalogue is a catalogue. Each catalogue is a collection of publications. Publications represent 'something' that needs to be publicized. What that something is, is defined by a metadata description (defined by a ",(0,i.jsx)(t.a,{href:"https://json-schema.org/",children:"schema.json"}),"). Catalogues can contain publications from different types (e.g., datasets from the ",(0,i.jsx)(t.a,{href:"https://www.who.int/",children:"WHO"}),", requests from the ",(0,i.jsx)(t.a,{href:"https://www.rijksoverheid.nl/onderwerpen/wet-open-overheid-woo",children:"WOO"}),", or repositories of ",(0,i.jsx)(t.a,{href:"https://docs.italia.it/italia/developers-italia/publiccodeyml-en/en/master/index.html",children:"publiccode"}),"). Publications MUST belong to ONE catalogue, and each catalogue MUST belong to ONE organization, meaning that publications are traceable to organizations through their catalogue."]}),"\n",(0,i.jsx)(t.h2,{id:"federated-search",children:"Federated Search"}),"\n",(0,i.jsxs)(t.p,{children:["Each Open Catalogue installation provides a search endpoint that allows searching the catalogues belonging to that installation, allowing searching multiple catalogues at once. Each Open Catalogue installation also keeps track of other Open Catalogue installations and keeps a record of those in its ",(0,i.jsx)(t.code,{children:"directory"}),". This provides the basic constraints for the federated search."]}),"\n",(0,i.jsx)(t.p,{children:"When executing a federated search, an Open Catalogue instance will get all other Open Catalogue installations known to it from its directory, query those instances asynchronously, and aggregate the results."}),"\n",(0,i.jsx)(t.p,{children:'Performance-wise, we try to query as little as possible. For that, we apply the following "tricks":'}),"\n",(0,i.jsxs)(t.ul,{children:["\n",(0,i.jsx)(t.li,{children:"When searching for a specific type of metadata, we only query catalogues that are known to have it."}),"\n",(0,i.jsx)(t.li,{children:"We query Open Catalogue installations instead of catalogues."}),"\n"]}),"\n",(0,i.jsx)(t.h2,{id:"keeping-it-all-up-to-date",children:"Keeping It All Up to Date"}),"\n",(0,i.jsx)(t.p,{children:"When a new Open Catalogue installation is discovered, the discovering instance will make itself known to the discovered instance and take a notification subscription. Open Catalogue installations will notify other installations in their directory when:"}),"\n",(0,i.jsxs)(t.ul,{children:["\n",(0,i.jsx)(t.li,{children:"A catalogue is added, changed, or removed."}),"\n",(0,i.jsx)(t.li,{children:"A metadata description is added, changed, or removed."}),"\n",(0,i.jsx)(t.li,{children:"An entry in their directory is added, changed, or removed."}),"\n"]}),"\n",(0,i.jsx)(t.p,{children:"That means that a new installation only needs to make itself known to one other installation in order to snowball to all other installations. Directory updates are made unique by an event key to prevent circular notifications and overloading the network."}),"\n",(0,i.jsx)(t.p,{children:(0,i.jsx)(t.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/createnetwork.svg",alt:"Sequence Diagram network creation",title:"Sequence Diagram network creation"})}),"\n",(0,i.jsx)(t.h2,{id:"under-the-hood",children:"Under the Hood"}),"\n",(0,i.jsx)(t.p,{children:"Open Catalogue actually consists of a couple of technical components working together. For a start, it consists of several objects (Catalogi, Publications, Documents, and Index) which are stored in an object store (or ORC in VNG terms). Publications give a basic workflow management setup. When a publication is marked as published, it is then transferred to a search index (Elasticsearch). The Open Catalogue search endpoint then uses this search index to answer questions. This means that the user-oriented (public) frontend uses the search index (since it questions the search endpoint) and that the administration endpoint uses the object store."}),"\n",(0,i.jsxs)(t.p,{children:["Separate synchronization services can create publications from external sources (for example GitHub, or case handling systems). These publications are created in the object store and need to be marked as published before they are synchronized to the search index (and are made available under the search endpoint), though this process can be automated in configuration. This hard separation of data based on the role and context of requesters in a store and a search part prevents accidental disclosure of information. This is especially important because Open Catalogue is also used by ",(0,i.jsx)(t.a,{href:"https://openwoo.app/",children:"OpenWoo.app"}),"."]}),"\n",(0,i.jsx)(t.p,{children:"Normally speaking, documents (and files in general) aren't transferred to the object store, but obtained from the source when a single object is requested. You can however choose to transfer said object (per configuration) in order to prevent over asking the source application. This is especially convenient when dealing with older or less performant sources. Documents however are NEVER transferred to the search index in order to prevent indirect exposure. Documents can also be added to publications that have been manually created through the administration interface. Keep in mind though that these documents might still be required to be archived under archival law."}),"\n",(0,i.jsx)(t.p,{children:(0,i.jsx)(t.img,{src:"https://raw.githubusercontent.com/OpenCatalogi/.github/main/docs/handleidingen/components.svg",alt:"components",title:"components"})}),"\n",(0,i.jsx)(t.h2,{id:"manual-publications-and-zgw",children:"Manual Publications and ZGW"}),"\n",(0,i.jsx)(t.p,{children:"The admin UI does allow you to manually create publications, attach documents to them, and have a basic publication flow. If you want a more complex flow with several roles and actions, you might want to take a look into ZGW."}),"\n",(0,i.jsx)(t.h2,{id:"the-search-api",children:"The Search API"}),"\n",(0,i.jsx)(t.p,{children:"The main feature of Open Catalogue is its search API. This is provided in two forms: plain JSON and JSON-LD, and facilitates the federated search possibility."}),"\n",(0,i.jsxs)(t.p,{children:["Core Concepts and Guidelines:\nUsers should be guided/helped into finding the right information. The vast amount of data that is theoretically available on Open Catalogue makes this a challenge. To tackle this challenge, we incorporate ",(0,i.jsx)(t.a,{href:"https://www.oxfordsemantic.tech/faqs/what-is-faceted-search#:~:text=Faceted%20search%20is%20a%20method,that%20we%20are%20looking%20for.",children:"faceted search"}),". User interfaces SHOULD always include a dynamically created search interface using this faceted search. Search facets contain both search options and the expected results under those options, giving users a good indication on how to tweak their search. That also means that the facets should be updated during or after each search."]}),"\n",(0,i.jsx)(t.p,{children:"This is where performance comes into play. Search facets are (optionally) returned on the search API so both results and facets SHOULD be obtained in one call. You MAY however split it into two calls (getting results and getting facets) if you update the facets directly after or async to getting the result. This could give you a 200 to 400 ms performance boost. However, in this configuration, you MUST implement a loading state on the search interface until both calls are completed."}),"\n",(0,i.jsx)(t.p,{children:"When querying the search API, you SHOULD limit your search by either catalogues or metadata sets (e.g., WOO Verzoeken) or preferably both in order to prevent setting out too broad a search (and thereby over asking the API). It is preferred to have the user interface start small."}),"\n",(0,i.jsx)(t.h2,{id:"more-about-the-catalogue",children:"More About the Catalogue"}),"\n",(0,i.jsxs)(t.p,{children:["The Catalogue functions both as a ",(0,i.jsx)(t.a,{href:"https://semiceu.github.io/DCAT-AP/releases/3.0.0/#CataloguedResource",children:"DCAT Catalogue"})," and as an [FCS Inway]. The latter means that a Catalogue can belong to only ONE organization; catalogue ownership is verified through the use of a PKI certificate."]}),"\n",(0,i.jsx)(t.h2,{id:"about-publications",children:"About Publications"}),"\n",(0,i.jsxs)(t.p,{children:["The publication functions as a ",(0,i.jsx)(t.a,{href:"https://semiceu.github.io/DCAT-AP/releases/3.0.0/#CatalogueRecord",children:"DCAT Catalogue Record"}),". Originally designed as a holder for a ",(0,i.jsx)(t.a,{href:"https://docs.italia.it/italia/developers-italia/publiccodeyml-en/en/master/index.html",children:"publiccode.yaml"}),"."]}),"\n",(0,i.jsx)(t.h2,{id:"about-metadata",children:"About Metadata"}),"\n",(0,i.jsx)(t.p,{children:"A metadata file describes and defines the (meta)data stored in a publication. It does this by defining properties (e.g., name) and requirements for that property (e.g., minimal length). Metadata descriptions are used to validate publications on creation, add context to JSON-LD messages, and generate dynamic search interfaces."}),"\n",(0,i.jsx)(t.p,{children:"Traditionally, Open Catalogue focused on scraping publiccode files from GitHub and GitLab based on the publiccode.yaml standard, but recent years have seen the addition of WOO, Decat, and other standards. By default, the Open Catalogue object store supports the local development storage of metadata files. But metadata files can and SHOULD be separately hosted."}),"\n",(0,i.jsxs)(t.p,{children:["Keep in mind that metadata files are (in line with the VNG ORC standard) defined in ",(0,i.jsx)(t.a,{href:"https://json-schema.org/",children:"json-schema"})," which means that they are versioned within their file."]}),"\n",(0,i.jsx)(t.h2,{id:"data-governance",children:"Data Governance"}),"\n",(0,i.jsx)(t.p,{children:"When data is synchronized into the Open Catalogue object store, metadata is mapped (or generated) to the best of our abilities. There will however always be gaps. We are currently working on a dashboard to make these gaps visible in order for governance."})]})}function d(e={}){const{wrapper:t}={...(0,o.R)(),...e.components};return t?(0,i.jsx)(t,{...e,children:(0,i.jsx)(h,{...e})}):h(e)}},8453:(e,t,a)=>{a.d(t,{R:()=>s,x:()=>r});var n=a(6540);const i={},o=n.createContext(i);function s(e){const t=n.useContext(o);return n.useMemo((function(){return"function"==typeof e?e(t):{...t,...e}}),[t,e])}function r(e){let t;return t=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:s(e.components),n.createElement(o.Provider,{value:t},e.children)}}}]);