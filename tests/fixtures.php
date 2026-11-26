<?php

// Isolated integration fixtures. Never connect to the installed database.
foreach ([
    'APP_ENV' => 'testing', 'APP_INSTALLED' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/submission-report-no-config-'.getmypid().'.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null', 'MODEL_CACHE_ENABLED' => 'false',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$root = getenv('LECONFE_TEST_APP_PATH') ?: (getenv('LECONFE_APP_PATH') ?: dirname(__DIR__, 3));
require $root.'/vendor/autoload.php';
$testPlugin = require dirname(__DIR__).'/index.php';
$app = require $root.'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('http://localhost'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});

use App\Models\Review;
use App\Models\ReviewFormItem;
use App\Models\Submission;
use App\Models\SubmissionFormItem;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'laravel-model-caching.enabled' => false]);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
    throw new RuntimeException('Refusing to create fixtures outside SQLite memory');
}

Schema::create('users', function (Blueprint $table): void {
    $table->id(); $table->string('given_name'); $table->string('family_name')->nullable();
    $table->string('email'); $table->timestamps();
});
Schema::create('meta', function (Blueprint $table): void {
    $table->id(); $table->string('metable_type'); $table->unsignedBigInteger('metable_id');
    $table->string('type'); $table->string('key'); $table->longText('value');
    $table->unique(['metable_type', 'metable_id', 'key']);
});
Schema::create('scheduled_conferences', function (Blueprint $table): void {
    $table->id(); $table->integer('conference_id'); $table->string('title'); $table->softDeletes();
});
Schema::create('submissions', function (Blueprint $table): void {
    $table->id(); $table->integer('conference_id'); $table->integer('scheduled_conference_id');
    $table->integer('user_id'); $table->integer('track_id')->nullable();
    $table->string('status'); $table->string('stage'); $table->timestamps();
});
Schema::create('submission_review_rounds', function (Blueprint $table): void {
    $table->id(); $table->integer('submission_id'); $table->integer('round_number');
    $table->string('name')->nullable(); $table->string('status'); $table->timestamps();
});
Schema::create('reviews', function (Blueprint $table): void {
    $table->id(); $table->integer('submission_id'); $table->integer('user_id')->nullable();
    $table->integer('review_round_id')->nullable(); $table->string('status');
    $table->double('score')->nullable(); $table->string('recommendation')->nullable();
    $table->timestamp('date_completed')->nullable(); $table->timestamps();
});
foreach (['submission_form_items', 'submission_review_form_items'] as $tableName) {
    Schema::create($tableName, function (Blueprint $table): void {
        $table->id(); $table->integer('scheduled_conference_id'); $table->string('label')->nullable();
        $table->integer('type'); $table->integer('order_column'); $table->double('weight')->nullable(); $table->timestamps();
    });
}
Schema::create('submission_has_participants', function (Blueprint $table): void {
    $table->id(); $table->integer('submission_id'); $table->integer('user_id'); $table->integer('role_id');
});
Schema::create('roles', function (Blueprint $table): void {
    $table->id(); $table->string('name'); $table->string('guard_name')->default('web');
    $table->integer('conference_id')->default(7); $table->integer('scheduled_conference_id')->default(101);
});
Schema::create('authors', function (Blueprint $table): void {
    $table->id(); $table->integer('submission_id'); $table->string('given_name');
    $table->string('family_name')->nullable(); $table->string('email')->nullable(); $table->integer('order_column');
});
Schema::create('tracks', function (Blueprint $table): void {
    $table->id(); $table->string('title'); $table->integer('conference_id'); $table->integer('scheduled_conference_id');
});
Schema::create('topics', function (Blueprint $table): void {
    $table->id(); $table->string('name'); $table->integer('conference_id'); $table->integer('scheduled_conference_id');
});
Schema::create('topicables', function (Blueprint $table): void {
    $table->integer('topic_id'); $table->integer('topicable_id'); $table->string('topicable_type');
});
Schema::create('media', function (Blueprint $table): void {
    $table->id(); $table->string('model_type'); $table->integer('model_id'); $table->string('collection_name');
    $table->string('name'); $table->string('file_name'); $table->integer('order_column')->default(1);
    $table->text('custom_properties')->default('{}'); $table->text('generated_conversions')->default('{}');
    $table->text('responsive_images')->default('{}'); $table->timestamps();
});

// Use the real submission policy, with explicit fixture permissions.
class ReportTestUser extends User
{
    public bool $pluginAccess = true;
    public bool $canSubmitAs = true;

    public function can($abilities, $arguments = []): bool
    {
        return match ($abilities) {
            'Plugin:viewAny' => $this->pluginAccess,
            'submitAs' => $this->canSubmitAs,
            'actAsEditor' => (bool) (new SubmissionPolicy)->actAsEditor($this, $arguments),
            default => false,
        };
    }
}

