<?php

namespace Warext\AIContentInspector\Pub\Controller;

use Warext\AIContentInspector\Service\InteropGateway;
use XF\Pub\Controller\AbstractController;

class Interop extends AbstractController
{
    use JsonResponder;

    public function actionIndex()
    {
        if (!\XF::visitor()->user_id)
        {
            return $this->noPermission();
        }

        return $this->asJson([
            'success' => true,
            'capabilities' => (new InteropGateway())->capabilities()
        ]);
    }

    public function actionAnalyze()
    {
        if (!\XF::visitor()->user_id)
        {
            return $this->noPermission();
        }

        $this->assertPostOnly();

        $message = (string)$this->filter('message', 'str');
        $mode = (string)$this->filter('mode', 'str');
        if (!in_array($mode, ['ai', 'hybrid'], true)) $mode = 'ai';

        $localRaw = (string)$this->filter('local_context', 'str');
        $local = [];
        if ($localRaw !== '')
        {
            $decoded = json_decode($localRaw, true);
            if (is_array($decoded)) $local = $decoded;
        }

        $result = (new InteropGateway())->analyze($message, $local, [
            'writing_mode' => $mode,
            'user_id' => (int)\XF::visitor()->user_id
        ]);

        return $this->asJson([
            'success' => !empty($result['available']),
            'result' => $result
        ]);
    }
}
