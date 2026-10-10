<?php
/**
 * add_expense.php — records an expense and who shares it.
 *
 * Two tables, one transaction:
 *   1. INSERT the bill into `expenses` and take lastInsertId().
 *   2. Divide total_amount by the number of SELECTED members only (not the
 *      whole trip) and insert one `expense_splits` row per member.
 *
 * The division happens in integer paise: `sum(splits) = total_amount` must hold
 * exactly, or the dashboard's balances stop adding up to zero. See
 * split_amount() in lib.php.
 *
 * Security
 *   - Prepared statements with bound parameters throughout.
 *   - POST + CSRF token.
 *   - The trip comes from the SESSION, and every posted member id is checked to
 *     belong to that trip, so a tampered form cannot split an expense across
 *     another trip's members.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

const DESCRIPTION_MIN_LENGTH = 3;
const DESCRIPTION_MAX_LENGTH = 255;        // expenses.description VARCHAR(255)
const AMOUNT_MAX = 99999999.99;            // expenses.total_amount DECIMAL(10,2)

require_post();
require_valid_csrf();

/* -------------------------------------------------------------------------
   1. Which trip?

   Taken from the hidden trip_id field (Step 4), then verified to exist — a
   hidden input is user-controlled, so "it came from the form" proves nothing.
   ------------------------------------------------------------------------- */
$tripId = posted_trip_id($pdo);

if ($tripId === null) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_missing']);
}

/* -------------------------------------------------------------------------
   2. Read and validate the scalar fields
   ------------------------------------------------------------------------- */

// is_string() guards: a payload like description[]=x would otherwise make these
// arrays, and PHP 8 would throw a TypeError inside the string functions.
$rawDescription = is_string($_POST['description'] ?? null) ? $_POST['description'] : '';
$rawAmount      = is_string($_POST['amount'] ?? null)      ? trim($_POST['amount']) : '';
$rawPayerId     = is_string($_POST['payer_id'] ?? null)    ? trim($_POST['payer_id']) : '';

$errors = [];

// --- Description ---------------------------------------------------------
$description = clean_description($rawDescription);
$descriptionLength = mb_strlen($description);

if ($description === '') {
    $errors[] = 'desc_empty';
} elseif ($descriptionLength < DESCRIPTION_MIN_LENGTH) {
    $errors[] = 'desc_short';
} elseif ($descriptionLength > DESCRIPTION_MAX_LENGTH) {
    $errors[] = 'desc_long';
}

// --- Amount --------------------------------------------------------------
// Matched as a string first: the pattern rejects negatives, scientific
// notation ("1e5"), thousands separators, and more than two decimal places.
$amountCents = 0;

if ($rawAmount === '') {
    $errors[] = 'amount_empty';
} elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $rawAmount)) {
    $errors[] = 'amount_format';
} else {
    $amount = (float) $rawAmount;

    if ($amount <= 0) {
        $errors[] = 'amount_range';
    } elseif ($amount > AMOUNT_MAX) {
        $errors[] = 'amount_range';
    } else {
        $amountCents = to_paise($amount);
    }
}

