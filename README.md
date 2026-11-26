# Submission Report 1.1.0

Export submission data and reviewer feedback from the scheduled conference's
**Submission Report** plugin page. This update is contained in the plugin and
does not require a core change or database migration.

## Usage

1. Open the plugin's Submission Report page and select submission statuses.
2. Select the columns for **Submission Summary** (one row per submission).
3. Choose the review rounds and whether to export completed reviews only.
4. Leave **Include Review Details sheet** enabled and select review columns.
5. Click **Export** to download the Excel workbook.

**Review Details** has one row per review assignment, identified by submission ID,
submission title, and the selected review fields. Available fields include the
reviewer, round, score, recommendation, completion date, comments for author and
editor, comments for editor only, and answers to the conference's review form.
Choice answers use their display labels. Upload answers list filenames; the files
themselves are not embedded. HTML comments are converted to text with line breaks.

Disable the detail sheet for a summary-only export. Existing submission fields
and custom submission form columns remain available. Reviewer name/score pairs
in the summary use the same review selection as the detail sheet.

## Review selection and scores

- **Latest assignment per reviewer (Review Result)** is the default. A reviewer's
  newer assignment supersedes their older one, including when the newer
  assignment was canceled or declined. With completed reviews only enabled,
  average scores match the core Review Result calculation for submissions shown
  on that page. The report's submission statuses are selected independently.
- **Latest round of each submission** selects the highest round number, including
  closed rounds.
- **All rounds** exports historical assignments.
- **Round N** selects that round number across the selected submissions.

**Completed reviews only** is enabled by default. Disable it to include assignment
history, subject to the chosen round selection. Unsubmitted scores,
recommendations, answers, and comments are never exported. Average scores always
use accepted assignments with a completion date. Canceled and declined
assignments are excluded from averages. Zero scores are retained.

**Exported Review Count** counts assignments matching the selected filters.
**Completed Review Count** counts completed, accepted assignments in that selection.

## Access and downloads

Access to the page requires the existing `Plugin:viewAny` permission. Review data,
including reviewer names, scores, and confidential comments, is exported only for
submissions on which the current user passes Leconfe's `actAsEditor` policy.
Other submissions can appear in the summary with review fields left blank.
Every export is explicitly scoped to the current scheduled conference and
rechecks permission on download.

The workbook uses literal text cells for user input, wrapped text, frozen headers,
and column filters. Temporary workbooks use unique private paths and are deleted
after download; failures also remove partial output. No extra PHP packages are
required beyond the OpenSpout library already provided by Leconfe.

## Compatibility and validation

This version targets the Leconfe installation with `SubmissionReviewRound`,
`reviews.review_round_id`, and the existing `actAsEditor` policy. Install the
complete plugin folder, including its Composer autoloader. No migration is added.

Run `composer test` in this directory, or from the Leconfe root:

```console
php plugins/SubmissionReport/tests/run.php
```

The standalone suite uses SQLite in memory and temporary fixture downloads. It
validates the actual Filament form and generated XLSX files, including round
selection, parity with Review Result, readable form answers, access restrictions,
empty results, zero scores, missing reviewer accounts, and literal text cells.
It does not require PHPUnit or access the installation's database.

The existing PHPUnit feature suite remains available with `composer test:phpunit`
when the plugin is installed under `plugins/SubmissionReport` in a Leconfe checkout
with development dependencies and the application's `Tests\\TestCase` installed.
For an external checkout, set `LECONFE_APP_PATH` for the bootstrap and invoke the
application's PHPUnit binary with this plugin's `phpunit.xml`. The standalone
suite also accepts `LECONFE_APP_PATH` or `LECONFE_TEST_APP_PATH`.