app()->setCurrentConferenceId(7);
app()->setCurrentScheduledConferenceId(101);
DB::table('scheduled_conferences')->insert([
    ['id' => 101, 'conference_id' => 7, 'title' => 'Fixture Conference'],
    ['id' => 201, 'conference_id' => 8, 'title' => 'Other Conference'],
]);
for ($id = 1; $id <= 10; $id++) {
    DB::table('users')->insert(['id' => $id, 'given_name' => $id === 2 ? '=Reviewer' : 'User '.$id, 'family_name' => 'Fixture', 'email' => 'user'.$id.'@example.test']);
}
foreach ([501 => [101, 'On Review'], 502 => [101, 'On Review'], 503 => [101, 'On Review'], 504 => [101, 'On Review'], 505 => [101, 'Published'], 601 => [201, 'On Review']] as $id => [$scheduled, $status]) {
    DB::table('submissions')->insert(['id' => $id, 'conference_id' => $scheduled === 101 ? 7 : 8, 'scheduled_conference_id' => $scheduled, 'user_id' => 1, 'status' => $status, 'stage' => 'Peer Review']);
    Submission::withoutGlobalScopes()->findOrFail($id)->setManyMeta(['title' => $id === 501 ? '=Literal submission title' : 'Submission '.$id, 'abstract' => '<p>Fixture abstract</p>', 'submission_form_responses' => [51 => 'Submission answer']]);
}
foreach ([11 => [501, 1], 12 => [501, 2], 30 => [503, 1], 40 => [504, 1], 50 => [505, 1], 99 => [601, 9]] as $id => [$submission, $number]) {
    DB::table('submission_review_rounds')->insert(['id' => $id, 'submission_id' => $submission, 'round_number' => $number, 'name' => 'Round '.$number, 'status' => $id === 11 ? 'Closed' : 'Open']);
}
foreach ([
    [1, 501, 11, 2, 'Accepted', 40, true],
    [2, 501, 12, 2, 'Accepted', 90, true],
    [3, 501, 11, 3, 'Accepted', 80, true],
    [4, 501, 12, 4, 'Accepted', 100, false],
    [5, 501, 11, 5, 'Accepted', 5, true],
    [6, 501, 12, 5, 'Canceled', 1, true],
    [7, 501, 12, 6, 'Pending', null, false],
    [8, 501, 12, 7, 'Declined', null, false],
    [9, 501, 12, 8, 'Accepted', 70, true],
    [10, 501, 12, 8, 'Accepted', 60, true],
    [30, 503, 30, 2, 'Accepted', 0, true],
    [40, 504, 40, 2, 'Accepted', 100, true],
    [50, 505, 50, 2, 'Accepted', 95, true],
    [99, 601, 99, 2, 'Accepted', 10, true],
] as [$id, $submission, $round, $user, $status, $score, $complete]) {
    DB::table('reviews')->insert(['id' => $id, 'submission_id' => $submission, 'review_round_id' => $round, 'user_id' => $user, 'status' => $status, 'score' => $score, 'recommendation' => 'Accept', 'date_completed' => $complete ? '2026-11-25 10:00:00' : null]);
    Review::findOrFail($id)->setManyMeta(['review_for_author_editor' => '<p>Review '.$id.'</p>', 'review_for_editor' => '<p>Editor secret '.$id.'</p>']);
}
Review::findOrFail(2)->setManyMeta([
    'review_for_author_editor' => '<p>=SUM(1,2)</p><p>Perbaiki &amp; lengkapi<br>referensi.</p>',
    'review_for_editor' => '<p>Editor secret 2</p>',
    'review_responses' => [101 => '=Literal answer', 102 => 0, 103 => ['a', 'b'], 104 => 'yes', 106 => "Paragraph one\nParagraph two"],
]);
Review::findOrFail(4)->setManyMeta(['review_for_author_editor' => 'UNSUBMITTED DRAFT', 'review_for_editor' => 'PRIVATE DRAFT', 'review_responses' => [101 => 'DRAFT RESPONSE']]);
foreach ([101 => [1, 'Originality'], 102 => [5, 'Quality'], 103 => [3, 'Criteria'], 104 => [4, 'Ethics'], 105 => [6, 'Annotated file'], 106 => [2, 'Detailed assessment'], 201 => [1, 'Other conference question']] as $id => [$type, $label]) {
    DB::table('submission_review_form_items')->insert(['id' => $id, 'scheduled_conference_id' => $id === 201 ? 201 : 101, 'label' => $label, 'type' => $type, 'order_column' => $id]);
}
ReviewFormItem::findOrFail(102)->setMeta('select_options', [['value' => 0, 'label' => 'Needs work'], ['value' => 1, 'label' => 'Good']]);
ReviewFormItem::findOrFail(103)->setMeta('checkbox_options', ['a' => 'Method', 'b' => 'Results']);
ReviewFormItem::findOrFail(104)->setMeta('radio_options', ['yes' => 'Compliant', 'no' => 'Not compliant']);
DB::table('submission_form_items')->insert(['id' => 51, 'scheduled_conference_id' => 101, 'type' => 1, 'order_column' => 1]);
SubmissionFormItem::findOrFail(51)->setMeta('name', 'Additional submission question');
DB::table('media')->insert(['model_type' => (new Review)->getMorphClass(), 'model_id' => 2, 'collection_name' => 'meta.review_responses.105', 'name' => 'annotated-paper', 'file_name' => 'annotated-paper.pdf']);
DB::table('roles')->insert([
    ['id' => 1, 'name' => App\Models\Enums\UserRole::Author->value],
    ['id' => 2, 'name' => App\Models\Enums\UserRole::ScheduledConferenceEditor->value],
]);
DB::table('submission_has_participants')->insert([
    ['submission_id' => 504, 'user_id' => 9, 'role_id' => 1],
    ['submission_id' => 501, 'user_id' => 10, 'role_id' => 2],
    ['submission_id' => 503, 'user_id' => 10, 'role_id' => 2],
]);
$reportUser = new ReportTestUser;
$reportUser->forceFill(['id' => 9, 'given_name' => 'Report Manager', 'email' => 'manager@example.test']);
auth()->setUser($reportUser);
