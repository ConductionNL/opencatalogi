// @ts-check

/**
 * OpenCatalogi documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, KvK / BTW copyright). Site-specific
 * overrides — locales, sidebar path, mermaid theme, redocusaurus, custom
 * navbar items — are passed through createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'Open Catalogi',
  tagline: 'Open source catalog management for government organizations',
  url: 'https://opencatalogi.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'opencatalogi',
  trailingSlash: false,

  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'nl'],
    localeConfigs: {
      en: { label: 'English' },
      nl: { label: 'Nederlands' },
    },
  },

  /* The opencatalogi docs source lives at the repo root of `docs/`
     rather than under a `docs/` subfolder, so we override the preset's
     default `presets:` block to point `docs.path` at './' and disable
     the blog plugin. customCss carries opencatalogi-specific CSS only —
     brand tokens and the theme swizzles are auto-loaded by the brand
     theme entry in `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. Exclude src/ (pages live there)
             plus the standard node_modules bucket. */
          exclude: ['**/node_modules/**', 'src/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://codeberg.org/Conduction/opencatalogi/src/branch/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
    [
      'redocusaurus',
      {
        specs: [
          {
            spec: 'static/oas/open-catalogi.yaml',
            route: '/api',
          },
        ],
        theme: {
          primaryColor: '#1890ff',
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with opencatalogi's own (Documentation sidebar
     link, API docs, GitHub link, locale dropdown). */
  navbar: {
    title: 'Open Catalogi',
    logo: {
      alt: 'Open Catalogi Logo',
      src: 'img/logo.svg',
    },
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: '/api',
        label: 'API Documentation',
        position: 'right',
      },
      {
        href: 'https://codeberg.org/Conduction/opencatalogi',
        label: 'Codeberg',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. Single Conduction column;
     no Sendent partner column on this product page. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer's boat-sinking + kade-cyclist mini-games
     on this product-page footer (preset 1.3.0+). The static skyline +
     canal decoration are kept; the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    image: 'img/og-opencatalogi.png',
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown + onBroken* directly so they make it into the final
   Docusaurus config. */
config.onBrokenLinks = 'warn';
config.onBrokenMarkdownLinks = 'warn';
config.onBrokenAnchors = 'warn';
config.markdown = {
  mermaid: true,
};

module.exports = config;
