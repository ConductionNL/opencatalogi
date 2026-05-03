<?php
/**
 * OpenCatalogi Tool Registration Listener.
 *
 * Listens to ToolRegistrationEvent and registers OpenCatalogi's tools.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenCatalogi\Tool\CMSTool;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Tool Registration Listener.
 *
 * Registers OpenCatalogi's tools when the ToolRegistrationEvent is dispatched.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @template-implements IEventListener<Event>
 */
class ToolRegistrationListener implements IEventListener
{

    /**
     * CMS tool.
     *
     * @var CMSTool
     */
    private CMSTool $cmsTool;

    /**
     * Constructor.
     *
     * @param CMSTool $cmsTool CMS tool.
     */
    public function __construct(CMSTool $cmsTool)
    {
        $this->cmsTool = $cmsTool;

    }//end __construct()

    /**
     * Handle the event.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ToolRegistrationEvent) === false) {
            return;
        }

        // Register OpenCatalogi CMS tool.
        $event->registerTool(
            id: 'opencatalogi.cms',
            tool: $this->cmsTool,
            metadata: [
                'name'        => $this->cmsTool->getName(),
                'description' => $this->cmsTool->getDescription(),
                'icon'        => 'icon-category-office',
                'app'         => 'opencatalogi',
            ]
        );

    }//end handle()
}//end class
