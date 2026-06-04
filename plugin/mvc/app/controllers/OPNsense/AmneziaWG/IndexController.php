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
        $this->view->formGridInstance   = $this->getFormGrid('dialogInstance');
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
