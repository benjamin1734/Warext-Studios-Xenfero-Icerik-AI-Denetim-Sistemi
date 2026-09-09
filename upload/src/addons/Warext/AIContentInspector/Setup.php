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
            $table->addColumn('profile_metrics', 'mediumblob')->nullable();
            $table->addColumn('similarity_metrics', 'mediumblob')->nullable();
            $table->addColumn('external_metrics', 'mediumblob')->nullable();
            $table->addColumn('signal_summary', 'mediumblob')->nullable();
            $table->addColumn('content_hash', 'varchar', 64)->setDefault('');
            $table->addColumn('content_fingerprint', 'varchar', 16)->setDefault('');
            $table->addColumn('review_state', 'varchar', 24)->setDefault('pending');
            $table->addColumn('reviewer_user_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('reviewed_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('analyzed_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('updated_date', 'int')->unsigned()->setDefault(0);
            $table->addKey('post_id');
            $table->addKey(['thread_id', 'risk_score'], 'thread_risk');
            $table->addKey(['forum_id', 'risk_score'], 'forum_risk');
            $table->addKey(['forum_id', 'analyzed_date'], 'forum_date');
            $table->addKey(['user_id', 'analyzed_date'], 'user_date');
            $table->addKey(['review_state', 'risk_score'], 'review_risk');
            $table->addKey(['forum_id', 'content_fingerprint'], 'forum_fingerprint');
        });

        $this->createReviewLogTable();
        $this->createUsageTable();
    }

    public function upgrade1000080Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_ai_analysis', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('profile_metrics', 'mediumblob')->nullable()->after('writing_metrics');
            $table->addKey(['review_state', 'risk_score'], 'review_risk');
        });

        $this->createReviewLogTable();
    }

    public function upgrade1000090Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_ai_analysis', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('content_fingerprint', 'varchar', 16)->setDefault('')->after('content_hash');
            $table->addKey(['forum_id', 'content_fingerprint'], 'forum_fingerprint');
        });
    }

    public function upgrade1000110Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_ai_analysis', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('external_metrics', 'mediumblob')->nullable()->after('profile_metrics');
        });
    }

    public function upgrade1000170Step1(): void
    {
        $this->createUsageTable();
    }

    public function upgrade1000190Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_ai_analysis', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('similarity_metrics', 'mediumblob')->nullable()->after('profile_metrics');
            $table->addKey(['forum_id', 'analyzed_date'], 'forum_date');
        });
    }

    public function upgrade1000200Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_ai_usage', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('cost_source', 'varchar', 12)->setDefault('unknown')->after('cost_microusd');
        });
    }

    protected function createReviewLogTable(): void
    {
        $this->schemaManager()->createTable('xf_warext_ai_review_log', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('review_id', 'int')->unsigned()->autoIncrement();
            $table->addColumn('analysis_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('post_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('reviewer_user_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('from_state', 'varchar', 24)->setDefault('pending');
            $table->addColumn('to_state', 'varchar', 24)->setDefault('pending');
            $table->addColumn('note', 'varchar', 500)->setDefault('');
            $table->addColumn('created_date', 'int')->unsigned()->setDefault(0);
            $table->addPrimaryKey('review_id');
            $table->addKey(['post_id', 'created_date'], 'post_date');
            $table->addKey(['reviewer_user_id', 'created_date'], 'reviewer_date');
        });
    }

    protected function createUsageTable(): void
    {
        $this->schemaManager()->createTable('xf_warext_ai_usage', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('usage_id', 'int')->unsigned()->autoIncrement();
            $table->addColumn('post_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('provider_id', 'varchar', 32)->setDefault('');
            $table->addColumn('model', 'varchar', 191)->setDefault('');
            $table->addColumn('prompt_tokens', 'int')->unsigned()->setDefault(0);
            $table->addColumn('completion_tokens', 'int')->unsigned()->setDefault(0);
            $table->addColumn('total_tokens', 'int')->unsigned()->setDefault(0);
            $table->addColumn('cost_microusd', 'bigint')->unsigned()->setDefault(0);
            $table->addColumn('cost_source', 'varchar', 12)->setDefault('unknown');
            $table->addColumn('success', 'tinyint')->unsigned()->setDefault(0);
            $table->addColumn('failure_reason', 'varchar', 80)->setDefault('');
            $table->addColumn('created_date', 'int')->unsigned()->setDefault(0);
            $table->addPrimaryKey('usage_id');
            $table->addKey(['provider_id', 'created_date'], 'provider_date');
            $table->addKey(['post_id', 'created_date'], 'post_date');
            $table->addKey('created_date');
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_warext_ai_usage');
        $this->schemaManager()->dropTable('xf_warext_ai_review_log');
        $this->schemaManager()->dropTable('xf_warext_ai_analysis');
    }
}
