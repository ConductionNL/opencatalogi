<?php
/**
 * Stub for OCA\OpenRegister\Service\DeckCardService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for DeckCardService used by PHPUnit mocks in bare CI.
 */
class DeckCardService
{

    /**
     * Check if Deck is available.
     *
     * @return boolean
     */
    public function isDeckAvailable(): bool
    {
        return false;

    }//end isDeckAvailable()


    /**
     * Get cards for an object.
     *
     * @param string $objectUuid Object UUID.
     *
     * @return array<mixed>
     */
    public function getCardsForObject(string $objectUuid): array
    {
        return [];

    }//end getCardsForObject()


}//end class
