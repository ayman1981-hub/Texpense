<?php
/**
 * lib.php — shared helpers for the Group Travel Expense Tracker.
 *
 * Included after db.php by every page and endpoint, so the dashboard and the
 * write endpoints can never disagree about what a balance is. Expects $pdo to
 * already exist.
 *
 * Conventions used throughout:
 *   - Money is integer paise. Never floats (see to_paise()).
 *   - Every state-changing request is POST + CSRF-checked.
 *   - Output escaping happens at the render edge with e().
 */

declare(strict_types=1);

/* =========================================================================
   Output + money + dates
   ========================================================================= */

/** Escape a value for safe output in HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Convert a DECIMAL/string amount from MySQL into integer paise. */
function to_paise($amount): int
{
    return (int) round((float) $amount * 100);
}

/** Format integer paise as a rupee string, e.g. 1845000 -> "₹18,450.00". */
function format_money(int $paise): string
{
    $sign = $paise < 0 ? '-' : '';

    return $sign . '₹' . number_format(abs($paise) / 100, 2);
}

/** Format a MySQL TIMESTAMP for display, e.g. "12 Sep 2026". */
function format_date(string $timestamp, string $format = 'd M Y'): string
{
    try {
        return (new DateTimeImmutable($timestamp))->format($format);
    } catch (Exception $exception) {
        return $timestamp;
    }
}

/**
 * Normalise a free-text description into safe, predictable plain text.
 * Markup is stripped rather than HTML-encoded: encoding on the way in plus
 * escaping on the way out double-encodes ("&#39;" rendered literally).
 */
function clean_description(string $raw): string
{
    $clean = strip_tags($raw);
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean);
    $clean = preg_replace('/\s+/u', ' ', $clean ?? '');

    return trim($clean ?? '');
}

/** Collapse whitespace in a trip name (no markup is allowed in names either). */
function clean_trip_name(string $raw): string
{
    return clean_description($raw);
}

/* =========================================================================
   Sessions, CSRF and redirects
   ========================================================================= */

/*
 * The session is started by db.php (which every page includes first), because
 * both the active trip and the CSRF token live in it.
 */

/**
 * The per-session CSRF token.
 *
 * Every POST form renders csrf_field() and every endpoint calls
 * require_valid_csrf() before touching the database. Without this, any page a
 * user visits could silently delete expenses or move money in their trip.
 */
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Hidden input for a POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Reject the request unless it carries a valid token. */
function require_valid_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? null;

    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        error_log(
            '[travel_tracker] CSRF mismatch on '
            . ($_SERVER['REQUEST_URI'] ?? 'unknown')
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );

        redirect_to('index.php', ['status' => 'error', 'reason' => 'csrf']);
    }
}

/** Reject anything that is not a POST before it can reach the database. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        if (!headers_sent()) {
            http_response_code(405);
            header('Allow: POST');
        }
        exit('Method Not Allowed. This endpoint accepts POST requests only.');
    }
}

/**
 * Redirect (Post/Redirect/GET) and stop. 303 tells the browser to follow up
 * with a GET, so refreshing the dashboard cannot replay the POST.
 */
function redirect_to(string $script, array $params = []): void
{
    $query = $params === [] ? '' : '?' . http_build_query($params);

    if (!headers_sent()) {
        header('Location: ' . $script . $query, true, 303);
    }

    exit;
}

/** Read a positive integer from POST, or null when absent/malformed. */
function post_int(string $key, int $min = 1): ?int
{
    $raw = $_POST[$key] ?? null;

    if (!is_string($raw) && !is_int($raw)) {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);

    return $value === false ? null : (int) $value;
}

/**
 * Redirect back to a specific trip's dashboard (Post/Redirect/GET).
 *
 * Every endpoint funnels through here so the trip_id in the URL is never
 * forgotten between an action and the page load that shows its result.
 */
function redirect_to_trip(int $tripId, string $status, string $reason = ''): void
{
    $params = ['trip_id' => $tripId, 'status' => $status];

    if ($reason !== '') {
        $params['reason'] = $reason;
    }

    redirect_to('index.php', $params);
}

