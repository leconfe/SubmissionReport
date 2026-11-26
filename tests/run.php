<?php

require __DIR__.'/fixtures.php';

use App\Models\Review;
use App\Models\Submission;
use App\Panel\ScheduledConference\Pages\ReviewResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use SubmissionReport\Pages\SubmissionReportPage;
use SubmissionReport\Services\ReviewReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TestReportPage extends SubmissionReportPage
{
    public function download(array $data) { return $this->export($data); }
}

class FailingReportPage extends TestReportPage
{
    protected function writeWorkbook(string $filename, array $data): void
    {
        file_put_contents($filename, 'partial workbook');
        throw new RuntimeException('Fixture export failure');
    }
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS: {$message}\n";
}
function rejects(callable $action, string $message, string $exceptionClass = ValidationException::class): void
{
    try { $action(); } catch (Throwable $error) {
        if (! $error instanceof $exceptionClass) { throw $error; }
        check(true, $message);
        return;
    }
    throw new RuntimeException($message.' (was allowed)');
}
function workbook(array $overrides = []): array
{
    $report = new ReviewReport(101);
    $data = array_replace([
        'status' => ['On Review'],
        'columns' => ['id', 'title', 'status', 'average_score', 'review_count', 'completed_review_count', 'submission_form_response_51', 'reviewers'],
        'include_review_details' => true,
        'review_columns' => array_keys($report->columnOptions()),
        'review_round' => 'effective', 'completed_reviews_only' => true,
    ], $overrides);
    $response = (new TestReportPage)->download($data);
    check($response->headers->get('Content-Type') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Download has Excel content type');
    $path = $response->getFile()->getPathname();
    $reader = new Reader;
    $sheets = [];
    try {
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) { $rows[] = $row->toArray(); }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        $zip = new ZipArchive;
        $zip->open($path);
        for ($i = 1; $i <= count($sheets); $i++) {
            $xml = $zip->getFromName('xl/worksheets/sheet'.$i.'.xml');
            check(! preg_match('/<f(?:\s|>)/', $xml), 'Sheet '.$i.' contains no injected Excel formulas');
            check(str_contains($xml, '<autoFilter ') && str_contains($xml, 'state="frozen"'), 'Sheet '.$i.' has filters and a frozen header');
        }
        $zip->close();
        if (getenv('SUBMISSION_REPORT_QA_OUTPUT')) {
            copy($path, getenv('SUBMISSION_REPORT_QA_OUTPUT'));
            putenv('SUBMISSION_REPORT_QA_OUTPUT');
        }
    } finally {
        $reader->close();
        if (is_file($path)) { unlink($path); }
    }

    return $sheets;
}
function records(array $rows): array
{
    $headers = array_shift($rows);
    return array_map(fn ($row) => array_combine($headers, array_pad($row, count($headers), '')), $rows);
}

