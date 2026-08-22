<?php

namespace SubmissionReport\Tests\Feature;

use App\Models\Conference;
use App\Models\Enums\SubmissionStatus;
use App\Models\Permission;
use App\Models\ScheduledConference;
use App\Models\Submission;
use App\Models\SubmissionFormItem;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubmissionReport\Pages\SubmissionReportPage;
use Tests\TestCase;

class SubmissionReportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_report_access_requires_plugin_view_permission(): void
    {
        $context = $this->makeSubmissionContext();
        Permission::query()->firstOrCreate([
            'name' => 'Plugin:viewAny',
            'guard_name' => 'web',
        ]);

        $this->actingAs($context['user']);
        $this->assertFalse(SubmissionReportPage::canAccess());

        $viewer = User::query()->create([
            'given_name' => 'Plugin',
            'family_name' => 'Viewer',
            'email' => 'plugin-viewer@example.test',
            'password' => 'password123456',
        ]);
        $viewer->givePermissionTo('Plugin:viewAny');

        $this->actingAs($viewer);
        $this->assertTrue(SubmissionReportPage::canAccess());
    }

    public function test_submission_report_offers_non_upload_custom_fields_as_columns(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);
        $checkboxField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_CHECKBOX, 'Participation type', 2);
        $uploadField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_UPLOAD, 'Supporting document', 3);

        $report = $this->reportPage();
        $options = $report->getReportColumnOptions();

        $this->assertSame('Institutional affiliation', $options['submission_form_response_'.$textField->getKey()]);
        $this->assertSame('Participation type', $options['submission_form_response_'.$checkboxField->getKey()]);
        $this->assertArrayNotHasKey('submission_form_response_'.$uploadField->getKey(), $options);
        $this->assertSame('Institutional affiliation', $report->reportColumnHeader('submission_form_response_'.$textField->getKey()));
    }

    public function test_submission_report_only_offers_fields_from_current_scheduled_conference(): void
    {
        $context = $this->makeSubmissionContext();
        $otherScheduledConference = ScheduledConference::withoutGlobalScopes()->create([
            'conference_id' => $context['scheduledConference']->conference_id,
            'title' => 'Other Scheduled Conference',
            'path' => 'other-scheduled-conference',
            'date_start' => now()->toDateString(),
            'date_end' => now()->addDays(2)->toDateString(),
        ]);
        $currentField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Current field', 1);
        $otherField = $this->makeFormItem($otherScheduledConference, SubmissionFormItem::TYPE_TEXT, 'Other field', 1);

        $options = $this->reportPage()->getReportColumnOptions();

        $this->assertSame('Current field', $options['submission_form_response_'.$currentField->getKey()]);
        $this->assertArrayNotHasKey('submission_form_response_'.$otherField->getKey(), $options);
    }

    public function test_submission_report_resolves_scalar_and_multiple_custom_field_responses(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);
        $checkboxField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_CHECKBOX, 'Participation type', 2);
        $context['submission']->setMeta('submission_form_responses', [
            $textField->getKey() => 'Example University',
            $checkboxField->getKey() => ['Speaker', 'Organizer'],
        ]);

        $report = $this->reportPage();

        $this->assertSame('Example University', $report->reportColumn($context['submission'], 'submission_form_response_'.$textField->getKey()));
        $this->assertSame('Speaker, Organizer', $report->reportColumn($context['submission'], 'submission_form_response_'.$checkboxField->getKey()));
    }

    public function test_submission_report_returns_blank_for_missing_custom_field_response(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);

        $this->assertNull(
            $this->reportPage()->reportColumn($context['submission'], 'submission_form_response_'.$textField->getKey())
        );
    }

    public function test_submission_report_exports_blank_cell_for_missing_custom_field_response(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);
        $context['submission']->update(['status' => SubmissionStatus::Incomplete]);
        $this->actingAs($context['user']);

        $response = $this->reportPage()->exportReport([
            'status' => [SubmissionStatus::Incomplete->value],
            'columns' => ['submission_form_response_'.$textField->getKey()],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'submission-report-');
        file_put_contents($path, $this->responseContent($response));

        try {
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($path));
            $worksheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();
        } finally {
            unlink($path);
        }

        $this->assertStringContainsString('<row r="2"', $worksheet);
        $this->assertStringContainsString('<c r="A2"', $worksheet);
    }

    public function test_submission_report_exports_custom_field_headers_and_values(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);
        $checkboxField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_CHECKBOX, 'Participation type', 2);
        $context['submission']->update(['status' => SubmissionStatus::Incomplete]);
        $context['submission']->setMeta('submission_form_responses', [
            $textField->getKey() => 'Example University',
            $checkboxField->getKey() => ['Speaker', 'Organizer'],
        ]);
        $this->actingAs($context['user']);

        $response = $this->reportPage()->exportReport([
            'status' => [SubmissionStatus::Incomplete->value],
            'columns' => [
                'submission_form_response_'.$textField->getKey(),
                'submission_form_response_'.$checkboxField->getKey(),
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'submission-report-');
        file_put_contents($path, $this->responseContent($response));

        try {
            $reader = new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }

                break;
            }

            $reader->close();
        } finally {
            unlink($path);
        }

        $this->assertSame(['Institutional affiliation', 'Participation type'], $rows[0]);
        $this->assertSame(['Example University', 'Speaker, Organizer'], $rows[1]);
    }

    public function test_submission_report_exports_custom_fields_with_postgres_query_grammar(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, 'Institutional affiliation', 1);
        $context['submission']->update(['status' => SubmissionStatus::Incomplete]);
        $context['submission']->setMeta('submission_form_responses', [
            $textField->getKey() => 'Example University',
        ]);
        $this->actingAs($context['user']);

        $connection = DB::connection();
        $queryGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new PostgresGrammar);

        try {
            $response = $this->reportPage()->exportReport([
                'status' => [SubmissionStatus::Incomplete->value],
                'columns' => ['submission_form_response_'.$textField->getKey()],
            ]);
        } finally {
            $connection->setQueryGrammar($queryGrammar);
        }

        $rows = $this->readRowsFromResponse($response);

        $this->assertSame(['Institutional affiliation'], $rows[0]);
        $this->assertSame(['Example University'], $rows[1]);
    }

    public function test_submission_report_ignores_upload_columns_during_export(): void
    {
        $context = $this->makeSubmissionContext();
        $uploadField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_UPLOAD, 'Supporting document', 1);
        $context['submission']->update(['status' => SubmissionStatus::Incomplete]);
        $context['submission']->setMeta('submission_form_responses', [
            $uploadField->getKey() => 'private-document.pdf',
        ]);
        $this->actingAs($context['user']);

        $response = $this->reportPage()->exportReport([
            'status' => [SubmissionStatus::Incomplete->value],
            'columns' => ['id', 'submission_form_response_'.$uploadField->getKey()],
        ]);

        $rows = $this->readRowsFromResponse($response);

        $this->assertSame(['id'], $rows[0]);
        $this->assertSame([$context['submission']->getKey()], $rows[1]);
    }

    public function test_submission_report_escapes_custom_field_spreadsheet_formulas(): void
    {
        $context = $this->makeSubmissionContext();
        $textField = $this->makeFormItem($context['scheduledConference'], SubmissionFormItem::TYPE_TEXT, '=Custom field', 1);
        $context['submission']->update(['status' => SubmissionStatus::Incomplete]);
        $context['submission']->setMeta('submission_form_responses', [
            $textField->getKey() => '=1+1',
        ]);
        $this->actingAs($context['user']);

        $response = $this->reportPage()->exportReport([
            'status' => [SubmissionStatus::Incomplete->value],
            'columns' => ['submission_form_response_'.$textField->getKey()],
        ]);

        $rows = $this->readWorkbookRowsFromResponse($response);

        $this->assertInstanceOf(\OpenSpout\Common\Entity\Cell\StringCell::class, $rows[0]->getCellAtIndex(0));
        $this->assertSame("'=Custom field", $rows[0]->getCellAtIndex(0)->getValue());
        $this->assertInstanceOf(\OpenSpout\Common\Entity\Cell\StringCell::class, $rows[1]->getCellAtIndex(0));
        $this->assertSame("'=1+1", $rows[1]->getCellAtIndex(0)->getValue());
    }

    protected function readRowsFromResponse(object $response): array
    {
        return array_map(
            fn (\OpenSpout\Common\Entity\Row $row) => $row->toArray(),
            $this->readWorkbookRowsFromResponse($response)
        );
    }

    protected function readWorkbookRowsFromResponse(object $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'submission-report-');
        ob_start();
        $response->sendContent();
        file_put_contents($path, ob_get_clean());

        try {
            $reader = new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row;
                }

                break;
            }

            $reader->close();
        } finally {
            unlink($path);
        }

        return $rows;
    }

    protected function responseContent(object $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    protected function reportPage(): object
    {
        return new class extends \SubmissionReport\Pages\SubmissionReportPage
        {
            public function reportColumn(Submission $submission, string $column): mixed
            {
                return $this->getReportColumn($submission, $column);
            }

            public function reportColumnHeader(string $column): string
            {
                return $this->getReportColumnHeader($column);
            }

            public function exportReport(array $data)
            {
                return $this->export($data);
            }
        };
    }

    protected function makeSubmissionContext(): array
    {
        $conference = Conference::query()->create([
            'name' => 'Conference',
            'path' => 'conference',
        ]);
        $scheduledConference = ScheduledConference::withoutGlobalScopes()->create([
            'conference_id' => $conference->getKey(),
            'title' => 'Scheduled Conference',
            'path' => 'scheduled-conference',
            'date_start' => now()->toDateString(),
            'date_end' => now()->addDays(2)->toDateString(),
        ]);

        app()->setCurrentConferenceId($conference->getKey());
        app()->setCurrentScheduledConferenceId($scheduledConference->getKey());

        $track = Track::withoutGlobalScopes()->create([
            'scheduled_conference_id' => $scheduledConference->getKey(),
            'title' => 'Track',
            'abbreviation' => 'TRK',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'given_name' => 'Author',
            'family_name' => 'Tester',
            'email' => 'author@example.test',
            'password' => 'password123456',
        ]);
        $submission = Submission::withoutGlobalScopes()->forceCreate([
            'user_id' => $user->getKey(),
            'conference_id' => $conference->getKey(),
            'scheduled_conference_id' => $scheduledConference->getKey(),
            'track_id' => $track->getKey(),
        ]);

        return compact('scheduledConference', 'submission', 'user');
    }

    protected function makeFormItem(ScheduledConference $scheduledConference, int $type, string $name, int $order): SubmissionFormItem
    {
        $item = SubmissionFormItem::withoutGlobalScopes()->create([
            'scheduled_conference_id' => $scheduledConference->getKey(),
            'type' => $type,
            'order_column' => $order,
        ]);
        $item->setMeta('name', $name);

        return $item;
    }
}