/* =========================================================================
   Trips
   ========================================================================= */

/** All trips, newest first. */
function fetch_trips(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name, created_at
           FROM trips
          ORDER BY created_at DESC, id DESC'
    )->fetchAll();
}

/**
 * The trip this page load is looking at, taken from the URL.
 *
 * ?trip_id=123 wins when it names a real trip — which is what makes a
 * dashboard link shareable and lets two tabs sit on two different trips.
 * Anything else (parameter absent, malformed, or pointing at a deleted trip)
 * falls back to the most recently created trip, so a bare index.php always
 * shows something useful.
 *
 * Returns null only when no trips exist at all.
 *
 * Note for callers in POST endpoints: the URL is not available after a form
 * submit, so those take the trip from a hidden form field instead — see
 * posted_trip_id().
 */
function resolve_active_trip_id(PDO $pdo): ?int
{
    $requested = $_GET['trip_id'] ?? null;

    if (is_string($requested) || is_int($requested)) {
        $candidate = filter_var($requested, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($candidate !== false && trip_exists($pdo, (int) $candidate)) {
            return (int) $candidate;
        }

        // A present-but-wrong trip_id is a stale or mangled link: drop it and
        // let the redirect in index.php rebuild a clean URL.
        $GLOBALS['trip_id_param_rejected'] = true;
    }

    // The most recently created trip is the one a group is most likely
    // mid-way through.
    $newest = $pdo->query(
        'SELECT id FROM trips ORDER BY created_at DESC, id DESC LIMIT 1'
    )->fetchColumn();

    return $newest === false ? null : (int) $newest;
}

/**
 * The trip a POST endpoint should act on, taken from the hidden trip_id field.
 *
 * A hidden input is user-controlled, so "it came from our form" proves
 * nothing: the id is only trusted after confirming the trip exists. Callers
 * redirect with an error when this returns null.
 */
function posted_trip_id(PDO $pdo): ?int
{
    $candidate = post_int('trip_id');

    if ($candidate === null || !trip_exists($pdo, $candidate)) {
        return null;
    }

    return $candidate;
}

/** Does this trip exist? */
function trip_exists(PDO $pdo, int $tripId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM trips WHERE id = :id LIMIT 1');
    $statement->bindValue(':id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchColumn() !== false;
}

/** The travellers of one trip, alphabetical. */
function fetch_travelers(PDO $pdo, int $tripId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name
           FROM travelers
          WHERE trip_id = :trip_id
          ORDER BY name ASC, id ASC'
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Recent expenses for a trip, newest first, with who shared each one.
 *
 * LEFT JOINs so an expense with no split rows still appears (it should not
 * exist, but a half-written expense must not vanish from the screen silently).
 * Every non-aggregated column is in GROUP BY, so this is ONLY_FULL_GROUP_BY safe.
 */
function fetch_recent_expenses(PDO $pdo, int $tripId, int $limit = 10): array
{
    $statement = $pdo->prepare(
        'SELECT e.id,
                e.description,
                e.total_amount,
                e.created_at,
                t.name AS payer_name,
                COUNT(s.traveler_id) AS participant_count,
                GROUP_CONCAT(shr.name ORDER BY shr.name SEPARATOR ", ") AS participant_names
           FROM expenses e
           INNER JOIN travelers t ON t.id = e.payer_id
           LEFT JOIN expense_splits s ON s.expense_id = e.id
           LEFT JOIN travelers shr ON shr.id = s.traveler_id
          WHERE e.trip_id = :trip_id
          GROUP BY e.id, e.description, e.total_amount, e.created_at, t.name
          ORDER BY e.created_at DESC, e.id DESC
          LIMIT ' . (int) $limit
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/** Recent settlements for a trip, newest first. */
function fetch_recent_settlements(PDO $pdo, int $tripId, int $limit = 5): array
{
    $statement = $pdo->prepare(
        'SELECT s.id, s.amount, s.created_at,
                f.name AS from_name,
                t.name AS to_name
           FROM settlements s
           INNER JOIN travelers f ON f.id = s.from_traveler_id
           INNER JOIN travelers t ON t.id = s.to_traveler_id
          WHERE s.trip_id = :trip_id
          ORDER BY s.created_at DESC, s.id DESC
          LIMIT ' . (int) $limit
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/* =========================================================================
   Balance engine — the single source of truth for the split maths
   ========================================================================= */

/**
 * Everything the dashboard and the settle-up endpoint need to reason about a
 * trip's money, in paise.
 *
 * Each balance entry carries:
 *   paid_cents     expenses this member fronted (their expense rows)
 *   owed_cents     the sum of their expense_splits — what they consumed
 *   sent_cents     cash they have handed over to settle debts
 *   received_cents cash they have taken in from others settling up
 *   balance_cents  paid + sent - received - owed
 *
 *   positive -> the trip owes them, negative -> they owe the trip.
 *
 * The balances sum to exactly 0 as long as every expense's split rows add up
 * to its total_amount (see split_amount() and add_expense.php). There is no
 * single "fair share" any more: each expense is divided among the members who
 * actually shared it, so two members can owe very different totals for the
 * same trip — average_per_person_cents is a display-only figure.
 *
 * @return array{
 *   traveler_count:int, total_cents:int, average_per_person_cents:int,
 *   expense_count:int, balances:array<int,array<string,mixed>>
 * }
 */
function trip_financials(PDO $pdo, int $tripId): array
{
    $travelers = fetch_travelers($pdo, $tripId);
    $travelerCount = count($travelers);

    // --- Trip total ------------------------------------------------------
    $statement = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM expenses WHERE trip_id = :trip_id');
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();
    $totalRaw = $statement->fetchColumn();

    $totalCents = to_paise($totalRaw === false ? 0 : $totalRaw);

    // --- Expense count ---------------------------------------------------
    $statement = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE trip_id = :trip_id');
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();
    $expenseCount = (int) $statement->fetchColumn();

    // --- Paid per traveller ---------------------------------------------
    $statement = $pdo->prepare(
        'SELECT payer_id, SUM(total_amount) AS total_paid
           FROM expenses
          WHERE trip_id = :trip_id
          GROUP BY payer_id'
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    $paidByTraveler = [];
    foreach ($statement->fetchAll() as $row) {
        $paidByTraveler[(int) $row['payer_id']] = to_paise($row['total_paid']);
    }

    // --- Owed per member, from the split rows ----------------------------
    // expense_splits has no trip_id of its own, so the trip filter has to come
    // through its expense — which is why expenses carries idx_expenses_trip_date
    // and expense_splits carries idx_splits_traveler.
    $statement = $pdo->prepare(
        'SELECT s.traveler_id, SUM(s.amount_owed) AS total_owed
           FROM expense_splits s
           INNER JOIN expenses e ON e.id = s.expense_id
          WHERE e.trip_id = :trip_id
          GROUP BY s.traveler_id'
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    $owedByTraveler = [];
    foreach ($statement->fetchAll() as $row) {
        $owedByTraveler[(int) $row['traveler_id']] = to_paise($row['total_owed']);
    }

    // --- Settlements, from both sides of the transfer --------------------
    $statement = $pdo->prepare(
        'SELECT from_traveler_id, SUM(amount) AS total_sent
           FROM settlements
          WHERE trip_id = :trip_id
          GROUP BY from_traveler_id'
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    $sentByTraveler = [];
    foreach ($statement->fetchAll() as $row) {
        $sentByTraveler[(int) $row['from_traveler_id']] = to_paise($row['total_sent']);
    }

    $statement = $pdo->prepare(
        'SELECT to_traveler_id, SUM(amount) AS total_received
           FROM settlements
          WHERE trip_id = :trip_id
          GROUP BY to_traveler_id'
    );
    $statement->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    $statement->execute();

    $receivedByTraveler = [];
    foreach ($statement->fetchAll() as $row) {
        $receivedByTraveler[(int) $row['to_traveler_id']] = to_paise($row['total_received']);
    }

    // Average only — with per-expense splits there is no single fair share any
    // more, so this is a headline figure, not something anyone owes.
    $averagePerPersonCents = $travelerCount > 0 ? (int) round($totalCents / $travelerCount) : 0;

    $balances = [];

    foreach ($travelers as $traveler) {
        $travelerId = (int) $traveler['id'];
        $paidCents     = $paidByTraveler[$travelerId] ?? 0;
        $owedCents     = $owedByTraveler[$travelerId] ?? 0;
        $sentCents     = $sentByTraveler[$travelerId] ?? 0;
        $receivedCents = $receivedByTraveler[$travelerId] ?? 0;

        $balances[] = [
            'id'             => $travelerId,
            'name'           => (string) $traveler['name'],
            'paid_cents'     => $paidCents,
            'owed_cents'     => $owedCents,
            'sent_cents'     => $sentCents,
            'received_cents' => $receivedCents,
            'balance_cents'  => $paidCents + $sentCents - $receivedCents - $owedCents,
        ];
    }

    return [
        'traveler_count'          => $travelerCount,
        'total_cents'             => $totalCents,
        'average_per_person_cents' => $averagePerPersonCents,
        'expense_count'           => $expenseCount,
        'balances'                => $balances,
    ];
}

/**
 * Divide an amount among a set of members, in whole paise.
 *
 * The parts always add back up to the total: intdiv() gives every member the
 * same base share and the remaining paise are handed out one each, rather than
 * letting rounding silently lose (or invent) money. ₹100 across 3 members is
 * 33.34 + 33.33 + 33.33, never 33.33 x 3.
 *
 * Members are sorted by id first so the result is deterministic: the same
 * expense always produces the same split rows.
 *
 * @param int   $totalCents  the amount to divide (positive paise)
 * @param int[] $travelerIds participant ids
 * @return array<int,int>    traveler id => cents owed
 */
function split_amount(int $totalCents, array $travelerIds): array
{
    $travelerIds = array_values(array_unique(array_map('intval', $travelerIds)));
    sort($travelerIds);

    $count = count($travelerIds);

    if ($count === 0 || $totalCents <= 0) {
        return [];
    }

    $base = intdiv($totalCents, $count);
    $remainder = $totalCents % $count;

    $splits = [];

    foreach ($travelerIds as $index => $travelerId) {
        $splits[$travelerId] = $base + ($index < $remainder ? 1 : 0);
    }

    return $splits;
}

/**
 * Work out who should pay whom to clear one debtor's balance, using the
 * fewest transfers: the debtor pays their largest creditors first.
 *
 * Pure arithmetic — it touches no database and can be reasoned about (and
 * tested) on its own.
 *
 * @param int $debtCents        the amount to be settled (positive paise)
 * @param array $creditors      balances with balance_cents > 0, any order
 * @return array<int,array{id:int,name:string,amount_cents:int}>
 */
function allocate_settlement(int $debtCents, array $creditors): array
{
    if ($debtCents <= 0) {
        return [];
    }

    // Largest creditor first: fewer, larger transfers.
    usort($creditors, static fn (array $a, array $b): int => $b['balance_cents'] <=> $a['balance_cents']);

    $transfers = [];
    $remaining = $debtCents;

    foreach ($creditors as $creditor) {
        if ($remaining <= 0) {
            break;
        }

        $payable = min((int) $creditor['balance_cents'], $remaining);

        if ($payable <= 0) {
            continue;
        }

        $transfers[] = [
            'id'           => (int) $creditor['id'],
            'name'         => (string) $creditor['name'],
            'amount_cents' => $payable,
        ];

        $remaining -= $payable;
    }

    // A nonzero remainder means the creditors could not cover the debt, which
    // happens only if the balances do not sum to zero.
    return $remaining === 0 ? $transfers : [];
}
