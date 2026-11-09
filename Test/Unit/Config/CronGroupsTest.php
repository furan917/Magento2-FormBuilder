<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

class CronGroupsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../etc/';

    public function testItRunsEveryModuleJobInADeclaredModuleGroupWithItsOwnProcess(): void
    {
        $groups = simplexml_load_file(self::ROOT . 'cron_groups.xml');
        $separate = [];
        foreach ($groups->group as $group) {
            $separate[(string)$group['id']] = (string)$group->use_separate_process === '1';
        }
        $crontab = simplexml_load_file(self::ROOT . 'crontab.xml');
        $jobs = 0;
        foreach ($crontab->group as $group) {
            $id = (string)$group['id'];
            $this->assertStringStartsWith('furan_formbuilder', $id, 'Module jobs must not run in a shared group');
            $this->assertTrue($separate[$id] ?? false, $id . ' must be declared with use_separate_process=1');
            $jobs += count($group->job);
        }
        $this->assertSame(12, $jobs);
    }

    public function testItDeclaresOnlyTheHousekeepingRealtimeFoldAndRebuildGroupsAndUsesEachOne(): void
    {
        $declared = array_map('strval', simplexml_load_file(self::ROOT . 'cron_groups.xml')->xpath('//group/@id'));
        $used = array_map('strval', simplexml_load_file(self::ROOT . 'crontab.xml')->xpath('//group/@id'));
        sort($declared);
        sort($used);
        $this->assertSame(
            ['furan_formbuilder', 'furan_formbuilder_fold', 'furan_formbuilder_realtime', 'furan_formbuilder_stats'],
            $declared
        );
        $this->assertSame($declared, $used);
    }

    public function testItKeepsTimeSensitiveJobsInTheirOwnGroupAwayFromLongPurges(): void
    {
        $this->assertSame(
            ['furan_formbuilder_form_schedule', 'furan_formbuilder_action_retries', 'furan_formbuilder_webhook_drain'],
            $this->jobsIn('furan_formbuilder_realtime')
        );
        $realtime = simplexml_load_file(self::ROOT . 'cron_groups.xml')->xpath('//group[@id="furan_formbuilder_realtime"]')[0];
        $this->assertSame('1', (string)$realtime->schedule_generate_every);
    }

    public function testItRunsTheFoldAloneSoNoOtherJobInItsGroupDelaysIt(): void
    {
        $this->assertSame(['furan_formbuilder_stats_fold'], $this->jobsIn('furan_formbuilder_fold'));
        $fold = simplexml_load_file(self::ROOT . 'cron_groups.xml')->xpath('//group[@id="furan_formbuilder_fold"]')[0];
        $this->assertSame('1', (string)$fold->schedule_generate_every);
        $jobs = $this->jobsIn('furan_formbuilder');
        $this->assertNotContains('furan_formbuilder_stats_fold', $jobs);
        $this->assertContains('furan_formbuilder_form_purge', $jobs);
        $this->assertContains('furan_formbuilder_webhook_retention', $jobs);
    }

    /** @return list<string> */
    private function jobsIn(string $group): array
    {
        $crontab = simplexml_load_file(self::ROOT . 'crontab.xml');

        return array_map('strval', $crontab->xpath('//group[@id="' . $group . '"]/job/@name'));
    }

    public function testItKeepsJobNamesSoExistingSchedulesAndMonitoringStillMatch(): void
    {
        $crontab = simplexml_load_file(self::ROOT . 'crontab.xml');
        $names = array_map('strval', $crontab->xpath('//job/@name'));
        sort($names);
        $this->assertSame([
            'furan_formbuilder_action_retries',
            'furan_formbuilder_counter_cleanup',
            'furan_formbuilder_draft_cleanup',
            'furan_formbuilder_file_cleanup',
            'furan_formbuilder_form_purge',
            'furan_formbuilder_form_schedule',
            'furan_formbuilder_privacy_pending',
            'furan_formbuilder_retention',
            'furan_formbuilder_stats_fold',
            'furan_formbuilder_stats_rebuild_queue',
            'furan_formbuilder_webhook_drain',
            'furan_formbuilder_webhook_retention',
        ], $names);
    }

    public function testItDrainsLargeFormDeletesEveryMinute(): void
    {
        $crontab = simplexml_load_file(self::ROOT . 'crontab.xml');
        $job = $crontab->xpath('//group[@id="furan_formbuilder"]/job[@name="furan_formbuilder_form_purge"]')[0];
        $this->assertSame(\Furan\FormBuilder\Model\Cron\FormPurge::class, (string)$job['instance']);
        $this->assertSame('* * * * *', (string)$job->schedule);
    }

    public function testItDrainsWebhooksEveryMinute(): void
    {
        $job = simplexml_load_file(self::ROOT . 'crontab.xml')->xpath('//job[@name="furan_formbuilder_webhook_drain"]')[0];
        $this->assertSame(\Furan\FormBuilder\Model\Cron\WebhookDrain::class, (string)$job['instance']);
        $this->assertSame('* * * * *', (string)$job->schedule);
    }

    public function testItPurgesWebhookDeliveriesHourly(): void
    {
        $job = simplexml_load_file(self::ROOT . 'crontab.xml')->xpath('//job[@name="furan_formbuilder_webhook_retention"]')[0];
        $this->assertSame(\Furan\FormBuilder\Model\Cron\WebhookRetention::class, (string)$job['instance']);
        $this->assertSame('35 * * * *', (string)$job->schedule);
    }

    public function testItSweepsPendingPrivacyWorkOfDeletedAccountsInTheHousekeepingGroupEveryFiveMinutes(): void
    {
        $job = simplexml_load_file(self::ROOT . 'crontab.xml')->xpath('//group[@id="furan_formbuilder"]/job[@name="furan_formbuilder_privacy_pending"]')[0];
        $this->assertSame(\Furan\FormBuilder\Model\Cron\PrivacyPending::class, (string)$job['instance']);
        $this->assertSame('*/5 * * * *', (string)$job->schedule);
    }
}
