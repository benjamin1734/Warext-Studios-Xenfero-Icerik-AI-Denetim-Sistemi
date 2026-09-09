<?php

namespace Warext\AIContentInspector\Admin\Controller;

use XF\Admin\Controller\AbstractController;

class HighRisk extends AbstractController
{
    public function actionIndex()
    {
        $url = \XF::app()->router('public')->buildLink('warext-ai', null, [
            'risk_min' => 70
        ]);

        return $this->redirect($url);
    }
}
