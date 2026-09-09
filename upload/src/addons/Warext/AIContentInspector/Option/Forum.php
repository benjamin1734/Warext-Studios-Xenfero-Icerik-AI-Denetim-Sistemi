<?php

namespace Warext\AIContentInspector\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;
use XF\Repository\NodeRepository;

class Forum extends AbstractOption
{
    public static function renderCheckboxList(Option $option, array $htmlParams)
    {
        /** @var NodeRepository $nodeRepo */
        $nodeRepo = \XF::repository(NodeRepository::class);
        $nodes = $nodeRepo->getFullNodeList();

        $byId = [];
        foreach ($nodes as $node)
        {
            $byId[(int)$node->node_id] = $node;
        }

        $choices = [];
        foreach ($nodes as $node)
        {
            if ((string)$node->node_type_id !== 'Forum')
            {
                continue;
            }

            $path = [];
            $parentId = (int)$node->parent_node_id;
            $guard = 0;
            while ($parentId > 0 && isset($byId[$parentId]) && $guard < 20)
            {
                $parent = $byId[$parentId];
                array_unshift($path, (string)$parent->title);
                $parentId = (int)$parent->parent_node_id;
                $guard++;
            }

            $path[] = (string)$node->title;
            $choices[(int)$node->node_id] = implode(' › ', $path);
        }

        if (!$choices)
        {
            $rowOptions = static::getRowOptions($option, $htmlParams);
            return static::getTemplater()->formRow(
                '<div class="blockMessage">Seçilebilir forum bulunamadı.</div>',
                $rowOptions
            );
        }

        return static::getCheckboxRow($option, $htmlParams, $choices);
    }
}