// --- Payer ---------------------------------------------------------------
$payerId = filter_var($rawPayerId, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($payerId === false) {
    $errors[] = 'payer_invalid';
}

/* -------------------------------------------------------------------------
   3. Read and validate the participants (who shares this expense)
   ------------------------------------------------------------------------- */
$rawParticipants = $_POST['participants'] ?? null;

if (!is_array($rawParticipants)) {
    $rawParticipants = [];
}

$participantIds = [];

foreach ($rawParticipants as $rawParticipant) {
    if (!is_string($rawParticipant) && !is_int($rawParticipant)) {
        continue;
    }

    $candidate = filter_var($rawParticipant, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($candidate === false) {
        // A malformed id means the form was tampered with; drop the request
        // rather than silently splitting among a subset.
        redirect_to_trip($tripId, 'error', 'participants_invalid');
    }

    // A person can only appear once, however many checkboxes they sent.
    $participantIds[(int) $candidate] = (int) $candidate;
}

$participantIds = array_values($participantIds);

if ($participantIds === []) {
    $errors[] = 'participants_empty';
}

if ($errors !== []) {
    redirect_to_trip($tripId, 'error', $errors[0]);
}

/* -------------------------------------------------------------------------
   4. The payer and every participant must belong to THIS trip
   ------------------------------------------------------------------------- */
try {
    $involvedIds = array_values(array_unique(array_merge([$payerId], $participantIds)));
    $placeholders = implode(', ', array_fill(0, count($involvedIds), '?'));

    $check = $pdo->prepare(
        "SELECT id FROM travelers WHERE trip_id = ? AND id IN ($placeholders)"
    );
    $check->execute(array_merge([$tripId], $involvedIds));

    $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array($payerId, $validIds, true)) {
        redirect_to_trip($tripId, 'error', 'payer_unknown');
    }

    if (count($validIds) !== count($involvedIds)) {
        redirect_to_trip($tripId, 'error', 'participants_invalid');
    }
} catch (PDOException $exception) {
    error_log('[travel_tracker] member lookup failed: ' . $exception->getMessage());
    redirect_to_trip($tripId, 'error', 'db');
}

/* -------------------------------------------------------------------------
   5. Divide the bill among the selected members
   ------------------------------------------------------------------------- */
$splits = split_amount($amountCents, $participantIds);

if ($splits === [] || array_sum($splits) !== $amountCents) {
    // Defensive: the split rows MUST add up to the bill, or the balance sheet
    // will not sum to zero. Refuse rather than write a wrong ledger.
    error_log(
        '[travel_tracker] split mismatch: total=' . $amountCents
        . ' sum=' . array_sum($splits) . ' participants=' . count($participantIds)
    );
    redirect_to_trip($tripId, 'error', 'split_failed');
}

/* -------------------------------------------------------------------------
   6. Write the expense and its splits as one unit
   ------------------------------------------------------------------------- */
try {
    $pdo->beginTransaction();
    // 6a. The bill.
    $insertExpense = $pdo->prepare(
        'INSERT INTO expenses (trip_id, payer_id, description, total_amount)
         VALUES (:trip_id, :payer_id, :description, :total_amount)'
    );

    $insertExpense->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $insertExpense->bindValue(':payer_id', $payerId, PDO::PARAM_INT);
    $insertExpense->bindValue(':description', $description, PDO::PARAM_STR);
    // Bound as a string so MySQL casts an exact decimal literal rather than a
    // float that could arrive as "1200.5499999999".
    $insertExpense->bindValue(':total_amount', number_format($amountCents / 100, 2, '.', ''), PDO::PARAM_STR);
    $insertExpense->execute();

    $expenseId = (int) $pdo->lastInsertId();

    // 6b. One split row per selected member.
    $insertSplit = $pdo->prepare(
        'INSERT INTO expense_splits (expense_id, traveler_id, amount_owed)
         VALUES (:expense_id, :traveler_id, :amount_owed)'
    );

    foreach ($splits as $travelerId => $owedCents) {
        $insertSplit->bindValue(':expense_id', $expenseId, PDO::PARAM_INT);
        $insertSplit->bindValue(':traveler_id', $travelerId, PDO::PARAM_INT);
        $insertSplit->bindValue(':amount_owed', number_format($owedCents / 100, 2, '.', ''), PDO::PARAM_STR);
        $insertSplit->execute();
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[travel_tracker] expense insert failed: ' . $exception->getMessage());

    // SQLSTATE 23000 = integrity constraint violation: a member or payer who is
    // not in this trip, or one deleted mid-session.
    $reason = ((string) $exception->getCode() === '23000') ? 'payer' : 'db';
    redirect_to_trip($tripId, 'error', $reason);
}

/* -------------------------------------------------------------------------
   7. Back to the trip the expense was filed under
   ------------------------------------------------------------------------- */
redirect_to_trip($tripId, 'success');
