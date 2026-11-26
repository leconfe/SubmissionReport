<?php

namespace SubmissionReport\Services;

use App\Constants\ReviewerStatus;
use App\Models\Review;
use App\Models\ReviewFormItem;
use App\Models\Submission;
use App\Models\SubmissionReviewRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReviewReport
{
    protected ?Collection $formItems = null;

    public function __construct(protected int $scheduledConferenceId) {}

    public function roundOptions(): array
    {
        $options = [
            'effective' => 'Latest assignment per reviewer (Review Result)',
            'latest_round' => 'Latest round of each submission',
            'all' => 'All rounds',
        ];

        foreach (SubmissionReviewRound::query()
            ->whereHas('submission', fn (Builder $query) => $query->where('scheduled_conference_id', $this->scheduledConferenceId))
            ->distinct()->orderBy('round_number')->pluck('round_number') as $number) {
            $options['round_'.$number] = 'Round '.$number;
        }

        return $options;
    }

    public function formItems(): Collection
    {
        return $this->formItems ??= ReviewFormItem::query()
            ->where('scheduled_conference_id', $this->scheduledConferenceId)
            ->with('meta')->ordered()->get()->keyBy('id');
    }

    public function columnOptions(): array
    {
        return array_merge([
            'review_id' => 'Review ID',
            'reviewer_name' => 'Reviewer Name',
            'reviewer_email' => 'Reviewer Email',
            'round_number' => 'Round Number',
            'round_name' => 'Round Name',
            'assignment_status' => 'Assignment Status',
            'completion_status' => 'Review Status',
            'score' => 'Score',
            'recommendation' => 'Recommendation',
            'date_completed' => 'Completed At',
            'review_for_author_editor' => 'Comments for Author and Editor',
            'review_for_editor' => 'Comments for Editor Only',
        ], $this->formItems()->mapWithKeys(fn (ReviewFormItem $item) => [
            'review_response_'.$item->getKey() => 'Review Form: '.$item->label,
        ])->all());
    }

    public function applyFilters(Builder $query, array $data): Builder
    {
        $query->whereHas('submission', fn (Builder $submission) => $submission
            ->where('scheduled_conference_id', $this->scheduledConferenceId));

        $round = $data['review_round'] ?? 'effective';
        if ($round === 'effective') {
            // A newer canceled/declined assignment also supersedes the old review,
            // just as it does on the core Review Result page.
            $query->leftJoin('submission_review_rounds as report_rounds', 'report_rounds.id', '=', 'reviews.review_round_id')
                ->whereIn('reviews.status', [ReviewerStatus::PENDING, ReviewerStatus::ACCEPTED])
                ->whereNotExists(function ($newer) {
                    $newer->selectRaw('1')->from('reviews as report_newer_reviews')
                        ->leftJoin('submission_review_rounds as report_newer_rounds', 'report_newer_rounds.id', '=', 'report_newer_reviews.review_round_id')
                        ->whereColumn('report_newer_reviews.submission_id', 'reviews.submission_id')
                        ->whereColumn('report_newer_reviews.user_id', 'reviews.user_id')
                        ->where(function ($newer) {
                            $newer->whereRaw('COALESCE(report_newer_rounds.round_number, 0) > COALESCE(report_rounds.round_number, 0)')
                                ->orWhere(function ($sameRound) {
                                    $sameRound->whereRaw('COALESCE(report_newer_rounds.round_number, 0) = COALESCE(report_rounds.round_number, 0)')
                                        ->whereColumn('report_newer_reviews.id', '>', 'reviews.id');
                                });
                        });
                });
        } elseif ($round === 'latest_round') {
            $query->where('reviews.review_round_id', function ($latest) {
                $latest->select('id')->from('submission_review_rounds')
                    ->whereColumn('submission_id', 'reviews.submission_id')
                    ->orderByDesc('round_number')->orderByDesc('id')->limit(1);
            });
        } elseif (preg_match('/^round_([1-9][0-9]*)$/', $round, $matches)) {
            $query->whereHas('reviewRound', fn (Builder $roundQuery) => $roundQuery->where('round_number', (int) $matches[1]));
        }

        if ($data['completed_reviews_only'] ?? true) {
            $query->where('reviews.status', ReviewerStatus::ACCEPTED)->whereNotNull('reviews.date_completed');
        }

        return $query;
    }

    public function canRead(Submission $submission): bool
    {
        return (int) $submission->scheduled_conference_id === $this->scheduledConferenceId
            && (auth()->user()?->can('actAsEditor', $submission) ?? false);
    }

    public function isCompleted(Review $review): bool
    {
        return $review->status === ReviewerStatus::ACCEPTED && $review->date_completed !== null;
    }

    public function value(Review $review, string $column): mixed
    {
        $completed = $this->isCompleted($review);

        if (Str::startsWith($column, 'review_response_')) {
            $item = $this->formItems()->get((int) Str::after($column, 'review_response_'));
            if (! $completed || ! $item) {
                return null;
            }

            $responses = $review->getMeta('review_responses', []);
            $response = is_array($responses) ? ($responses[$item->getKey()] ?? null) : null;
            if ($item->isUploadType()) {
                return $item->getContentFromValue(null, $review);
            }
            if ($response === null) {
                return null;
            }
            if ($item->type === ReviewFormItem::TYPE_CHECKBOX && ! is_array($response)) {
                $response = [$response];
            } elseif (is_array($response) && $item->type !== ReviewFormItem::TYPE_CHECKBOX) {
                return implode(', ', array_filter($response, 'is_scalar'));
            }

            return $item->getContentFromValue($response, $review);
        }

        return match ($column) {
            'review_id' => $review->getKey(),
            'reviewer_name' => $review->user ? Str::squish($review->user->given_name.' '.$review->user->family_name) : null,
            'reviewer_email' => $review->user?->email,
            'round_number' => $review->reviewRound?->round_number,
            'round_name' => $review->reviewRound?->name,
            'assignment_status' => $review->status,
            'completion_status' => $completed ? 'Completed' : match ($review->status) {
                ReviewerStatus::ACCEPTED => 'In progress',
                default => $review->status,
            },
            'date_completed' => $review->date_completed?->format('Y-m-d H:i:s'),
            // Never export an unfinished reviewer's draft answers or comments.
            'score' => $completed ? $review->score : null,
            'recommendation' => $completed ? $review->recommendation : null,
            'review_for_author_editor', 'review_for_editor' => $completed ? $this->plainText($review->getMeta($column)) : null,
            default => null,
        };
    }

    public function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6]|tr|blockquote)>#i', "\n", $html);

        return trim(str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
