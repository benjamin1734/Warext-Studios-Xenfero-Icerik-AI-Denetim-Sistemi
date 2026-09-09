<?php

namespace Warext\AIContentInspector\Cron;

use Warext\AIContentInspector\Service\UsageTracker;

class UsagePrune
{
    public static function run(): void
    {
        try
        {
            (new UsageTracker())->prune();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Usage Prune: ');
        }
    }
}
