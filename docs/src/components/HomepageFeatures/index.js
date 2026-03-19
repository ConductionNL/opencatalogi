import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Publication Management',
    description: (
      <>
        Create, manage, and publish documents with full metadata, attachments, and WOO compliance. Support for multiple publication types and statuses.
      </>
    ),
  },
  {
    title: 'Federated Catalogs',
    description: (
      <>
        Synchronize catalogs across organizations in a federated directory. Discover and search publications from the entire network.
      </>
    ),
  },
  {
    title: 'Open Data & WOO',
    description: (
      <>
        Built for Dutch open data standards and WOO transparency requirements. DCAT-AP compliant metadata and public search frontend.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}