<?php

namespace Warext\AIContentInspector\Pub\Controller;

trait JsonResponder
{
    protected function asJson(array $params)
    {
        $this->setResponseType('json');

        $reply = $this->view('Warext\\AIContentInspector:Json', '', []);
        $reply->setJsonParams($params);

        return $reply;
    }
}
