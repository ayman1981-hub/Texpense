<?php
/**
 * index.php — dashboard for the ACTIVE trip.
 *
 * Money model (schema v3):
 *   Every expense has a total_amount and one expense_splits row per member who
 *   shares it, so an expense can be split among a subset of the trip.
 *
 *   balance = (expenses this member paid)
 *           + (settlements they sent) - (settlements they received)
 *           - (sum of their expense_splits)
 *
 *   positive -> the trip owes them, negative -> they owe the trip.
 *   The balances sum to exactly zero, because every split row belongs to an
 *   expense whose splits add up to its total.
 *
 * All of that arithmetic lives in lib.php's trip_financials(), in integer
 * paise, so this page and settle_up.php can never disagree.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

/* =========================================================================
   Flash message (Post/Redirect/GET). Reasons are filtered through a whitelist,
   so nothing a user sends is ever rendered back onto the page.
   ========================================================================= */
$noticeMessages = [
    'success'                 => 'Expense added successfully.',
    'success_deleted'         => 'Expense deleted, along with its split rows.',
    'success_settled'         => 'Settled up — the balance is cleared.',
    'success_trip_created'    => 'New trip created and opened.',
    'success_trip_switched'   => 'Switched trip.',
    'success_trip_deleted'    => 'Trip deleted — its members, expenses and settlements are all gone.',
    'desc_empty'              => 'Please enter a description.',
    'desc_short'              => 'The description must be at least 3 characters.',
    'desc_long'               => 'The description is too long (255 characters maximum).',
    'amount_empty'            => 'Please enter the amount that was paid.',
    'amount_format'           => 'Enter an amount like 450 or 450.50 — digits only, up to 2 decimal places.',
    'amount_range'            => 'The amount must be greater than 0 and within the allowed range.',
    'payer_invalid'           => 'Please choose who paid for this expense.',
    'payer_unknown'           => 'That member is not part of this trip — please pick another.',
    'payer'                   => 'That member is not part of this trip — please pick another.',
    'participants_empty'      => 'Choose at least one member to share this expense.',
    'participants_invalid'    => 'Those members are not part of this trip. Please pick again.',
    'split_failed'            => 'The amount could not be divided among those members. Please try again.',
    'csrf'                    => 'That request expired. Please try again.',
    'expense_invalid'         => 'That expense could not be identified.',
    'expense_missing'         => 'That expense no longer exists — it may already have been deleted.',
    'traveler_invalid'        => 'That member is not part of this trip.',
    'settle_nothing'          => 'Nothing to settle — that member does not owe anything.',
    'settle_no_creditors'     => 'Nobody is owed money in this trip, so there is nothing to settle against.',
    'trip_invalid'            => 'That trip could not be identified.',
    'trip_missing'            => 'That trip no longer exists.',
    'trip_not_settled'        => 'Settle every balance before deleting this trip.',
    'trip_name_empty'         => 'Please give the new trip a name.',
    'trip_name_long'          => 'Trip names must be 150 characters or fewer.',
    'member_empty'            => 'Add at least one member to the new trip.',
    'member_name_long'        => 'Member names must be 100 characters or fewer.',
    'member_too_many'         => 'That is more members than this app supports in one trip (60).',
    'member_duplicate'        => 'Each member needs a unique name — one of those is already on the trip.',
    'db'                      => 'Something went wrong while saving. Please try again.',
    'error'                   => 'That action could not be completed. Please check the form and try again.',
];

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$reason = is_string($_GET['reason'] ?? null) ? $_GET['reason'] : '';

$notice = '';
$noticeType = '';

if ($status === 'success') {
    $notice = $noticeMessages['success_' . $reason] ?? $noticeMessages['success'];
    $noticeType = 'notice--success';
} elseif ($status === 'error') {
    $notice = $noticeMessages[$reason] ?? $noticeMessages['error'];
    $noticeType = 'notice--error';
}

/* =========================================================================
   Data for the active trip
   ========================================================================= */
$trips       = [];
$activeTrip  = null;
$expenses    = [];
$settlements = [];

$financials = [
    'traveler_count'           => 0,
    'total_cents'              => 0,
    'average_per_person_cents' => 0,
    'expense_count'            => 0,
    'balances'                 => [],
];

