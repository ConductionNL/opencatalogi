<?php

namespace OCA\OpenCatalogi\Cron;

use OCP\WorkflowEngine\IEntity;

/**
 * DOCS: https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/flow.html#entities
 */
class AttachmentEvent implements IEntity
{
    
    public function getName(): string
    {
        return $this->l10n-t('Attachment');
    }

    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath('opencatalogi', 'app.svg');
    }

    public function getEvents(): string
    {
        return '';
    }

    /**
     * will check whether the passed user is allowed to see the current event 
     */
    public function isLegitimatedForUserId(): bool
    {
        return true;
    }


}