<?php
/**
 * delete_trip.php — permanently removes a trip and ALL of its data.
 *
 * The most destructive endpoint in the app: one request erases a trip's
 * members, every expense, every split row and every settlement.
 *
 * Safety rules
 *   - POST + CSRF only.
 *   - The trip must be FULLY SETTLED — every balance exactly zero. Deleting an
 *     unsettled trip would destroy the only record of who owed whom, so a trip
 *     may leave the switcher only after the group has squared up. index.php
 *     hides the button in that state; the same check is repeated HERE because
 *     a hidden button in a browser proves nothing.
 *   - Deletes run in foreign-key-safe order inside one transaction:
 *       settlements -> expenses (split rows cascade away) -> travelers -> trip
 *     Every FK is ON DELETE RESTRICT, so a wrong order would be rejected by
 *     the database rather than corrupt it — the transaction guarantees a
 *     half-deleted trip can never be committed.
 *   - The success redirect carries NO trip_id: the trip no longer exists, so
 *     the dashboard falls back to the newest remaining trip (or shows the
 *     first-run empty state when this was the last one).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

require_post();
require_valid_csrf();

// The trip comes from the hidden trip_id field of the danger-zone form.
$tripId = posted_trip_id($pdo);

if ($tripId === null) {
    // Stale page, a hand-rolled POST, or a double submit (already gone).
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_invalid']);
}

// --- Read the ledger first: this both proves the trip exists and tells us
// --- whether every balance has been settled.
try {
    $financials = trip_financials($pdo, $tripId);
} catch (PDOException $exception) {
    error_log('[travel_tracker] trip-delete read failed: ' . $exception->getMessage());
    redirect_to('index.php', ['status' => 'error', 'reason' => 'db']);
}

foreach ($financials['balances'] as $balance) {
    if ((int) $balance['balance_cents'] !== 0) {
        redirect_to('index.php', [
            'trip_id' => $tripId,
            'status'  => 'error',
            'reason'  => 'trip_not_settled',
        ]);
    }
}

try {
    $pdo->beginTransaction();

    // 1. Settlements first: they reference travelers (RESTRICT).
    $statement = $pdo->prepare('DELETE FROM settlements WHERE trip_id = :trip_id');
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    // 2. Expenses: their expense_splits rows are removed by ON DELETE CASCADE,
    //    so splits never have to be touched (and could not be, individually —
    //    they have no trip_id of their own).
    $statement = $pdo->prepare('DELETE FROM expenses WHERE trip_id = :trip_id');
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    // 3. Travelers: referenced by nothing now (splits cascaded, expenses and
    //    settlements deleted above).
    $statement = $pdo->prepare('DELETE FROM travelers WHERE trip_id = :trip_id');
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    // 4. The trip row itself.
    $statement = $pdo->prepare('DELETE FROM trips WHERE id = :id');
    $statement->bindValue(':id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() === 0) {
        // Deleted underneath us between the check and this write.
        $pdo->rollBack();
        redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_invalid']);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[travel_tracker] trip delete failed: ' . $exception->getMessage());
    redirect_to('index.php', ['status' => 'error', 'reason' => 'db']);
}

// No trip_id here on purpose: there is no trip to go back to.
redirect_to('index.php', ['status' => 'success', 'reason' => 'trip_deleted']);