try {
    $trips        = fetch_trips($pdo);
    $activeTripId = resolve_active_trip_id($pdo);

    if ($activeTripId !== null) {
        foreach ($trips as $trip) {
            if ((int) $trip['id'] === $activeTripId) {
                $activeTrip = $trip;
                break;
            }
        }

        // Every query below is trip-scoped: members, expenses, settlements and
        // balances all come from the active trip and nothing else.
        $financials  = trip_financials($pdo, $activeTripId);
        $expenses    = fetch_recent_expenses($pdo, $activeTripId, 10);
        $settlements = fetch_recent_settlements($pdo, $activeTripId, 5);
    }
} catch (PDOException $exception) {
    error_log('[travel_tracker] dashboard query failed: ' . $exception->getMessage());

    if ($notice === '') {
        $notice = 'We could not load your trip data just now. Please refresh the page.';
        $noticeType = 'notice--error';
    }
}

// A ?trip_id that named a deleted (or nonsense) trip was ignored above; send
// the user to a clean URL for the trip actually being displayed.
if (!empty($GLOBALS['trip_id_param_rejected']) && $activeTripId !== null && $notice === '') {
    redirect_to('index.php', ['trip_id' => $activeTripId]);
}

// The balance rows already carry id + name in alphabetical order, which is
// what the payer drop-down and the participant checkboxes need.
$members              = $financials['balances'];
$memberCount          = $financials['traveler_count'];
$totalCents           = $financials['total_cents'];
$averagePerPerson     = $financials['average_per_person_cents'];
$expenseCount         = $financials['expense_count'];
$balances             = $financials['balances'];
$tripName             = $activeTrip !== null ? (string) $activeTrip['name'] : '';
$hasTrip              = $activeTripId !== null;

