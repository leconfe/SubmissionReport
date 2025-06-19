<?php

namespace SubmissionReport\Pages;

use App\Models\Author;
use App\Models\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Topic;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use OpenSpout\Common\Entity\Row;
use Squire\Models\Country;
use Illuminate\Support\Str;

class SubmissionReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Submission Report';

    protected static string $view = 'SubmissionReport::submission-report';

    protected static bool $shouldRegisterNavigation = false;

    public array $formData = [];

    public function mount(): void
    {
        $this->form->fill([
            'columns' => [
                'id',
                'authors',
                'editors',
                'reviews',
                'submitter_name',
                'submitter_email',
                'submitter_affiliation',
                'submitter_country_id',
                'submitter_country',
                'title',
                'status',
                'keywords',
                'topics',
                'abstract',
                'review_score',
            ]
        ]);
    }

    public static function getRoutePath(): string
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                CheckboxList::make('status')
                    ->label('Select Submission Status that you want to export')
                    ->options(collect(array_combine(SubmissionStatus::values(), SubmissionStatus::values()))->filter(fn($value) => !in_array($value, ['Payment Declined', 'On Payment'])))
                    ->bulkToggleable()
                    ->required(),
                CheckboxList::make('columns')
                    ->required()
                    ->label('Select Columns to be exported')
                    ->options([
                        'id' => 'ID',
                        'authors' => 'Authors',
                        'editors' => "Editors",
                        'reviews' => "Reviewers",
                        'submitter_name' => 'Submitter Name',
                        'submitter_email' => 'Submitter Email',
                        'submitter_affiliation' => 'Submitter Affiliation',
                        'submitter_country' => 'Submitter Country',
                        'title' => "Submission Title",
                        'status' => "Submission Status",
                        'keywords' => "Keywords",
                        'topics' => "Topics",
                        'abstract' => "Abstract",
                        'review_score' => "Review Score",
                    ])
                    ->bulkToggleable()

            ])
            ->statePath('formData');
    }

    public function submit()
    {
        $data = $this->form->getState();

        $name = implode('-', [
            'submissions',
            app()->getCurrentScheduledConference()->getKey(),
            now()->format('Ymd'),
        ]);
        $filename = Storage::disk('private-files')->path(auth()->user()->id . $name . '.xlsx');

        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($filename);

        $writer->addRow(Row::fromValues($data['columns']));

        Submission::query()
            ->with([
                'meta',
                'participants',
                'authors',
                'editors.user',
                'user',
                'topics',
            ])
            ->when($data['status'], fn($query) => $query->whereIn('status', $data['status']))
            ->withAvg(['reviews' => fn($query) => $query->whereNotNull('date_completed')], 'score')
            ->orderBy('reviews_avg_score', 'desc')
            ->lazy()
            ->each(fn(Submission $submission) => $writer->addRow(Row::fromValues(collect($data['columns'])->map(fn($column) => $this->getReportColumn($submission, $column))->toArray())));

        $writer->close();

        $csv = file_get_contents($filename);

        unlink($filename);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $name . '.xlsx');
    }

    protected function getReportColumn(Submission $submission, $column)
    {
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
            'title' => $submission->getMeta('title'),
            'status' => $submission->status?->value,
            'keywords' => implode(", ", $submission->getMeta('keywords') ?? []),
            'topics' =>  $submission->topics->implode(fn(Topic $topic) => $topic->name, ','),
            'abstract' => html_entity_decode(strip_tags($submission->getMeta('abstract'))),
            'review_score' => $submission->reviews_avg_score ? round($submission->reviews_avg_score, 1) : null,
            default => null,
        };
    }
}
