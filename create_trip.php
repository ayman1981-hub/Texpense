<?php
/**
 * create_trip.php — creates a trip AND its members in one transaction.
 *
 * The form posts trip_name plus a member_names[] array of names that JavaScript
 * added to the modal. A trip with members is the smallest useful unit: an
 * empty trip cannot hold an expense, so at least one member is required.
 *
 * All-or-nothing: a half-created trip (name saved, members lost) would be
 * invisible on the dashboard and impossible to fix from the UI.
 */

declare(strict_types=1);

/* =========================================================================
   TEMPORARY DEBUG MODE — delete this block once the bug is found.

   With display_errors off (XAMPP's production-ish default), a failing insert
   can look like "nothing happened": the browser just lands back on the
   dashboard. These lines force every notice, warning and exception onto the
   screen instead. NEVER deploy with them on — a raw PDO message leaks table
   names, SQL fragments and file paths to whoever can trigger the error.
   ========================================================================= */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';   // $pdo already runs ERRMODE_EXCEPTION
require_once __DIR__ . '/lib.php';

require_post();
require_valid_csrf();

const TRIP_NAME_MAX_LENGTH = 150;    // trips.name VARCHAR(150)
const MEMBER_NAME_MAX_LENGTH = 100;  // travelers.name VARCHAR(100)
const MAX_MEMBERS_PER_TRIP = 60;     // a sanity bound, not a technical limit

$name = clean_trip_name(is_string($_POST['trip_name'] ?? null) ? $_POST['trip_name'] : '');

if ($name === '') {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_name_empty']);
}

if (mb_strlen($name) > TRIP_NAME_MAX_LENGTH) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'trip_name_long']);
}

/* -------------------------------------------------------------------------
   Collect the member names.
   ------------------------------------------------------------------------- */
$rawMembers = $_POST['member_names'] ?? null;

if (!is_array($rawMembers)) {
    $rawMembers = [];
}

$members = [];
$seenNames = [];

foreach ($rawMembers as $rawMember) {
    if (!is_string($rawMember)) {
        continue;
    }

    $member = clean_description($rawMember);

    if ($member === '') {
        continue;   // blank rows the user left empty are simply ignored
    }

    if (mb_strlen($member) > MEMBER_NAME_MAX_LENGTH) {
        redirect_to('index.php', ['status' => 'error', 'reason' => 'member_name_long']);
    }

    // Dedupe case-insensitively here: the UNIQUE (trip_id, name) index would
    // reject "Ayman" twice and abort the whole transaction otherwise.
    $fingerprint = mb_strtolower($member);

    if (isset($seenNames[$fingerprint])) {
        continue;
    }

    $seenNames[$fingerprint] = true;
    $members[] = $member;
}

if ($members === []) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'member_empty']);
}

if (count($members) > MAX_MEMBERS_PER_TRIP) {
    redirect_to('index.php', ['status' => 'error', 'reason' => 'member_too_many']);
}

/* -------------------------------------------------------------------------
   Write both tables, or neither.
   ------------------------------------------------------------------------- */
try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare('INSERT INTO trips (name) VALUES (:name)');
    $statement->bindValue(':name', $name, PDO::PARAM_STR);
    $statement->execute();

    $newTripId = (int) $pdo->lastInsertId();

    $insertMember = $pdo->prepare(
        'INSERT INTO travelers (trip_id, name) VALUES (:trip_id, :name)'
    );

    foreach ($members as $member) {
        $insertMember->bindValue(':trip_id', $newTripId, PDO::PARAM_INT);
        $insertMember->bindValue(':name', $member, PDO::PARAM_STR);
        $insertMember->execute();
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // TEMPORARY DEBUG: stop and show the raw failure, per the active
    // investigation. It overrides the graceful redirect below.
    die('Database Error: ' . $exception->getMessage());

    // --- Production behaviour (restore by deleting the die() above and the
    // --- ini_set block at the top):
    // error_log('[travel_tracker] create trip failed: ' . $exception->getMessage());
    //
    // // SQLSTATE 23000 = a duplicate member name slipped past the dedupe above.
    // $reason = ((string) $exception->getCode() === '23000') ? 'member_duplicate' : 'db';
    // redirect_to('index.php', ['status' => 'error', 'reason' => $reason]);
}

// Land the user on the trip they just created: the URL is the source of
// truth for the active trip, so the redirect carries the new id.
redirect_to('index.php', [
    'trip_id' => $newTripId,
    'status'  => 'success',
    'reason'  => 'trip_created',
]);