// Keep test downloads outside the installation's private files.
$fixtureDir = sys_get_temp_dir().'/submission-report-'.bin2hex(random_bytes(8));
mkdir($fixtureDir);
config(['filesystems.disks.private-files.root' => $fixtureDir]);
Illuminate\Support\Facades\Storage::forgetDisk('private-files');
try {
    $report = new ReviewReport(101);
    $options = $report->roundOptions();
    check(isset($options['round_1'], $options['round_2']) && ! isset($options['round_9']), 'Round options are scoped to the current conference');
    check(isset($report->columnOptions()['review_response_105']) && ! isset($report->columnOptions()['review_response_201']), 'Review form columns include uploads and exclude other conferences');

    $page = new TestReportPage;
    $page->mount();
    check($page->formData['include_review_details'] === true && $page->formData['completed_reviews_only'] === true, 'Form defaults include completed review details');
    $page->formData['status'] = ['On Review'];
    $state = $page->form->getState();
    check($state['review_round'] === 'effective' && count($state['review_columns']) === count($report->columnOptions()), 'The real Filament form validates its default export options');
    $full = workbook($state);
    check(in_array('abstract', $full['Submission Summary'][0], true) && in_array('correspondance_author_name', $full['Submission Summary'][0], true), 'All existing summary columns export through the default form');
    $page->formData['include_review_details'] = false;
    $summaryOnly = $page->form->getState();
    check(! $summaryOnly['include_review_details'] && ! isset($summaryOnly['review_columns']), 'Disabling details removes the hidden review column requirement');

    $sheets = workbook();
    check(array_keys($sheets) === ['Submission Summary', 'Review Details'], 'Workbook contains summary and review detail sheets');
    $summary = collect(records($sheets['Submission Summary']))->keyBy('id');
    $details = collect(records($sheets['Review Details']));
    check($summary->keys()->sort()->values()->all() === [501, 502, 503, 504], 'Summary respects submission status and conference filters');
    check($details->pluck('Review ID')->sort()->values()->all() === [2, 3, 10, 30], 'Effective reviews exclude old, superseded, canceled, declined, unfinished, and inaccessible reviews');
    check(abs((float) $summary[501]['average_score'] - 76.67) < 0.001, 'Average uses exactly the effective completed reviews');
    check((int) $summary[503]['average_score'] === 0 && $summary[503]['average_score'] !== '', 'A zero average is preserved as a number');
    check($summary[502]['average_score'] === '' && (int) $summary[502]['review_count'] === 0, 'Submissions with no reviews export safely');
    check($summary[501]['Additional submission question'] === 'Submission answer', 'Existing custom submission form exports are preserved');
    check($summary[501]['title'] === '=Literal submission title', 'A formula-like submission title remains literal text');
    check($summary[501]['reviewer_1_name'] === 'User 3 Fixture' && $summary[501]['reviewer_2_name'] === '=Reviewer Fixture', 'Summary reviewer names follow the same review selection');
    check($summary[504]['average_score'] === '' && $summary[504]['reviewer_1_name'] === '', 'Author participants do not receive reviewer data through the report');
    $review = $details->firstWhere('Review ID', 2);
    check($review['Comments for Author and Editor'] === "=SUM(1,2)\nPerbaiki & lengkapi\nreferensi.", 'HTML comments become readable text while preserving line breaks');
    check($review['Comments for Editor Only'] === 'Editor secret 2', 'Authorized editors receive confidential comments');
    check($review['Review Form: Originality'] === '=Literal answer', 'Formula-like reviewer answers remain literal text');
    check($review['Review Form: Quality'] === 'Needs work', 'Zero-valued scoring responses export their option labels');
    check($review['Review Form: Criteria'] === 'Method, Results' && $review['Review Form: Ethics'] === 'Compliant', 'Checkbox and radio responses export readable labels');
    check($review['Review Form: Annotated file'] === 'annotated-paper.pdf', 'Upload responses export the attachment filename');
    check($review['Review Form: Detailed assessment'] === "Paragraph one\nParagraph two", 'Textarea responses preserve paragraph breaks');
    foreach ($sheets['Submission Summary'] as $row) {
        check(count($row) === count($sheets['Submission Summary'][0]), 'Summary rows retain aligned reviewer columns');
    }

    // Compare the report to the actual Review Result calculation.
    app()->scopeCurrentConference();
    app()->scopeCurrentScheduledConference();
    $core = ReviewResult::reviewResultsQuery()->findOrFail(501);
    check(abs(round($core->effective_reviews_avg_score, 2) - $summary[501]['average_score']) < 0.001, 'Default average matches the core Review Result page');

    $all = workbook(['review_round' => 'all', 'completed_reviews_only' => false]);
    $allDetails = collect(records($all['Review Details']));
    check($allDetails->where('Submission ID', 501)->count() === 10, 'All-round history includes pending, declined, and canceled assignments');
    $draft = $allDetails->firstWhere('Review ID', 4);
    check($draft['Review Status'] === 'In progress' && $draft['Score'] === '' && $draft['Recommendation'] === '' && $draft['Comments for Editor Only'] === '' && $draft['Review Form: Originality'] === '', 'Unsubmitted draft scores, recommendations, answers, and comments stay private');
    $allSummary = collect(records($all['Submission Summary']))->keyBy('id');
    check(abs((float) $allSummary[501]['average_score'] - 57.5) < 0.001, 'History averages exclude canceled reviews and unsubmitted drafts');
    check((int) $allSummary[501]['review_count'] === 10 && (int) $allSummary[501]['completed_review_count'] === 6, 'History counts distinguish exported assignments and completed reviews');

    $latest = workbook(['review_round' => 'latest_round']);
    check(collect(records($latest['Review Details']))->where('Submission ID', 501)->pluck('Review ID')->sort()->values()->all() === [2, 9, 10], 'Latest-round selection excludes earlier rounds');
    $first = workbook(['review_round' => 'round_1']);
    check(collect(records($first['Review Details']))->where('Submission ID', 501)->pluck('Review ID')->sort()->values()->all() === [1, 3, 5], 'A specific round exports the requested historical reviews');
    $single = workbook(['include_review_details' => false, 'review_columns' => [], 'columns' => ['id', 'title']]);
    check(array_keys($single) === ['Submission Summary'], 'Review Details can be disabled without breaking the original summary export');
    $selected = workbook(['review_columns' => ['reviewer_name', 'review_for_author_editor']]);
    check($selected['Review Details'][0] === ['Submission ID', 'Submission Title', 'Reviewer Name', 'Comments for Author and Editor'], 'Detail export honors selected columns and retains submission identifiers');
    $empty = workbook(['status' => ['Declined'], 'columns' => ['reviewers']]);
    check(count($empty['Submission Summary']) === 1 && count($empty['Review Details']) === 1, 'An empty result exports valid sheets with headers');

    $reviewerRecord = DB::table('users')->where('id', 8)->first();
    DB::table('users')->where('id', 8)->delete();
    $missingReviewer = workbook();
    check(collect(records($missingReviewer['Review Details']))->firstWhere('Review ID', 10)['Reviewer Name'] === '', 'A missing reviewer account does not break the report');
    DB::table('users')->insert((array) $reviewerRecord);

    $editor = new ReportTestUser;
    $editor->forceFill(['id' => 10]); $editor->canSubmitAs = false;
    auth()->setUser($editor);
    $editorSheets = workbook();
    check(collect(records($editorSheets['Review Details']))->pluck('Submission ID')->unique()->sort()->values()->all() === [501, 503], 'An assigned editor exports reviews only for their own submissions');
    $editor->forceFill(['id' => 1]);
    $unassigned = workbook();
    check(count($unassigned['Review Details']) === 1, 'Plugin access alone does not grant access to reviewer feedback');

    $valid = ['status' => ['On Review'], 'columns' => ['id']];
    rejects(fn () => (new TestReportPage)->download($valid + ['review_round' => 'round_9']), 'A forged round from another conference is rejected');
    rejects(fn () => (new TestReportPage)->download($valid + ['include_review_details' => true, 'review_columns' => []]), 'At least one review column is required when details are enabled');
    rejects(fn () => (new TestReportPage)->download($valid + ['include_review_details' => true, 'review_columns' => ['review_response_201']]), 'Forged review form columns are rejected');
    rejects(fn () => (new TestReportPage)->download(['status' => [], 'columns' => ['id']]), 'Empty status filters are rejected');
    rejects(fn () => (new TestReportPage)->download(['status' => ['On Review'], 'columns' => ['unknown']]), 'Unknown summary columns are rejected');
    rejects(fn () => (new FailingReportPage)->download($valid), 'Export failures are reported', RuntimeException::class);
    check(glob($fixtureDir.'/reports/*.xlsx') === [], 'Failed exports clean up partial private files');
    $download = (new TestReportPage)->download($valid);
    $downloadPath = $download->getFile()->getPathname();
    ob_start();
    $download->sendContent();
    $downloadBytes = ob_get_clean();
    check(str_starts_with($downloadBytes, 'PK') && strlen($downloadBytes) > 1000, 'The download response sends the generated workbook bytes');
    check(! is_file($downloadPath), 'A completed download deletes its temporary private file');
    $editor->pluginAccess = false;
    rejects(fn () => (new TestReportPage)->download($valid), 'Export checks permissions again on download', HttpException::class);
    $editor->pluginAccess = true;
    auth()->forgetUser();
    check(! SubmissionReportPage::canAccess(), 'Guests cannot access the report');
} finally {
    // Only files created in the random fixture directory can be removed here.
    foreach (glob($fixtureDir.'/reports/*.xlsx') ?: [] as $file) { unlink($file); }
    if (is_dir($fixtureDir.'/reports')) { rmdir($fixtureDir.'/reports'); }
    rmdir($fixtureDir);
}
echo "\n{$checks} checks passed.\n";
