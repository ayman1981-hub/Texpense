-- ============================================================================
-- Group Travel Expense Tracker — schema v3 (multiple trips, dynamic members,
-- per-expense splits)
--
--   mysql -u root < schema.sql
--
-- DESTRUCTIVE: this drops and recreates the whole database. Every existing
-- trip, traveller, expense and settlement is destroyed. Take a backup first:
--   mysqldump -u root travel_tracker > backup_before_v3.sql
--
-- What changed from v2
--   * expenses.amount          -> expenses.total_amount (the whole bill)
--   * NEW expense_splits table: one row per person who owes part of an
--     expense, so an expense can be split among SOME of the group instead of
--     everyone. Even splitting is now just "one split row per participant".
--   * travelers gained nothing structurally, but members are now created with
--     their trip (see create_trip.php).
-- ============================================================================

DROP DATABASE IF EXISTS travel_tracker;
CREATE DATABASE travel_tracker
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE travel_tracker;

-- ----------------------------------------------------------------------------
-- 1. trips — one row per trip, created with its members.
-- ----------------------------------------------------------------------------
CREATE TABLE trips (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(150) NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'One row per trip / group';


-- ----------------------------------------------------------------------------
-- 2. travelers — the members of a trip.
--    (trip_id, id) is a redundant key on purpose: it is the target that lets
--    expenses and settlements prove, at the database level, that the people
--    they reference belong to the trip being written to.
-- ----------------------------------------------------------------------------
CREATE TABLE travelers (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,   -- surrogate PK for stable references
  trip_id INT UNSIGNED NOT NULL,
  name    VARCHAR(100) NOT NULL,

  PRIMARY KEY (id),

  -- The same person cannot be added to one trip twice.
  UNIQUE KEY uq_travelers_trip_name (trip_id, name),
  UNIQUE KEY uq_travelers_trip_id (trip_id, id),

  -- RESTRICT, not CASCADE: a trip holding members is never deleted silently,
  -- and expenses/expense_splits would refuse the cascade anyway.
  CONSTRAINT fk_travelers_trip
      FOREIGN KEY (trip_id) REFERENCES trips (id)
      ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Members of a trip';


-- ----------------------------------------------------------------------------
-- 3. expenses — the bill itself, paid by one member.
--    total_amount is the FULL amount. Who owes what lives in expense_splits;
--    the two are deliberately separate so one expense can be split among any
--    subset of the trip (or unevenly) without changing this table.
-- ----------------------------------------------------------------------------
CREATE TABLE expenses (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id      INT UNSIGNED  NOT NULL,
  payer_id     INT UNSIGNED  NOT NULL,              -- who fronted the money
  description  VARCHAR(255)  NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,              -- exact money; max 99,999,999.99
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  KEY idx_expenses_trip_date (trip_id, created_at), -- dashboard: this trip, newest first
  KEY idx_expenses_payer (payer_id),                -- paid-per-member aggregation

  CONSTRAINT fk_expenses_trip
      FOREIGN KEY (trip_id) REFERENCES trips (id)
      ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Composite FK: an expense can never be paid by somebody from another trip.
  CONSTRAINT fk_expenses_trip_payer
      FOREIGN KEY (trip_id, payer_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT chk_expenses_total_positive CHECK (total_amount > 0)   -- enforced on MySQL 8.0.16+
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'One bill, paid by one member';


-- ----------------------------------------------------------------------------
-- 4. expense_splits — who owes what for a given expense.
--
--    One row per participant. The split rows of an expense must add up to its
--    total_amount; that is the invariant the dashboard's balance maths relies
--    on, and it is why add_expense.php divides in whole paise and gives the
--    leftover paise away rather than letting rounding lose them.
--
--    No surrogate id: (expense_id, traveler_id) is already unique and is the
--    natural key, which also halves the index count in a table that grows
--    fastest.
-- ----------------------------------------------------------------------------
CREATE TABLE expense_splits (
  expense_id  INT UNSIGNED  NOT NULL,
  traveler_id INT UNSIGNED  NOT NULL,
  amount_owed DECIMAL(10,2) NOT NULL,               -- this member's share

  PRIMARY KEY (expense_id, traveler_id),

  -- Supports "everything this member owes" and satisfies the FK below.
  KEY idx_splits_traveler (traveler_id),

  -- CASCADE: deleting an expense removes its split rows in the same statement,
  -- so a deleted expense can never leave credits behind in the balance sheet.
  CONSTRAINT fk_splits_expense
      FOREIGN KEY (expense_id) REFERENCES expenses (id)
      ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT fk_splits_traveler
      FOREIGN KEY (traveler_id) REFERENCES travelers (id)
      ON DELETE RESTRICT ON UPDATE CASCADE,

  -- >= 0, not > 0: splitting ₹0.01 three ways legitimately gives two members a
  -- share of 0.00, and rejecting that would fail the whole expense.
  CONSTRAINT chk_splits_amount_owed_non_negative CHECK (amount_owed >= 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Per-member share of each expense';


-- ----------------------------------------------------------------------------
-- 5. settlements — cash handed between members to clear balances.
--
--    Not an expense: settling up must not change the trip total or anyone's
--    share. Balances are:
--
--      balance = (expenses paid) + (settlements sent) - (settlements received)
--                - (sum of this member's expense_splits)
--
--    Summed over everyone that is exactly zero, provided every expense's
--    splits add up to its total.
-- ----------------------------------------------------------------------------
CREATE TABLE settlements (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id          INT UNSIGNED  NOT NULL,
  from_traveler_id INT UNSIGNED  NOT NULL,          -- the debtor handing over cash
  to_traveler_id   INT UNSIGNED  NOT NULL,          -- the creditor receiving it
  amount           DECIMAL(10,2) NOT NULL,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_settlements_trip_from
      FOREIGN KEY (trip_id, from_traveler_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_settlements_trip_to
      FOREIGN KEY (trip_id, to_traveler_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT chk_settlements_amount_positive CHECK (amount > 0),
  CONSTRAINT chk_settlements_distinct_party CHECK (from_traveler_id <> to_traveler_id),

  KEY idx_settlements_trip (trip_id),
  KEY idx_settlements_from (from_traveler_id),
  KEY idx_settlements_to (to_traveler_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Cash transfers that clear balances without changing trip cost';


-- ============================================================================
-- Seed: one demo trip with its members, so the dashboard is not empty.
-- ============================================================================
INSERT INTO trips (name) VALUES ('Munnar Trip 2026');

INSERT INTO travelers (trip_id, name)
SELECT id, 'Ayman'   FROM trips WHERE name = 'Munnar Trip 2026';
INSERT INTO travelers (trip_id, name)
SELECT id, 'Anshad'  FROM trips WHERE name = 'Munnar Trip 2026';
INSERT INTO travelers (trip_id, name)
SELECT id, 'Ramshad' FROM trips WHERE name = 'Munnar Trip 2026';
INSERT INTO travelers (trip_id, name)
SELECT id, 'Safvan'  FROM trips WHERE name = 'Munnar Trip 2026';
INSERT INTO travelers (trip_id, name)
SELECT id, 'Salih'   FROM trips WHERE name = 'Munnar Trip 2026';
INSERT INTO travelers (trip_id, name)
SELECT id, 'Umair'   FROM trips WHERE name = 'Munnar Trip 2026';


-- ----------------------------------------------------------------------------
-- Optional sample data — uncomment to see the split maths in action.
-- Hotel shared by everyone; the jeep safari shared by only three of them.
-- ----------------------------------------------------------------------------
-- INSERT INTO expenses (trip_id, payer_id, description, total_amount)
-- SELECT t.id, tr.id, 'Hotel Stay (2 nights)', 9600.00
--   FROM trips t JOIN travelers tr ON tr.trip_id = t.id AND tr.name = 'Anshad'
--  WHERE t.name = 'Munnar Trip 2026';
--
-- -- Split the hotel evenly across all six members.
-- INSERT INTO expense_splits (expense_id, traveler_id, amount_owed)
-- SELECT e.id, tr.id, 1600.00
--   FROM expenses e
--   JOIN travelers tr ON tr.trip_id = e.trip_id
--  WHERE e.description = 'Hotel Stay (2 nights)';
--
-- INSERT INTO expenses (trip_id, payer_id, description, total_amount)
-- SELECT t.id, tr.id, 'Jeep Safari', 3000.00
--   FROM trips t JOIN travelers tr ON tr.trip_id = t.id AND tr.name = 'Safvan'
--  WHERE t.name = 'Munnar Trip 2026';
--
-- -- Only three of the six went, so only three owe a share (1000.00 each).
-- INSERT INTO expense_splits (expense_id, traveler_id, amount_owed)
-- SELECT e.id, tr.id, 1000.00
--   FROM expenses e
--   JOIN travelers tr ON tr.trip_id = e.trip_id
--  WHERE e.description = 'Jeep Safari'
--    AND tr.name IN ('Safvan', 'Ayman', 'Umair');


-- ============================================================================
-- Verify: every expense's splits must add up to its total, and each trip's
-- balances must sum to zero.
-- ============================================================================
--
--   SELECT e.id, e.description, e.total_amount, COALESCE(SUM(s.amount_owed), 0) AS split_total,
--          e.total_amount - COALESCE(SUM(s.amount_owed), 0) AS difference
--     FROM expenses e
--     LEFT JOIN expense_splits s ON s.expense_id = e.id
--    GROUP BY e.id, e.description, e.total_amount
--   HAVING difference <> 0;      -- must return no rows
