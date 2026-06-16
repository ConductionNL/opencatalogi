<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\IMetricsProvider.
 *
 * Mirrors the interface shipped by the OpenRegister AppHost observability
 * engine (ADR-040). Used only in environments where the openregister runtime
 * is not installed (e.g. bare CI containers). OpenCatalogiMetricsProvider
 * implements the real interface in production; this stub keeps the class
 * loadable when openregister is absent.
 *
 * This file is loaded by tests/bootstrap*.php when the real interface is absent.
 * It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

if (interface_exists(IMetricsProvider::class) === false) {
    /**
     * Stub interface for IMetricsProvider — used only in standalone unit tests.
     */
    interface IMetricsProvider
    {

        /**
         * Produce the provider's metric samples.
         *
         * @return \OCA\OpenRegister\AppHost\Observability\MetricSample[] The provider's samples.
         */
        public function metrics(): array;

    }//end interface
}//end if