// Deleting a trip is only offered once EVERY balance is exactly zero —
// destroy the ledger early and the record of who owed whom goes with it.
// delete_trip.php re-checks this server-side; this only decides what renders.
$unsettledMembers = 0;
foreach ($balances as $balance) {
    if ((int) $balance['balance_cents'] !== 0) {
        $unsettledMembers++;
    }
}
$allSettled = $unsettledMembers === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hasTrip ? e($tripName) . ' · ' : '' ?>Travel Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- ================= Header + trip switcher ================= -->
    <header class="site-header">
        <div class="site-header__inner site-header__inner--split">
            <div class="brand">
                <span class="brand__mark" aria-hidden="true">✈</span>
                <div class="brand__text">
                    <h1 class="brand__title">Travel Tracker</h1>
                    <p class="brand__subtitle">Split trip costs, settle up later</p>
                </div>
            </div>

            <div class="trip-bar">
                <?php if ($trips !== []): ?>
                    <!--
                        Switch Trip: a GET navigation, not a state change, so it
                        needs no CSRF token and no endpoint — the dashboard is
                        the endpoint. The submit button keeps this working when
                        JavaScript is off; script.js hides it where JS runs.
                    -->
                    <form class="trip-switcher" action="index.php" method="get">
                        <label class="visually-hidden" for="trip_id">Switch trip</label>
                        <select
                            class="field__input field__input--compact"
                            id="trip_id"
                            name="trip_id"
                            data-trip-switcher
                            aria-label="Switch trip">
                            <?php foreach ($trips as $trip): ?>
                                <option value="<?= e($trip['id']) ?>"<?= (int) $trip['id'] === (int) $activeTripId ? ' selected' : '' ?>>
                                    <?= e($trip['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn--sm btn--ghost" type="submit" data-trip-switcher-submit>Switch</button>
                    </form>
                <?php endif; ?>

                <button class="btn btn--sm btn--primary" type="button" id="trip-dialog-open">+ New trip</button>
            </div>
        </div>
    </header>

    <main class="container">

        <!-- ================= Flash Message ================= -->
        <?php if ($notice !== ''): ?>
            <div class="notice <?= e($noticeType) ?>" role="status" aria-live="polite">
                <span class="notice__text"><?= e($notice) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$hasTrip): ?>
            <!-- ================= First-run empty state ================= -->
            <section class="section">
                <div class="card">
                    <h2 class="section__title">No trips yet</h2>
                    <p class="empty-state">
                        Choose <strong>+ New trip</strong> above, name the trip and add everyone travelling with you.
                    </p>
                </div>
            </section>
        <?php else: ?>

        <!-- ================= Summary Cards ================= -->
        <section class="section" aria-labelledby="summary-heading">
            <div class="card__header card__header--row">
                <h2 class="section__title" id="summary-heading">Trip Summary</h2>
                <span class="header-badges">
                    <span class="badge badge--trip"><?= e($tripName) ?></span>
                    <span class="badge"><?= e($memberCount) ?> <?= $memberCount === 1 ? 'member' : 'members' ?></span>
                </span>
            </div>

            <div class="summary-grid">
                <article class="card metric-card metric-card--primary">
                    <p class="metric-card__label">Total Trip Cost (₹)</p>
                    <p class="metric-card__value"><?= e(format_money($totalCents)) ?></p>
                    <p class="metric-card__meta">
                        <?php if ($expenseCount === 0): ?>
                            No expenses logged yet
                        <?php else: ?>
                            Across <?= e($expenseCount) ?> logged <?= $expenseCount === 1 ? 'expense' : 'expenses' ?>
                        <?php endif; ?>
                    </p>
                </article>

                <article class="card metric-card metric-card--accent">
                    <p class="metric-card__label">Average Per Person (₹)</p>
                    <p class="metric-card__value"><?= e(format_money($averagePerPerson)) ?></p>
                    <p class="metric-card__meta">
                        Average only — each expense is split between the members who shared it
                    </p>
                </article>
            </div>
        </section>

        <!-- ================= Add Expense ================= -->
        <section class="section" aria-labelledby="add-expense-heading">
            <div class="card form-card">
                <header class="card__header">
                    <h2 class="section__title" id="add-expense-heading">Add Expense</h2>
                    <p class="card__hint">
                        Logged against <strong><?= e($tripName) ?></strong>. The amount is divided
                        between the members you tick below — not the whole trip.
                    </p>
                </header>

                <!-- The trip is a hidden field: the POST carries the trip being
                     viewed, so an add never lands in the wrong trip after a
                     switch. Validated server-side, as with any input. -->
                <form class="expense-form" action="add_expense.php" method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="trip_id" value="<?= e($activeTripId) ?>">
                    <div class="form-grid">
                        <div class="field field--wide">
                            <label class="field__label" for="description">Description</label>
                            <input
                                class="field__input"
                                type="text"
                                id="description"
                                name="description"
                                placeholder="Dinner, Train Tickets…"
                                maxlength="255"
                                autocomplete="off"
                                required>
                        </div>

                        <div class="field">
                            <label class="field__label" for="amount">Total Amount (₹)</label>
                            <input
                                class="field__input"
                                type="number"
                                id="amount"
                                name="amount"
                                placeholder="₹ 0.00"
                                min="0.01"
                                step="0.01"
                                inputmode="decimal"
                                required>
                        </div>

                        <div class="field">
                            <label class="field__label" for="payer_id">Paid by</label>
                            <select class="field__input" id="payer_id" name="payer_id" required>
                                <?php if ($members === []): ?>
                                    <option value="" disabled selected>No members in this trip</option>
                                <?php else: ?>
                                    <option value="" disabled selected>Select a member</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?= e($member['id']) ?>"><?= e($member['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- ============ Who shares this expense ============ -->
                        <fieldset class="field field--wide participants">
                            <legend class="field__label">
                                Split between
                                <span class="participants__hint" id="split-hint" aria-live="polite"></span>
                            </legend>

                            <?php if ($members === []): ?>
                                <p class="empty-state">Add members to this trip before logging an expense.</p>
                            <?php else: ?>
                                <div class="participants__toolbar">
                                    <button class="btn btn--sm btn--ghost" type="button" data-participants-action="all">Select all</button>
                                    <button class="btn btn--sm btn--ghost" type="button" data-participants-action="none">Clear</button>
                                </div>

                                <div class="participants__grid" id="participants-grid">
                                    <?php foreach ($members as $member): ?>
                                        <label class="participant">
                                            <input
                                                type="checkbox"
                                                name="participants[]"
                                                value="<?= e($member['id']) ?>"
                                                data-participant
                                                checked>
                                            <span class="participant__name"><?= e($member['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </fieldset>

                        <div class="field field--submit">
                            <button class="btn btn--primary" type="submit"<?= $members === [] ? ' disabled' : '' ?>>Add Expense</button>
                        </div>
                    </div>

                    <!-- script.js writes success/error feedback here -->
                    <p class="form-status" role="status" aria-live="polite"></p>
                </form>
            </div>
        </section>

        <!-- ================= Who Owes Who ================= -->
        <!-- data-* attributes feed the WhatsApp share; PHP owns the numbers, JS owns the wording. -->
        <section class="section" aria-labelledby="balances-heading">
            <div
                class="card"
                id="balances-card"
                data-trip-name="<?= e($tripName) ?>"
                data-total-cents="<?= e($totalCents) ?>"
                data-per-person-cents="<?= e($averagePerPerson) ?>">
                <header class="card__header card__header--row">
                    <h2 class="section__title" id="balances-heading">Who Owes Who</h2>
                    <button class="btn btn--sm btn--ghost" type="button" id="share-balances" data-share-target="#balances-card">
                        Share to WhatsApp
                    </button>
                </header>

                <?php if ($balances === []): ?>
                    <p class="empty-state">No members in this trip yet.</p>
                <?php else: ?>
                    <ul class="balance-list">
                        <?php foreach ($balances as $balance): ?>
                            <?php
                            $balanceCents = (int) $balance['balance_cents'];

                            if ($balanceCents > 0) {
                                $modifier   = 'balance-item--owed';
                                $statusText = 'Owed ' . format_money($balanceCents);
                            } elseif ($balanceCents < 0) {
                                $modifier   = 'balance-item--owing';
                                $statusText = 'Owes ' . format_money($balanceCents);
                            } else {
                                $modifier   = 'balance-item--settled';
                                $statusText = 'Settled';
                            }
                            ?>
                            <li
                                class="balance-item <?= e($modifier) ?>"
                                data-traveler-name="<?= e($balance['name']) ?>"
                                data-balance-cents="<?= e($balanceCents) ?>">
                                <span class="balance-item__person">
                                    <span class="balance-item__name"><?= e($balance['name']) ?></span>
                                    <span class="balance-item__meta">
                                        Paid <?= e(format_money((int) $balance['paid_cents'])) ?>
                                        · owes <?= e(format_money((int) $balance['owed_cents'])) ?>
                                        <?php if ((int) $balance['sent_cents'] > 0 || (int) $balance['received_cents'] > 0): ?>
                                            · settled <?= e(format_money((int) $balance['sent_cents'] - (int) $balance['received_cents'])) ?>
                                        <?php endif; ?>
                                    </span>
                                </span>

                                <!-- A <div>, not a <span>: this holds a <form>, which is flow content. -->
                                <div class="balance-item__actions">
                                    <span class="balance-item__status"><?= e($statusText) ?></span>

                                    <?php if ($balanceCents < 0): ?>
                                        <!-- js-confirm asks for a second click before moving money. -->
                                        <form class="js-confirm" action="settle_up.php" method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="trip_id" value="<?= e($activeTripId) ?>">
                                            <input type="hidden" name="traveler_id" value="<?= e($balance['id']) ?>">
                                            <button
                                                class="btn btn--sm btn--success"
                                                type="submit"
                                                data-confirm-label="Confirm?">Settle Up</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($settlements !== []): ?>
                    <!-- Settlements are not expenses; they are listed here so a
                         cleared balance has a visible explanation. -->
                    <div class="settlements">
                        <h3 class="settlements__title">Recent settlements</h3>
                        <ul class="settlements__list">
                            <?php foreach ($settlements as $settlement): ?>
                                <li class="settlements__item">
                                    <span class="settlements__who">
                                        <?= e($settlement['from_name']) ?> <span aria-hidden="true">→</span>
                                        <span class="visually-hidden">paid</span>
                                        <?= e($settlement['to_name']) ?>
                                    </span>
                                    <span class="settlements__amount"><?= e(format_money(to_paise($settlement['amount']))) ?></span>
                                    <span class="settlements__date"><?= e(format_date((string) $settlement['created_at'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================= Recent Expenses ================= -->
        <section class="section" aria-labelledby="recent-heading">
            <div class="card">
                <header class="card__header card__header--row">
                    <h2 class="section__title" id="recent-heading">Recent Expenses</h2>
                    <span class="badge">Last 10</span>
                </header>

                <?php if ($expenses === []): ?>
                    <p class="empty-state">No expenses yet — add the first one above.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <caption class="visually-hidden">Most recent expenses in <?= e($tripName) ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Payer</th>
                                    <th scope="col">Shared by</th>
                                    <th scope="col" class="table__num">Total (₹)</th>
                                    <th scope="col" class="table__actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $expense): ?>
                                    <?php $participantNames = (string) ($expense['participant_names'] ?? ''); ?>
                                    <tr>
                                        <td data-label="Date"><?= e(format_date((string) $expense['created_at'])) ?></td>
                                        <td data-label="Description"><?= e($expense['description']) ?></td>
                                        <td data-label="Payer"><span class="chip"><?= e($expense['payer_name']) ?></span></td>
                                        <td data-label="Shared by">
                                            <?php if ($participantNames === ''): ?>
                                                <span class="table__muted">nobody — check this expense</span>
                                            <?php else: ?>
                                                <span class="table__clamp" title="<?= e($participantNames) ?>">
                                                    <?= e($participantNames) ?>
                                                </span>
                                                <span class="table__count"><?= e($expense['participant_count']) ?> way<?= (int) $expense['participant_count'] === 1 ? '' : 's' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Total (₹)" class="table__num"><?= e(format_money(to_paise($expense['total_amount']))) ?></td>
                                        <td data-label="Actions" class="table__actions">
                                            <form class="js-confirm" action="delete_expense.php" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="trip_id" value="<?= e($activeTripId) ?>">
                                                <input type="hidden" name="expense_id" value="<?= e($expense['id']) ?>">
                                                <button
                                                    class="btn btn--sm btn--danger-ghost"
                                                    type="submit"
                                                    data-confirm-label="Confirm?"
                                                    aria-label="Delete expense: <?= e($expense['description']) ?>">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================= Danger Zone: delete this trip ================= -->
        <section class="section" aria-labelledby="danger-heading">
            <div class="card danger-card">
                <header class="card__header">
                    <h2 class="section__title" id="danger-heading">Delete this trip</h2>
                </header>

                <p class="card__hint">
                    Permanently removes <strong><?= e($tripName) ?></strong> and everything in it —
                    members, expenses, splits and settlements. It disappears from the switcher
                    and cannot be undone.
                </p>

                <?php if (!$allSettled): ?>
                    <!-- Locked: destroying an unsettled trip would destroy the
                         record of who owed whom. The endpoint enforces this
                         too — the state below is only what the user sees. -->
                    <p class="danger-card__locked">
                        Settle every balance first —
                        <?= e($unsettledMembers) ?>
                        <?= $unsettledMembers === 1 ? 'member has' : 'members have' ?>
                        an unsettled balance.
                    </p>
                    <button class="btn btn--danger" type="button" disabled>Delete trip</button>
                <?php else: ?>
                    <!-- js-confirm: first click arms, second click deletes. -->
                    <form class="js-confirm" action="delete_trip.php" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="trip_id" value="<?= e($activeTripId) ?>">
                        <button
                            class="btn btn--danger"
                            type="submit"
                            data-confirm-label="Delete everything?">Delete trip</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php endif; ?>

    </main>

    <footer class="site-footer">
        <small>&copy; <?= date('Y') ?> Travel Tracker</small>
    </footer>

    <!-- ================= New trip modal ================= -->
    <dialog class="modal" id="trip-dialog" aria-labelledby="trip-dialog-title">
        <form class="modal__form" action="create_trip.php" method="post">
            <?= csrf_field() ?>
            <h2 class="modal__title" id="trip-dialog-title">New trip</h2>
            <p class="modal__hint">Name the trip, then add everyone travelling with you.</p>

            <div class="field">
                <label class="field__label" for="trip_name">Trip name</label>
                <input
                    class="field__input"
                    type="text"
                    id="trip_name"
                    name="trip_name"
                    placeholder="Goa 2027"
                    maxlength="150"
                    autocomplete="off"
                    required>
            </div>

            <fieldset class="field">
                <legend class="field__label">Members</legend>
                <div class="member-rows" id="member-rows"></div>
                <button class="btn btn--sm btn--ghost" type="button" id="add-member-row">+ Add another member</button>
            </fieldset>

            <div class="modal__actions">
                <button class="btn btn--ghost" type="button" id="trip-dialog-cancel">Cancel</button>
                <button class="btn btn--primary" type="submit">Create trip</button>
            </div>

            <!-- Creating a trip needs JavaScript to build the member rows; the
                 server accepts any number of member_names[] inputs, so a plain HTML
                 form still works if someone posts directly. -->
            <noscript>
                <p class="modal__note">Creating a trip in this page needs JavaScript. With it off, insert the trip and its members from phpMyAdmin or the SQL console.</p>
            </noscript>
        </form>
    </dialog>

    <!-- One row is cloned for each extra member; JS fills in the value. -->
    <template id="member-row-template">
        <div class="member-row">
            <input
                class="field__input"
                type="text"
                name="member_names[]"
                placeholder="Member name"
                maxlength="100"
                autocomplete="off">
            <button class="btn btn--sm btn--danger-ghost js-remove-member" type="button" aria-label="Remove this member">Remove</button>
        </div>
    </template>

    <!-- Clipboard confirmations land here (script.js fills it in) -->
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script src="script.js"></script>
</body>
</html>
