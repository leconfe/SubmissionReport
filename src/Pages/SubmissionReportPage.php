<?php

namespace SubmissionReport\Pages;

use App\Models\Author;
use App\Models\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionFormItem;
use App\Models\Topic;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use Squire\Models\Country;

class SubmissionReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Submission Report';

    public function getView(): string
    {
        return 'SubmissionReport::submission-report';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-chart-bar';
    }

    protected static array $options = [
        'id' => 'ID',
        'authors' => 'Authors',
        'editors' => 'Editors',
        'reviewers' => 'Reviewers',
        'submitter_name' => 'Submitter Name',
        'submitter_email' => 'Submitter Email',
        'submitter_affiliation' => 'Submitter Affiliation',
        'submitter_country' => 'Submitter Country',
        'submitter_phone' => 'Submitter Phone',
        'correspondance_author_name' => 'Correspondance Author Name',
        'correspondance_author_email' => 'Correspondance Author Email',
        'title' => 'Submission Title',
        'status' => 'Submission Status',
        'track' => 'Track',
        'keywords' => 'Keywords',
        'topics' => 'Topics',
        'abstract' => 'Abstract',
        'average_score' => 'Average Score',
    ];

    public array $formData = [];

    public function mount(): void
    {
        $this->form->fill([
            'columns' => array_keys($this->getReportColumnOptions()),
        ]);
    }

    public static function getRoutePath(mixed $panel = null): string
    {
        return '/submission-report';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('update', app()->getCurrentScheduledConference());
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
        return new HtmlString(<<<'HTML'
            <p class="text-sm text-gray-500"></p>
        HTML);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                CheckboxList::make('status')
                    ->label('Select Submission Status that you want to export')
                    ->options(function () {
                        return collect(array_combine(SubmissionStatus::values(), SubmissionStatus::values()))
                            ->filter(fn ($value) => ! in_array($value, ['Payment Declined', 'On Payment'], true))
                            ->all();
                    })
                    ->bulkToggleable()
                    ->required(),
                CheckboxList::make('columns')
                    ->required()
                    ->label('Select Columns to be exported')
                    ->options($this->getReportColumnOptions())
                    ->bulkToggleable(),
            ])
            ->statePath('formData');
    }

    public function submit()
    {
        return $this->export($this->form->getState());
    }

    protected function export(array $data)
    {
        $name = implode('-', [
            'submissions',
            app()->getCurrentScheduledConference()->getKey(),
            now()->timestamp,
        ]);
        $filename = Storage::disk('private-files')->path(auth()->user()->id.$name.'.xlsx');

        try {
            $columnOptions = $this->getReportColumnOptions();
            $data['columns'] = array_values(array_filter(
                $data['columns'],
                fn ($column) => is_string($column) && array_key_exists($column, $columnOptions)
            ));
            $columns = $data['columns'];
            $isReviewersIncluded = in_array('reviewers', $columns);
            if ($isReviewersIncluded) {
                $maxReviewers = DB::table('reviews')
                    ->selectRaw('submission_id, COUNT(*) as count')
                    ->groupBy('submission_id')
                    ->orderBy('count', 'desc')
                    ->first();

                if ($maxReviewers && $maxReviewers->count > 0) {
                    for ($i = 1; $i <= $maxReviewers->count; $i++) {
                        $columns[] = 'reviewer_'.$i.'_name';
                        $columns[] = 'reviewer_'.$i.'_score';
                    }
                }

                // Remove 'reviewers' from columns as we will handle it differently
                $columns = array_filter($columns, fn ($column) => $column !== 'reviewers');
                $data['columns'] = array_filter($data['columns'], fn ($column) => $column !== 'reviewers');
            }

            $writer = new \OpenSpout\Writer\XLSX\Writer;
            $writer->openToFile($filename);

            $writer->addRow(Row::fromValues(array_map(
                fn (string $column) => $this->escapeSpreadsheetFormula($this->getReportColumnHeader($column, $columnOptions)),
                $columns
            )));

            $submissions = Submission::query()
                ->with([
                    'meta',
                    'participants',
                    'authors' => fn ($query) => $query->ordered(),
                    'editors.user',
                    'user',
                    'topics',
                    'track',
                    'reviews.user',
                ])
                ->when($data['status'], fn ($query) => $query->whereIn('status', $data['status']))
                ->withAvg(['reviews' => fn ($query) => $query->whereNotNull('date_completed')], 'score')
                ->orderBy('reviews_avg_score', 'desc')
                ->lazy();

            foreach ($submissions as $submission) {
                $rowData = [];
                foreach ($data['columns'] as $column) {
                    $rowData[] = $this->getReportColumn($submission, $column);
                }
                if ($isReviewersIncluded) {
                    foreach ($submission->reviews as $review) {
                        $reviewerName = $review->user ? Str::squish($review->user->given_name.' '.$review->user->family_name) : '';
                        $rowData[] = $this->escapeSpreadsheetFormula($reviewerName);
                        $rowData[] = $this->escapeSpreadsheetFormula($review->score);
                    }
                }

                $writer->addRow($this->makeReportRow($rowData));
            }

            $writer->close();

            // Stream file directly to prevent memory exhaustion
            return response()->streamDownload(function () use ($filename) {
                if (file_exists($filename)) {
                    readfile($filename);
                    @unlink($filename);
                }
            }, $name.'.xlsx');

        } catch (\Throwable $th) {
            if (file_exists($filename)) {
                @unlink($filename);
            }
            throw $th;
        }
    }

    protected function getReportColumn(Submission $submission, $column)
    {
        if (($formItemId = $this->getSubmissionFormResponseId($column)) !== null) {
            $responses = $submission->getMeta('submission_form_responses', []);
            $response = is_array($responses) ? ($responses[$formItemId] ?? null) : null;

            return $this->escapeSpreadsheetFormula(
                is_array($response) ? implode(', ', $response) : $response
            );
        }

        $authorCorrespondanceNameFn = function (Submission $submission) {
            $author = Author::find($submission->getMeta('primary_contact_id'));

            return $author ? Str::squish($author->given_name.' '.$author->family_name) : Str::squish($submission->user->given_name.' '.$submission->user->family_name);
        };

        $rawVal = match ($column) {
            'id' => $submission->getKey(),
            'authors' => $submission->authors->implode(fn (Author $author) => Str::squish($author->given_name.' '.$author->family_name), ', '),
            'editors' => $submission->editors->implode(fn ($editor) => Str::squish($editor->user->given_name.' '.$editor->user->family_name), ', '),
            'reviewers' => $submission->reviews->implode(fn ($review) => Str::squish($review->user->given_name.' '.$review->user->family_name), ', '),
            'submitter_name' => Str::squish($submission->user->given_name.' '.$submission->user->family_name),
            'submitter_email' => $submission->user->email,
            'submitter_affiliation' => $submission->user->getMeta('affiliation'),
            'submitter_country_id' => $submission->user->getMeta('country'),
            'submitter_country' => $submission->user->getMeta('country') ? Country::where('id', $submission->user->getMeta('country', null))?->value('name') : null,
            'submitter_phone' => $submission->user->getMeta('phone'),
            'correspondance_author_name' => $authorCorrespondanceNameFn($submission),
            'correspondance_author_email' => Author::find($submission->getMeta('primary_contact_id'))?->email ?? $submission->user->email,
            'title' => $submission->getMeta('title'),
            'status' => is_object($submission->status) ? ($submission->status->value ?? $submission->status->name ?? (string) $submission->status) : (string) $submission->status,
            'track' => $submission->track?->title,
            'keywords' => implode(', ', $submission->getMeta('keywords') ?? []),
            'topics' => $submission->topics->implode(fn (Topic $topic) => $topic->name, ','),
            'abstract' => html_entity_decode(strip_tags($submission->getMeta('abstract'))),
            'average_score' => $submission->reviews_avg_score ? round($submission->reviews_avg_score, 1) : null,
            default => null,
        };

        return $this->escapeSpreadsheetFormula($rawVal);
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
            return $columnOptions[$column] ?? $column;
        }

        return $columnOptions[$column] ?? $this->getReportColumnOptions()[$column] ?? $column;
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
            fn ($value) => $value === null || $value === '' ? new StringCell('', null) : Cell::fromValue($value),
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
