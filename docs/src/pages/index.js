/**
 * OpenCatalogi landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/opencatalogi.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

const OPENCATALOGI_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M3 7l9-4 9 4-9 4-9-4z" />
    <path d="M3 12l9 4 9-4M3 17l9 4 9-4" />
  </svg>
);

const TAGLINE = (
  <>
    The public-facing software, dataset, and API catalogue. Drops into your{' '}
    <span className="next-blue">Nextcloud</span>, surfaces every register as a
    searchable entry, federates to data.overheid.nl out of the box.
  </>
);

function RecentPublicationsPanel() {
  const tones = [
    'var(--c-blue-cobalt)',
    'var(--c-forest-500)',
    'var(--c-lavender-500)',
    'var(--c-terracotta-500)',
    'var(--c-mint-500)',
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {tones.map((tone, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < tones.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              height: 4,
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
            }}
          />
          <span
            style={{
              width: 8,
              height: 9,
              clipPath: 'var(--hex-pointy-top)',
              background: i < 3 ? 'var(--c-mint-500)' : 'var(--c-cobalt-200)',
            }}
          />
        </div>
      ))}
    </div>
  );
}

function CatalogueStatsPanel() {
  const kpis = [
    { n: '3.4k', l: 'records', tone: 'var(--c-cobalt-900)' },
    { n: '12', l: 'feeders', tone: 'var(--c-mint-500)' },
    { n: '847', l: 'queries', tone: 'var(--c-orange-knvb)' },
  ];
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(3, 1fr)',
        gap: 6,
      }}
    >
      {kpis.map((kpi, i) => (
        <div
          key={i}
          style={{
            padding: '8px 6px',
            background: 'var(--c-cobalt-50)',
            borderRadius: 'var(--radius-sm)',
            display: 'flex',
            flexDirection: 'column',
            gap: 2,
          }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 18,
              fontWeight: 700,
              color: kpi.tone,
            }}
          >
            {kpi.n}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              letterSpacing: '0.06em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-400)',
            }}
          >
            {kpi.l}
          </div>
        </div>
      ))}
    </div>
  );
}

function FederationStatusPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', label: 'data.overheid.nl' },
    { tone: 'var(--c-mint-500)', label: 'gemeente Tilburg' },
    { tone: 'var(--c-orange-knvb)', label: 'gemeente Almere' },
    { tone: 'var(--c-mint-500)', label: 'Forum Standaardisatie' },
    { tone: 'var(--c-red-vermillion)', label: 'gemeente Hoorn' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 8,
              height: 9,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 11,
              color: 'var(--c-cobalt-700)',
              flex: 1,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              height: 3,
              width: 26,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Recent publications',
    desc: 'Last items added to the public catalogue. Category hex, title, federation status.',
    panel: <RecentPublicationsPanel />,
  },
  {
    title: 'Catalogue stats',
    desc: 'Three KPIs at a glance: total publications, federated downstream, queries this week.',
    panel: <CatalogueStatsPanel />,
  },
  {
    title: 'Federation status',
    desc: 'Who is consuming your catalogue, who is publishing back. Mint = healthy, orange = retry, red = down.',
    panel: <FederationStatusPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="OpenCatalogi, open-source component catalog for Nextcloud"
      description="Public software catalog for Nextcloud. Federated component register with schema validation, REST and GraphQL APIs, and Common Ground alignment."
    >
      <main className="marketing-page">
        <DetailHero
          appId="opencatalogi"
          background="cobalt"
          locales="NL · EN"
          title="OpenCatalogi"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/opencatalogi',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://codeberg.org/Conduction/opencatalogi',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={OPENCATALOGI_ICON}
          illustration={<AppMock app="opencatalogi" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="On every Nextcloud dashboard."
          lede="Install OpenCatalogi and the home screen tracks what got published, what was searched, and what other gemeenten are federating from you."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
