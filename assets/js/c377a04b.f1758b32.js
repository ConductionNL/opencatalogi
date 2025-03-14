"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[3361],{28453:(e,n,i)=>{i.d(n,{R:()=>r,x:()=>a});var s=i(96540);const t={},o=s.createContext(t);function r(e){const n=s.useContext(o);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function a(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(t):e.components||t:r(e.components),s.createElement(o.Provider,{value:n},e.children)}},62502:(e,n,i)=>{i.r(n),i.d(n,{assets:()=>l,contentTitle:()=>a,default:()=>h,frontMatter:()=>r,metadata:()=>s,toc:()=>c});const s=JSON.parse('{"id":"index","title":"Open Catalogi Documentation","description":"Comprehensive documentation for Open Catalogi - an open source solution for creating, managing, and sharing catalogs of government software, services, and data","source":"@site/docs/index.md","sourceDirName":".","slug":"/","permalink":"/docs/","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/opencatalogi/tree/main/website/docs/index.md","tags":[],"version":"current","sidebarPosition":1,"frontMatter":{"sidebar_position":1,"title":"Open Catalogi Documentation","description":"Comprehensive documentation for Open Catalogi - an open source solution for creating, managing, and sharing catalogs of government software, services, and data"},"sidebar":"tutorialSidebar","previous":{"title":"Zoeken in de beheerinterface","permalink":"/docs/Users/zoeken"},"next":{"title":"Beheerders","permalink":"/docs/Administrator/"}}');var t=i(74848),o=i(28453);const r={sidebar_position:1,title:"Open Catalogi Documentation",description:"Comprehensive documentation for Open Catalogi - an open source solution for creating, managing, and sharing catalogs of government software, services, and data"},a="Open Catalogi",l={},c=[{value:"What is Open Catalogi?",id:"what-is-open-catalogi",level:2},{value:"System Architecture",id:"system-architecture",level:2},{value:"Component Descriptions",id:"component-descriptions",level:3},{value:"Manuals",id:"manuals",level:2},{value:"Developers",id:"developers",level:3},{value:"(Functional) Administrators",id:"functional-administrators",level:3},{value:"Users",id:"users",level:3},{value:"Key Features",id:"key-features",level:2},{value:"Benefits of Open Catalogi",id:"benefits-of-open-catalogi",level:2},{value:"For Government Organizations",id:"for-government-organizations",level:3},{value:"For Citizens and Businesses",id:"for-citizens-and-businesses",level:3},{value:"For Developers",id:"for-developers",level:3},{value:"Project History",id:"project-history",level:2},{value:"Key Milestones",id:"key-milestones",level:3},{value:"Q1 2024: Nextcloud Integration",id:"q1-2024-nextcloud-integration",level:4},{value:"Q2 2024: New Search API",id:"q2-2024-new-search-api",level:4},{value:"Q4 2024: Improved Document Handling",id:"q4-2024-improved-document-handling",level:4},{value:"Q1 2025: New Dashboarding (Planned)",id:"q1-2025-new-dashboarding-planned",level:4},{value:"Getting Started",id:"getting-started",level:2},{value:"For Organizations",id:"for-organizations",level:3},{value:"For Developers",id:"for-developers-1",level:3}];function d(e){const n={a:"a",code:"code",h1:"h1",h2:"h2",h3:"h3",h4:"h4",header:"header",li:"li",mermaid:"mermaid",ol:"ol",p:"p",pre:"pre",strong:"strong",ul:"ul",...(0,o.R)(),...e.components};return(0,t.jsxs)(t.Fragment,{children:[(0,t.jsx)(n.header,{children:(0,t.jsx)(n.h1,{id:"open-catalogi",children:"Open Catalogi"})}),"\n",(0,t.jsx)(n.h2,{id:"what-is-open-catalogi",children:"What is Open Catalogi?"}),"\n",(0,t.jsx)(n.p,{children:"Open Catalogi is an open source solution designed to help government organizations create, manage, and share catalogs of their software, services, and data. It enables transparent publication of public sector information, making it easier for citizens, businesses, and other government entities to discover and utilize available resources."}),"\n",(0,t.jsx)(n.p,{children:"The platform follows open standards and principles, supporting the goals of digital transparency, interoperability, and reusability in government. Open Catalogi helps organizations comply with regulations like the Public Access to Government Information Act (WOO) while providing a user-friendly interface for both publishers and consumers of information."}),"\n",(0,t.jsx)(n.h2,{id:"system-architecture",children:"System Architecture"}),"\n",(0,t.jsx)(n.p,{children:"Open Catalogi consists of several integrated components that work together to provide a complete catalog management solution:"}),"\n",(0,t.jsx)(n.mermaid,{value:"graph TB\n    subgraph Public_Frontend\n        UI[User Interface]\n    end\n\n    subgraph Nextcloud_Apps\n        OC[Open Catalogi App]\n        OR[Open Register App] \n        OCN[Open Connector App]\n    end\n\n    subgraph External_Sources\n        GH[GitHub Repositories]\n        GL[GitLab Repositories]\n        API[External APIs]\n    end\n\n    UI --\x3e OC\n    OC --\x3e OR\n    OR --\x3e OC\n    OCN --\x3e OR\n    GH --\x3e OCN\n    GL --\x3e OCN\n    API --\x3e OCN"}),"\n",(0,t.jsx)(n.h3,{id:"component-descriptions",children:"Component Descriptions"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsx)(n.p,{children:(0,t.jsx)(n.strong,{children:"Public Frontend"})}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"User Interface"}),": The web interface that users interact with to browse and search catalogs"]}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsx)(n.p,{children:(0,t.jsx)(n.strong,{children:"Nextcloud Apps"})}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Open Catalogi App"}),": Manages the presentation and organization of catalog items"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Open Register App"}),": Core data storage and management system for catalog items"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Open Connector App"}),": Integrates with external data sources to import catalog items"]}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsx)(n.p,{children:(0,t.jsx)(n.strong,{children:"External Sources"})}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"GitHub/GitLab Repositories"}),": Source code repositories that can be indexed"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"External APIs"}),": Other data sources that can be connected to enrich the catalog"]}),"\n"]}),"\n"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"manuals",children:"Manuals"}),"\n",(0,t.jsx)(n.p,{children:"Open Catalogi provides manuals for three different target groups:"}),"\n",(0,t.jsx)(n.h3,{id:"developers",children:"Developers"}),"\n",(0,t.jsx)(n.p,{children:"Manuals for developers who want to further develop Open Catalogi. This documentation contains technical information about the architecture, APIs, and how to contribute to the codebase."}),"\n",(0,t.jsx)(n.h3,{id:"functional-administrators",children:"(Functional) Administrators"}),"\n",(0,t.jsx)(n.p,{children:"Manuals for administrators who want to configure and set up Open Catalogi. This documentation contains information about managing catalogs, metadata, organizations and directories."}),"\n",(0,t.jsx)(n.h3,{id:"users",children:"Users"}),"\n",(0,t.jsx)(n.p,{children:"Manuals for end users who use Open Catalogi daily for example:"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"\ud83d\udcdd Publishing WOO requests"}),"\n",(0,t.jsx)(n.li,{children:"\ud83d\udccb Making permits available for inspection"}),"\n",(0,t.jsx)(n.li,{children:"\ud83d\uddc2\ufe0f Managing a complete catalog for software for example"}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"key-features",children:"Key Features"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcda ",(0,t.jsx)(n.strong,{children:"Component Catalog"}),": Create and maintain a comprehensive catalog of software components, APIs, and services"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcc4 ",(0,t.jsx)(n.strong,{children:"PublicCode.yml Support"}),": Automatic extraction and validation of PublicCode.yml metadata from repositories"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83c\udfe2 ",(0,t.jsx)(n.strong,{children:"Organization Profiles"}),": Showcase organizations and their digital offerings in a standardized format"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd04 ",(0,t.jsx)(n.strong,{children:"GitHub/GitLab Integration"}),": Automatically index and catalog repositories from GitHub and GitLab organizations"]}),"\n",(0,t.jsxs)(n.li,{children:["\u2601\ufe0f ",(0,t.jsx)(n.strong,{children:"Nextcloud Integration"}),": Seamless integration with Nextcloud for secure storage and access control"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83c\udf10 ",(0,t.jsx)(n.strong,{children:"Open Standards"}),": Built on open standards like PublicCode.yml, EUPL, and Common Ground principles"]}),"\n",(0,t.jsxs)(n.li,{children:["\u2705 ",(0,t.jsx)(n.strong,{children:"Compliance Support"}),": Help organizations comply with transparency requirements (WOO) and reuse policies"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd17 ",(0,t.jsx)(n.strong,{children:"Component Relationships"}),": Visualize dependencies and relationships between components"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83c\udf0d ",(0,t.jsx)(n.strong,{children:"Multilingual Support"}),": Interface and content available in multiple languages"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcf1 ",(0,t.jsx)(n.strong,{children:"Responsive Design"}),": User-friendly interface that works on desktop and mobile devices"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd0d ",(0,t.jsx)(n.strong,{children:"Faceted Search"}),": Advanced search capabilities with filtering by various metadata fields"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd0c ",(0,t.jsx)(n.strong,{children:"API Access"}),": RESTful API for programmatic access to catalog data"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"benefits-of-open-catalogi",children:"Benefits of Open Catalogi"}),"\n",(0,t.jsx)(n.h3,{id:"for-government-organizations",children:"For Government Organizations"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["\ud83c\udfdb\ufe0f ",(0,t.jsx)(n.strong,{children:"Transparency"}),": Easily publish information about your digital services and software"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcb0 ",(0,t.jsx)(n.strong,{children:"Cost Efficiency"}),": Reduce duplication by discovering existing solutions"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83e\udd1d ",(0,t.jsx)(n.strong,{children:"Collaboration"}),": Find partners working on similar challenges"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcca ",(0,t.jsx)(n.strong,{children:"Oversight"}),": Maintain a clear overview of your digital portfolio"]}),"\n",(0,t.jsxs)(n.li,{children:["\u2696\ufe0f ",(0,t.jsx)(n.strong,{children:"Compliance"}),": Meet legal requirements for transparency and reuse"]}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"for-citizens-and-businesses",children:"For Citizens and Businesses"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd0e ",(0,t.jsx)(n.strong,{children:"Discoverability"}),": Find government services and information more easily"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcf1 ",(0,t.jsx)(n.strong,{children:"Accessibility"}),": Access government resources through a user-friendly interface"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83e\udde9 ",(0,t.jsx)(n.strong,{children:"Integration"}),": Build upon existing government components for new solutions"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcc8 ",(0,t.jsx)(n.strong,{children:"Innovation"}),": Identify opportunities for improvement and innovation"]}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"for-developers",children:"For Developers"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["\ud83e\uddf0 ",(0,t.jsx)(n.strong,{children:"Reusable Components"}),": Find and reuse existing government software components"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udcda ",(0,t.jsx)(n.strong,{children:"Documentation"}),": Access comprehensive documentation for government APIs and services"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83d\udd27 ",(0,t.jsx)(n.strong,{children:"Contribution"}),": Easily contribute improvements to government software"]}),"\n",(0,t.jsxs)(n.li,{children:["\ud83c\udf31 ",(0,t.jsx)(n.strong,{children:"Open Source"}),": Benefit from and participate in open source government projects"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"project-history",children:"Project History"}),"\n",(0,t.jsx)(n.p,{children:"Open Catalogi has evolved significantly since its inception, with continuous improvements and new features being added to better serve the needs of government organizations, citizens, and developers."}),"\n",(0,t.jsx)(n.mermaid,{value:"timeline\n    title Open Catalogi Development Timeline\n    section 2024\n        Q1 : Nextcloud Integration\n            : Migration to Nextcloud platform\n            : Enhanced security and access control\n        Q2 : New Search API\n            : Improved search capabilities\n            : Advanced filtering options\n            : Performance optimizations\n        Q4 : Improved Document Handling\n            : Support for more document formats\n            : Automatic metadata extraction\n            : Version control for documents\n    section 2025\n        Q1 : New Dashboarding\n            : Customizable dashboards\n            : Advanced analytics\n            : Visual reporting tools"}),"\n",(0,t.jsx)(n.h3,{id:"key-milestones",children:"Key Milestones"}),"\n",(0,t.jsx)(n.h4,{id:"q1-2024-nextcloud-integration",children:"Q1 2024: Nextcloud Integration"}),"\n",(0,t.jsx)(n.p,{children:"The integration with Nextcloud marked a significant advancement for Open Catalogi, providing a robust foundation for secure storage, access control, and collaboration features. This migration enhanced the platform's security posture and enabled seamless integration with existing Nextcloud deployments in government organizations."}),"\n",(0,t.jsx)(n.h4,{id:"q2-2024-new-search-api",children:"Q2 2024: New Search API"}),"\n",(0,t.jsx)(n.p,{children:"The introduction of a new Search API dramatically improved the discoverability of catalog items. With enhanced search capabilities, advanced filtering options, and significant performance optimizations, users can now find relevant resources more quickly and accurately."}),"\n",(0,t.jsx)(n.h4,{id:"q4-2024-improved-document-handling",children:"Q4 2024: Improved Document Handling"}),"\n",(0,t.jsx)(n.p,{children:"Document handling capabilities were substantially enhanced with support for additional formats, automatic metadata extraction, and version control. These improvements streamlined the process of publishing and managing documents within catalogs."}),"\n",(0,t.jsx)(n.h4,{id:"q1-2025-new-dashboarding-planned",children:"Q1 2025: New Dashboarding (Planned)"}),"\n",(0,t.jsx)(n.p,{children:"The upcoming dashboarding features will provide users with customizable views, advanced analytics, and visual reporting tools. These enhancements will enable better insights into catalog usage and content, supporting data-driven decision making."}),"\n",(0,t.jsx)(n.h2,{id:"getting-started",children:"Getting Started"}),"\n",(0,t.jsx)(n.p,{children:"Ready to start using Open Catalogi? Follow these steps to get up and running quickly:"}),"\n",(0,t.jsx)(n.h3,{id:"for-organizations",children:"For Organizations"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsxs)(n.p,{children:[(0,t.jsx)(n.strong,{children:"Installation"}),":"]}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Set up Nextcloud on your server"}),"\n",(0,t.jsx)(n.li,{children:"Install the Open Catalogi, Open Register, and Open Connector apps"}),"\n",(0,t.jsx)(n.li,{children:"Configure your organization profile"}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsxs)(n.p,{children:[(0,t.jsx)(n.strong,{children:"Connect Your Repositories"}),":"]}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Link your GitHub or GitLab organization"}),"\n",(0,t.jsx)(n.li,{children:"Configure webhooks for automatic updates"}),"\n",(0,t.jsx)(n.li,{children:"Set up metadata extraction rules"}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsxs)(n.p,{children:[(0,t.jsx)(n.strong,{children:"Customize Your Catalog"}),":"]}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Define your catalog structure"}),"\n",(0,t.jsx)(n.li,{children:"Set up access permissions"}),"\n",(0,t.jsx)(n.li,{children:"Configure branding and appearance"}),"\n"]}),"\n"]}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"for-developers-1",children:"For Developers"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsxs)(n.p,{children:[(0,t.jsx)(n.strong,{children:"Local Development Environment"}),":"]}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["Clone the repositories:","\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-bash",children:"git clone https://github.com/OpenCatalogi/opencatalogi-app.git\ngit clone https://github.com/OpenCatalogi/openregister-app.git\ngit clone https://github.com/OpenCatalogi/openconnector-app.git\n"})}),"\n"]}),"\n",(0,t.jsx)(n.li,{children:"Set up Nextcloud development environment"}),"\n",(0,t.jsx)(n.li,{children:"Install dependencies and build the apps"}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.li,{children:["\n",(0,t.jsxs)(n.p,{children:[(0,t.jsx)(n.strong,{children:"API Integration"}),":"]}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Review the API documentation"}),"\n",(0,t.jsx)(n.li,{children:"Generate API keys"}),"\n",(0,t.jsx)(n.li,{children:"Test your integration"}),"\n"]}),"\n"]}),"\n"]}),"\n",(0,t.jsxs)(n.p,{children:["For detailed instructions, see the ",(0,t.jsx)(n.a,{href:"/docs/Installation",children:"Installation Guide"})," and ",(0,t.jsx)(n.a,{href:"/docs/Developers",children:"Developer Guide"}),"."]})]})}function h(e={}){const{wrapper:n}={...(0,o.R)(),...e.components};return n?(0,t.jsx)(n,{...e,children:(0,t.jsx)(d,{...e})}):d(e)}}}]);