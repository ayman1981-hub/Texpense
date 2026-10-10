<?php
/**
 * settle_up.php — clears one traveller's debt by recording who they paid.
 *
 * The debtor's balance is negative (they owe the trip). Settling means handing
 * cash to the people the trip owes money to, so their debt falls to zero while
 * the recipients' credit falls by the same amount: the trip total and everyone's
 * share are untouched, and the balances still sum to zero.
 *
 * Transfers are allocated largest-creditor-first, so clearing a debt needs as
 * few payments as possible.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

require_post();
require_valid_csrf();

// The trip comes from the hidden trip_id field the form posted (the URL is
// gone by the time a POST arrives) and is verified to exist before use.
$tripId     = posted_trip_id($pdo);
$travelerId = post_int('traveler_id');

if ($tripId === null) {
    // The hidden trip_id field was not posted — a stale page or a hand-rolled
    // POST. This is a form-identity failure, NOT a member problem, so it gets
    // its own reason rather than blaming the traveller.
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_invalid']);
}

if ($travelerId === null) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'traveler_invalid']);
}

try {
    $financials = trip_financials($pdo, $tripId);
} catch (PDOException $exception) {
    error_log('[travel_tracker] settle-up read failed: ' . $exception->getMessage());
    redirect_to_trip($tripId, 'error', 'db');
}

// --- Find the debtor ------------------------------------------------------
$debtor = null;

foreach ($financials['balances'] as $balance) {
    if ((int) $balance['id'] === $travelerId) {
        $debtor = $balance;
        break;
    }
}

if ($debtor === null) {
    redirect_to_trip($tripId, 'error', 'traveler_invalid');
}

// Only a negative balance can be settled. Checked server-side because the
// button being visible in the browser proves nothing.
if ($debtor['balance_cents'] >= 0) {
    redirect_to_trip($tripId, 'error', 'settle_nothing');
}

$owedCents = -$debtor['balance_cents'];

$creditors = array_values(array_filter(
    $financials['balances'],
    static fn (array $balance): bool => $balance['balance_cents'] > 0
));

$transfers = allocate_settlement($owedCents, $creditors);

if ($transfers === []) {
    // No creditor can absorb the payment — the ledger does not balance.
    error_log(
        '[travel_tracker] settle-up found no creditors for traveler '
        . $travelerId . ' in trip ' . $tripId
    );
    redirect_to_trip($tripId, 'error', 'settle_no_creditors');
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO settlements (trip_id, from_traveler_id, to_traveler_id, amount)
         VALUES (:trip_id, :from_id, :to_id, :amount)'
    );

    foreach ($transfers as $transfer) {
        $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
        $statement->bindValue(':from_id', $travelerId, PDO::PARAM_INT);
        $statement->bindValue(':to_id', $transfer['id'], PDO::PARAM_INT);
        // Bound as a string so MySQL casts an exact decimal literal.
        $statement->bindValue(':amount', number_format($transfer['amount_cents'] / 100, 2, '.', ''), PDO::PARAM_STR);
        $statement->execute();
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[travel_tracker] settle-up insert failed: ' . $exception->getMessage());
    redirect_to_trip($tripId, 'error', 'db');
}

redirect_to_trip($tripId, 'success', 'settled');
