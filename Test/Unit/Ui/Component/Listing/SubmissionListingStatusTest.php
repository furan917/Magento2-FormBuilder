<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component\Listing;

use PHPUnit\Framework\TestCase;

class SubmissionListingStatusTest extends TestCase
{
    private function listing(): \SimpleXMLElement
    {
        return simplexml_load_file(
            __DIR__ . '/../../../../../view/adminhtml/ui_component/furan_formbuilder_submission_listing.xml'
        );
    }

    public function testItShowsAndFiltersByStatusInTheSubmissionsGrid(): void
    {
        $column = $this->listing()->xpath('//columns/column[@name="status"]');
        $this->assertCount(1, $column);
        $this->assertSame('select', (string)$column[0]->settings->filter);
        $this->assertSame(\Furan\FormBuilder\Model\Source\SubmissionStatus::class, (string)$column[0]->settings->options['class']);
    }

    public function testItOffersAStatusMassActionWithOneSubActionPerStatusGatedByTheReplyPermission(): void
    {
        $listing = $this->listing();
        $action = $listing->xpath('//massaction/action[@name="status"]');
        $this->assertCount(1, $action);
        $this->assertSame('status', (string)$action[0]->settings->type);
        $subs = $action[0]->settings->actions->action;
        $this->assertCount(4, $subs);
        $i = 0;
        foreach ($subs as $sub) {
            $this->assertSame('furanform/submission/massStatus', (string)$sub->url["path"]);
            $this->assertSame((string)$i, (string)$sub->url->param);
            $i++;
        }
        $acl = $listing->xpath('//massaction/argument/item[@name="acl"]/item[@name="status"]');
        $this->assertSame('Furan_FormBuilder::submissions_reply', (string)$acl[0]);
    }
}
