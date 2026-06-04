<?php

namespace OPNsense\AmneziaWG;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm        = $this->getForm('general');
        // Multi-instance: dialogInstance.xml feeds both the edit dialog and the grid columns
        $this->view->formDialogInstance = $this->getForm('dialogInstance');
        $this->view->formGridInstance   = $this->getFormGrid('dialogInstance', 'grid-instances');
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
