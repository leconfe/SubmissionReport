<?php

namespace SubmissionReport\Pages;

use App\Models\Author;
use App\Models\Enums\SubmissionStatus;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Topic;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
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
    ];

    public array $formData = [];

    public function mount(): void
    {
        $this->form->fill([
            'columns' => array_keys(static::$options),
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
                    ->options(static::$options)
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
            now()->timestamp,
        ]);
        $filename = Storage::disk('private-files')->path(auth()->user()->id . $name . '.xlsx');

        $columns = $data['columns'];
        $isReviewersIncluded = in_array('reviewers', $columns);
        if ($isReviewersIncluded) {
            $maxReviewers = DB::table('reviews')
                ->selectRaw('submission_id, COUNT(*) as count')
                ->groupBy('submission_id')
                ->orderBy('count', 'desc')
                ->first();

            for ($i = 1; $i <= $maxReviewers->count; $i++) {
                $columns[] = 'reviewer_' . $i . '_name';
                $columns[] = 'reviewer_' . $i . '_score';
            }

            // Remove 'reviewers' from columns as we will handle it differently
            $columns = array_filter($columns, fn($column) => $column !== 'reviewers');
            $data['columns']  = array_filter($data['columns'], fn($column) => $column !== 'reviewers');
        }


        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($filename);

        $writer->addRow(Row::fromValues($columns));

        $submissions = Submission::query()
            ->with([
                'meta',
                'participants',
                'authors' => fn($query) => $query->ordered(),
                'editors.user',
                'user',
                'topics',
                'track',
                'reviews.user',
            ])
            ->when($data['status'], fn($query) => $query->whereIn('status', $data['status']))
            ->withAvg(['reviews' => fn($query) => $query->whereNotNull('date_completed')], 'score')
            ->orderBy('reviews_avg_score', 'desc')
            ->lazy();


        foreach ($submissions as $submission) {
            $rowData = [];
            foreach ($data['columns'] as $column) {
                $rowData[] = $this->getReportColumn($submission, $column);
            }
            if($isReviewersIncluded) { 
                foreach ($submission->reviews as $review) {
                    $rowData[] = Str::squish($review->user->given_name . ' ' . $review->user->family_name);
                    $rowData[] = $review->score;
                }
            }

            $writer->addRow(Row::fromValues($rowData));
        }


        $writer->close();

        $csv = file_get_contents($filename);

        unlink($filename);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $name . '.xlsx');
    }

    protected function getReportColumn(Submission $submission, $column)
    {
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
            'average_score' => $submission->reviews_avg_score ? round($submission->reviews_avg_score, 1) : null,
            default => null,
        };
    }
}
