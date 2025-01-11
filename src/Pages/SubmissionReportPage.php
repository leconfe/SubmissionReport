<?php

namespace SubmissionReport\Pages;

use App\Actions\Submissions\SubmissionCreateAction;
use App\Actions\Submissions\SubmissionUpdateAction;
use App\Forms\Components\TinyEditor;
use App\Models\Author;
use App\Models\Enums\SubmissionStage;
use App\Models\Enums\SubmissionStatus;
use App\Models\Proceeding;
use App\Models\Submission;
use App\Models\Topic;
use App\Models\Track;
use App\Panel\ScheduledConference\Livewire\Submissions\Components\ContributorList;
use App\Panel\ScheduledConference\Livewire\Submissions\Components\GalleyList;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Livewire;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use OpenSpout\Common\Entity\Row;
use Squire\Models\Country;

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
                    ->options(array_combine(SubmissionStatus::values(), SubmissionStatus::values()))
                    ->bulkToggleable()
                    ->required(),
                CheckboxList::make('columns')
                    ->required()
                    ->label('Select Columns to be exported')
                    ->options([
                        'id' => 'ID',
                        'authors' => 'Authors',
                        'submitter_name' => 'Submitter Name',
                        'submitter_email' => 'Submitter Email',
                        'submitter_affiliation' => 'Submitter Affiliation',
                        // 'submitter_country_id' => 'Submitter Country ID',
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

        $report = Submission::query()
            ->with([
                'meta',
                'participants',
                'authors',
                'user',
                'topics',
            ])
            ->when($data['status'], fn($query) => $query->whereIn('status', $data['status']))
            ->withAvg(['reviews' => fn($query) => $query->whereNotNull('date_completed')], 'score')
            ->orderBy('reviews_avg_score', 'desc')
            ->lazy()
            ->map(fn(Submission $submission) => collect($data['columns'])->map(fn($column) => $this->getReportColumn($submission, $column))->toArray());


        $filename = Storage::disk('private-files')->path(auth()->user()->id . '_submission_export.xlsx');

        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($filename);

        $writer->addRow(Row::fromValues($data['columns']));

        $report->each(fn($data) => $writer->addRow(Row::fromValues($data)));

        $writer->close();

        $csv = file_get_contents($filename);

        unlink($filename);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'submissions-' . now()->format('Ymd') . '.xlsx');
    }

    protected function getReportColumn(Submission $submission, $column){
        return match($column){
            'id' => $submission->getKey(),
            'authors' => $submission->authors->implode(fn(Author $author) => $author->full_name, ', '),
            'submitter_name' => $submission->user->full_name,
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
