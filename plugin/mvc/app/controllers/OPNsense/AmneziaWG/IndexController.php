<?php

namespace OPNsense\AmneziaWG;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm        = $this->getForm('general');
        // Multi-instance: dialogInstance.xml feeds both the edit dialog and the grid columns.
        // NB: grid id must NOT contain a hyphen — mapDataToFormUI matches the dialog
        // form via id.split('-')[0], so 'grid-instances' silently broke Edit data load.
        $this->view->formDialogInstance = $this->getForm('dialogInstance');
        // command_width: 5 row buttons (start/stop/edit/copy/delete)
        $this->view->formGridInstance   = array_merge(
            $this->getFormGrid('dialogInstance'),
            ['command_width' => '160']
        );
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
