<?php
/**
 * delete_expense.php — removes one expense from the trip the form was posted
 * from.
 *
 * Destructive, so it is POST + CSRF only, and the DELETE is scoped by trip_id
 * so a tampered expense_id cannot reach another trip's data.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

require_post();
require_valid_csrf();

// The trip comes from the hidden trip_id field the form posted (the URL is
// gone by the time a POST arrives) and is verified to exist before use.
$tripId    = posted_trip_id($pdo);
$expenseId = post_int('expense_id');

if ($tripId === null) {
    // The hidden trip_id field was not posted — a stale page or a hand-rolled
    // POST. Reported as a trip problem, not an expense problem.
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_invalid']);
}

if ($expenseId === null) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'expense_invalid']);
}

try {
    // trip_id in the WHERE clause is the authorisation check: even with a
    // valid CSRF token, this can only delete from the trip you are viewing.
    $statement = $pdo->prepare('DELETE FROM expenses WHERE id = :id AND trip_id = :trip_id');
    $statement->bindValue(':id', $expenseId, PDO::PARAM_INT);
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() === 0) {
        // Nothing matched: already deleted (double submit) or a foreign trip id.
        redirect_to_trip($tripId, 'error', 'expense_missing');
    }
} catch (PDOException $exception) {
    error_log('[travel_tracker] expense delete failed: ' . $exception->getMessage());
    redirect_to_trip($tripId, 'error', 'db');
}

redirect_to_trip($tripId, 'success', 'deleted');
