<?php

namespace SubmissionReport\Pages;

use App\Constants\ReviewerStatus;
use App\Models\Author;
use App\Models\Enums\SubmissionStatus;
use App\Models\Review;
use App\Models\Submission;
use App\Models\SubmissionFormItem;
use App\Models\Topic;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Squire\Models\Country;
use SubmissionReport\Services\ReviewReport;

class SubmissionReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Submission Report';

    protected static string $view = 'SubmissionReport::submission-report';

    protected static bool $shouldRegisterNavigation = false;

    protected static array $options = [
        'id' => 'ID',
        'authors' => 'Authors',
        'editors' => "Editors",
        'reviewers' => "Reviewers",
        'submitter_name' => 'Submitter Name',
        'submitter_email' => 'Submitter Email',
        'submitter_affiliation' => 'Submitter Affiliation',
        'submitter_country' => 'Submitter Country',
        'submitter_phone' => 'Submitter Phone',
        'correspondance_author_name' => "Correspondance Author Name",
        'correspondance_author_email' => "Correspondance Author Email",
        'title' => "Submission Title",
        'status' => "Submission Status",
        'track' => "Track",
        'keywords' => "Keywords",
        'topics' => "Topics",
        'abstract' => "Abstract",
        'average_score' => "Average Score",
        'review_count' => 'Exported Review Count',
        'completed_review_count' => 'Completed Review Count',
    ];

    public array $formData = [];

    protected ?ReviewReport $reviewReport = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'columns' => array_keys($this->getReportColumnOptions()),
            'include_review_details' => true,
            'review_columns' => array_keys($this->report()->columnOptions()),
            'review_round' => 'effective',
            'completed_reviews_only' => true,
        ]);
    }

    public static function getRoutePath(): string
    {
        return '/submission-report';
    }

    public static function canAccess(): bool
    {
        return app()->getCurrentScheduledConferenceId() !== null
            && (auth()->user()?->can('Plugin:viewAny') ?? false);
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Export submission summaries and reviewer feedback in one Excel workbook.';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Submission Summary')
                    ->description('One row per submission. Select the submission statuses and summary columns to export.')
                    ->schema([
                        CheckboxList::make('status')
                            ->label('Select Submission Status that you want to export')
                            ->options(collect(array_combine(SubmissionStatus::values(), SubmissionStatus::values()))->filter(fn ($value) => ! in_array($value, ['Payment Declined', 'On Payment'])))
                            ->bulkToggleable()
                            ->required()
                            ->columns(3),
                        CheckboxList::make('columns')
                            ->required()
                            ->label('Select Columns to be exported')
                            ->options($this->getReportColumnOptions())
                            ->bulkToggleable()
                            ->columns(3),
                    ]),
                Section::make('Review Selection')
                    ->description('These choices apply to reviewer columns, review counts, average scores, and the Review Details sheet. Review data is included only for submissions you can manage as editor.')
                    ->schema([
                        Select::make('review_round')
                            ->label('Review rounds')
                            ->options($this->report()->roundOptions())
                            ->default('effective')
                            ->required(),
                        Toggle::make('completed_reviews_only')
                            ->label('Completed reviews only')
                            ->helperText('Turn off to include assignment history. Unsubmitted draft answers and comments are excluded. Average scores always use completed, accepted assignments.')
                            ->default(true),
                    ]),
                Section::make('Review Details')
                    ->description('One row per reviewer per round, with submission ID and title always included. Upload answers contain filenames; attached files are not embedded.')
                    ->schema([
                        Toggle::make('include_review_details')
                            ->label('Include Review Details sheet')
                            ->default(true)
                            ->live(),
                        CheckboxList::make('review_columns')
                            ->label('Review columns')
                            ->options($this->report()->columnOptions())
                            ->required(fn (Get $get) => (bool) $get('include_review_details'))
                            ->visible(fn (Get $get) => (bool) $get('include_review_details'))
                            ->bulkToggleable()
                            ->columns(2),
                    ]),
            ])
            ->statePath('formData');
    }

    public function submit()
    {
        abort_unless(static::canAccess(), 403);

        return $this->export($this->form->getState());
    }

    protected function export(array $data)
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->validateExportData($data);

        $name = implode('-', [
            'submissions',
            app()->getCurrentScheduledConference()->getKey(),
            now()->timestamp,
        ]);
        $disk = Storage::disk('private-files');
        $disk->makeDirectory('reports');
        $filename = $disk->path('reports/'.Str::uuid().'.xlsx');

        try {
            $this->writeWorkbook($filename, $data);
        } catch (\Throwable $exception) {
            if (is_file($filename)) {
                unlink($filename);
            }
            throw $exception;
        }

        return response()->download($filename, $name.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    protected function report(): ReviewReport
    {
        return $this->reviewReport ??= new ReviewReport(app()->getCurrentScheduledConferenceId());
    }

    protected function validateExportData(array $data): array
    {
        $data += [
            'include_review_details' => false,
            'review_columns' => [],
            'review_round' => 'effective',
            'completed_reviews_only' => true,
        ];

        return Validator::make($data, [
            'status' => ['required', 'array', 'min:1'],
            'status.*' => ['required', 'string', Rule::in(SubmissionStatus::values())],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->getReportColumnOptions()))],
            'include_review_details' => ['required', 'boolean'],
            'review_round' => ['required', 'string', Rule::in(array_keys($this->report()->roundOptions()))],
            'completed_reviews_only' => ['required', 'boolean'],
            'review_columns' => [Rule::requiredIf((bool) $data['include_review_details']), 'array'],
            'review_columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->report()->columnOptions()))],
        ])->validate();
    }

    protected function writeWorkbook(string $filename, array $data): void
    {
        $report = $this->report();
        $columnOptions = $this->getReportColumnOptions();
        $summaryColumns = array_values(array_diff($data['columns'], ['reviewers']));
        $includeReviewers = in_array('reviewers', $data['columns'], true);
        $maxReviewers = 0;
        $headers = array_map(fn ($column) => $this->getReportColumnHeader($column, $columnOptions), $summaryColumns);

        if ($includeReviewers) {
            $counts = $report->applyFilters(Review::query(), $data)
                ->whereHas('submission', fn ($query) => $query->whereIn('status', $data['status']))
                ->select('reviews.submission_id')->selectRaw('COUNT(*) as review_count')->groupBy('reviews.submission_id');
            $maxReviewers = max(1, (int) DB::query()->fromSub($counts->toBase(), 'report_counts')->max('review_count'));
            for ($i = 1; $i <= $maxReviewers; $i++) {
                $headers[] = 'reviewer_'.$i.'_name';
                $headers[] = 'reviewer_'.$i.'_score';
            }
        }

        $writer = new Writer;
        $writer->getOptions()->DEFAULT_ROW_STYLE = (new Style)->setShouldWrapText()->setCellVerticalAlignment('top');
        $writer->openToFile($filename);

        try {
            $summarySheet = $writer->getCurrentSheet()->setName('Submission Summary');
            $this->prepareSheet($writer, $summarySheet, $headers);
            $detailSheet = null;
            if ($data['include_review_details']) {
                $detailSheet = $writer->addNewSheetAndMakeItCurrent()->setName('Review Details');
                $reviewOptions = $report->columnOptions();
                $this->prepareSheet($writer, $detailSheet, [
                    'Submission ID', 'Submission Title',
                    ...array_map(fn ($column) => $reviewOptions[$column], $data['review_columns']),
                ]);
            }

            $submissions = Submission::query()
                ->where('scheduled_conference_id', app()->getCurrentScheduledConferenceId())
                ->with([
                    'meta',
                    'participants',
                    'authors' => fn ($query) => $query->ordered(),
                    'editors.user',
                    'user.meta',
                    'topics',
                    'track',
                    'reviews' => fn ($query) => $report->applyFilters($query->getQuery(), $data)
                        ->select('reviews.*')->with(['user', 'meta', 'reviewRound', 'media'])
                        ->orderBy('reviews.review_round_id')->orderBy('reviews.id'),
                ])
                ->whereIn('status', $data['status'])
                ->withAvg(['reviews' => fn ($query) => $report->applyFilters($query, $data)
                    ->where('reviews.status', ReviewerStatus::ACCEPTED)
                    ->whereNotNull('reviews.date_completed')], 'score')
                ->orderBy('reviews_avg_score', 'desc')
                ->orderBy('submissions.id')
                ->lazy();

            foreach ($submissions as $submission) {
                $canReadReviews = $report->canRead($submission);
                $rowData = array_map(fn ($column) => $this->getReportColumn($submission, $column, $canReadReviews), $summaryColumns);
                if ($includeReviewers) {
                    $reviewerData = [];
                    if ($canReadReviews) {
                        foreach ($submission->reviews as $review) {
                            $reviewerData[] = $report->value($review, 'reviewer_name');
                            $reviewerData[] = $report->value($review, 'score');
                        }
                    }
                    array_push($rowData, ...array_pad($reviewerData, $maxReviewers * 2, null));
                }
                $writer->setCurrentSheet($summarySheet);
                $writer->addRow($this->makeReportRow($rowData));

                if ($detailSheet && $canReadReviews) {
                    $writer->setCurrentSheet($detailSheet);
                    foreach ($submission->reviews as $review) {
                        $writer->addRow($this->makeReportRow([
                            $submission->getKey(), $submission->getMeta('title'),
                            ...array_map(fn ($column) => $report->value($review, $column), $data['review_columns']),
                        ]));
                    }
                }
            }

            foreach (array_filter([$summarySheet, $detailSheet]) as $sheet) {
                $sheet->setAutoFilter(new AutoFilter(0, 1, $sheet === $summarySheet ? count($headers) - 1 : count($data['review_columns']) + 1, max(1, $sheet->getWrittenRowCount())));
            }
        } finally {
            $writer->close();
        }
    }

    protected function prepareSheet(Writer $writer, Sheet $sheet, array $headers): void
    {
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach (array_values($headers) as $index => $header) {
            $long = Str::contains(strtolower($header), ['title', 'abstract', 'comments', 'review form:']);
            $sheet->setColumnWidth($long ? 60 : 24, $index + 1);
        }
        $header = $this->makeReportRow($headers);
        $header->setStyle((new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText());
        $header->setHeight(32);
        $writer->addRow($header);
    }

    protected function getReportColumn(Submission $submission, $column, bool $canReadReviews = false)
    {
        if (! $canReadReviews && in_array($column, ['reviewers', 'average_score', 'review_count', 'completed_review_count'], true)) {
            return null;
        }
        if (($formItemId = $this->getSubmissionFormResponseId($column)) !== null) {
            $responses = $submission->getMeta('submission_form_responses', []);
            $response = is_array($responses) ? ($responses[$formItemId] ?? null) : null;

            return $this->escapeSpreadsheetFormula(
                is_array($response) ? implode(', ', $response) : $response
            );
        }

        $authorCorrespondanceNameFn = function (Submission $submission) {
            $author = Author::find($submission->getMeta('primary_contact_id'));

            return $author ? Str::squish($author->given_name . ' ' . $author->family_name) : Str::squish($submission->user->given_name . ' ' . $submission->user->family_name);
        };

        return match ($column) {
            'id' => $submission->getKey(),
            'authors' => $submission->authors->implode(fn(Author $author) => Str::squish($author->given_name . ' ' . $author->family_name), ', '),
            'editors' => $submission->editors->implode(fn($editor) => Str::squish($editor->user->given_name . ' ' . $editor->user->family_name), ', '),
            'reviewers' => $submission->reviews->implode(fn($review) => Str::squish($review->user->given_name . ' ' . $review->user->family_name), ', '),
            'submitter_name' => Str::squish($submission->user->given_name . ' ' . $submission->user->family_name),
            'submitter_email' =>  $submission->user->email,
            'submitter_affiliation' => $submission->user->getMeta('affiliation'),
            'submitter_country_id' => $submission->user->getMeta('country'),
            'submitter_country' =>  $submission->user->getMeta('country') ? Country::where('id', $submission->user->getMeta('country', null))?->value('name') : null,
            'submitter_phone' =>  $submission->user->getMeta('phone'),
            'correspondance_author_name' => $authorCorrespondanceNameFn($submission),
            'correspondance_author_email' => Author::find($submission->getMeta('primary_contact_id'))?->email ?? $submission->user->email,
            'title' => $submission->getMeta('title'),
            'status' => $submission->status?->value,
            'track' => $submission->track?->title,
            'keywords' => implode(", ", $submission->getMeta('keywords') ?? []),
            'topics' =>  $submission->topics->implode(fn(Topic $topic) => $topic->name, ','),
            'abstract' => html_entity_decode(strip_tags($submission->getMeta('abstract'))),
            'average_score' => $submission->reviews_avg_score !== null ? round($submission->reviews_avg_score, 2) : null,
            'review_count' => $submission->reviews->count(),
            'completed_review_count' => $submission->reviews->filter(fn ($review) => $this->report()->isCompleted($review))->count(),
            default => null,
        };
    }

    public function getReportColumnOptions(): array
    {
        return array_merge(
            static::$options,
            SubmissionFormItem::query()
                ->where('scheduled_conference_id', app()->getCurrentScheduledConferenceId())
                ->where('type', '!=', SubmissionFormItem::TYPE_UPLOAD)
                ->ordered()
                ->with('meta')
                ->get()
                ->mapWithKeys(fn (SubmissionFormItem $item) => [
                    $this->getSubmissionFormResponseColumn($item->getKey()) => $item->getMeta('name'),
                ])
                ->all()
        );
    }

    protected function getReportColumnHeader(string $column, array $columnOptions = []): string
    {
        if ($this->getSubmissionFormResponseId($column) === null) {
            return $column;
        }

        return $this->escapeSpreadsheetFormula(
            $columnOptions[$column] ?? $this->getReportColumnOptions()[$column] ?? $column
        );
    }

    protected function escapeSpreadsheetFormula(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@'], true) ? "'{$value}" : $value;
    }

    protected function makeReportRow(array $values): Row
    {
        return new Row(array_map(
            // Explicit string cells keep reviewer text such as =SUM(...) literal.
            fn ($value) => is_string($value) || $value === null ? new StringCell($value ?? '', null) : Cell::fromValue($value),
            $values
        ));
    }

    protected function getSubmissionFormResponseColumn(int $formItemId): string
    {
        return 'submission_form_response_'.$formItemId;
    }

    protected function getSubmissionFormResponseId(string $column): ?int
    {
        $prefix = 'submission_form_response_';

        if (! Str::startsWith($column, $prefix)) {
            return null;
        }

        $formItemId = Str::after($column, $prefix);

        return ctype_digit($formItemId) && (int) $formItemId > 0 ? (int) $formItemId : null;
    }
}
