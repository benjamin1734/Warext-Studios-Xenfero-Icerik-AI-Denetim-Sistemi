<?php

namespace Warext\AIContentInspector;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_warext_ai_analysis', function (Create $table)
        {
            $table->addColumn('analysis_id', 'int')->unsigned()->autoIncrement();
            $table->addColumn('post_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('thread_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('user_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('forum_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('risk_score', 'tinyint')->unsigned()->setDefault(0);
            $table->addColumn('confidence', 'tinyint')->unsigned()->setDefault(0);
            $table->addColumn('classification', 'varchar', 32)->setDefault('unknown');
            $table->addColumn('text_metrics', 'mediumblob')->nullable();
            $table->addColumn('behavior_metrics', 'mediumblob')->nullable();
            $table->addColumn('writing_metrics', 'mediumblob')->nullable();
            $table->addColumn('signal_summary', 'mediumblob')->nullable();
            $table->addColumn('content_hash', 'varchar', 64)->setDefault('');
            $table->addColumn('review_state', 'varchar', 24)->setDefault('pending');
            $table->addColumn('reviewer_user_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('reviewed_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('analyzed_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('updated_date', 'int')->unsigned()->setDefault(0);
            $table->addKey('post_id');
            $table->addKey(['thread_id', 'risk_score'], 'thread_risk');
            $table->addKey(['forum_id', 'risk_score'], 'forum_risk');
            $table->addKey(['user_id', 'analyzed_date'], 'user_date');
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_warext_ai_analysis');
    }
}
