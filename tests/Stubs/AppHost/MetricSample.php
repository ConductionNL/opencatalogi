<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Observability\MetricSample.
 *
 * Mirrors the value object shipped by the OpenRegister AppHost observability
 * engine (ADR-040). Used only in environments where the openregister runtime
 * is not installed (e.g. bare CI containers).
 *
 * This file is loaded by tests/bootstrap*.php when the real class is absent.
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

namespace OCA\OpenRegister\AppHost\Observability;

if (class_exists(MetricSample::class) === false) {
    /**
     * Stub value object for MetricSample — used only in standalone unit tests.
     */
    final class MetricSample
    {

        /**
         * Constructor.
         *
         * @param string                                                            $name    Metric name.
         * @param string                                                            $type    Prometheus type.
         * @param string                                                            $help    HELP text.
         * @param array<int, array{labels: array<string,string>, value: float|int}> $samples Labelled samples.
         */
        public function __construct(
            public readonly string $name,
            public readonly string $type,
            public readonly string $help,
            public readonly array $samples
        ) {

        }//end __construct()


        /**
         * Convenience factory for a single unlabelled sample.
         *
         * @param string                $name   Metric name.
         * @param string                $type   Prometheus type.
         * @param string                $help   HELP text.
         * @param float|int             $value  Sample value.
         * @param array<string, string> $labels Optional labels.
         *
         * @return self
         */
        public static function single(string $name, string $type, string $help, float|int $value, array $labels=[]): self
        {
            return new self(
                name: $name,
                type: $type,
                help: $help,
                samples: [['labels' => $labels, 'value' => $value]]
            );

        }//end single()


    }//end class
}//end if
