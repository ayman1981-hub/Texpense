-- ============================================================================
-- Migration 002 — multi-trip support + settlements
--
-- Run ONCE against the EXISTING travel_tracker database (do NOT re-run
-- schema.sql — it drops the database):
--
--     mysqldump -u root travel_tracker > backup_before_trips.sql     -- back up first
--     mysql -u root travel_tracker < migration_002_multi_trip.sql
--
-- Existing travellers and expenses are adopted into one "Default Trip", so no
-- data is lost. Every statement here is a one-shot DDL change: MySQL 8 has no
-- ADD COLUMN IF NOT EXISTS, so re-running will error on the first ALTER.
-- ============================================================================

USE travel_tracker;

-- ----------------------------------------------------------------------------
-- 1. trips
--    name is not UNIQUE: the same trip name may legitimately repeat across
--    years ("Goa Trip"), and the id is what everything else references.
-- ----------------------------------------------------------------------------
CREATE TABLE trips (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(150) NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci
  COMMENT = 'One row per trip / group of travellers';

-- The trip that adopts the data already in the database.
INSERT INTO trips (name) VALUES ('Default Trip');


-- ----------------------------------------------------------------------------
-- 2. travelers.trip_id
--    Added NULL first so the backfill has somewhere to write, then tightened
--    to NOT NULL once every row has a trip.
-- ----------------------------------------------------------------------------
ALTER TABLE travelers
  ADD COLUMN trip_id INT UNSIGNED NULL AFTER id;

UPDATE travelers
   SET trip_id = (SELECT id FROM trips ORDER BY id ASC LIMIT 1)
 WHERE trip_id IS NULL;

ALTER TABLE travelers
  MODIFY COLUMN trip_id INT UNSIGNED NOT NULL,
  -- A trip holding travellers cannot be deleted by accident (no CASCADE).
  ADD CONSTRAINT fk_travelers_trip
      FOREIGN KEY (trip_id) REFERENCES trips (id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
  -- Two people on the same trip may not share a name.
  ADD UNIQUE KEY uq_travelers_trip_name (trip_id, name),
  -- Redundant as a key (id is already unique) but REQUIRED as the target of
  -- the composite foreign keys below; it also serves trip_id-only lookups.
  ADD UNIQUE KEY uq_travelers_trip_id (trip_id, id);


-- ----------------------------------------------------------------------------
-- 3. expenses.trip_id
--    Backfilled from the payer's trip, which is the only link that existed.
-- ----------------------------------------------------------------------------
ALTER TABLE expenses
  ADD COLUMN trip_id INT UNSIGNED NULL AFTER id;

UPDATE expenses e
  JOIN travelers t ON t.id = e.payer_id
   SET e.trip_id = t.trip_id
 WHERE e.trip_id IS NULL;

ALTER TABLE expenses
  MODIFY COLUMN trip_id INT UNSIGNED NOT NULL,
  -- The dashboard's hot path is "this trip, newest first" in one index.
  ADD KEY idx_expenses_trip_date (trip_id, created_at),
  -- Superseded by fk_expenses_trip_payer below: one composite key proves the
  -- payer belongs to the same trip as the expense, which a single-column
  -- payer_id key can never express.
  -- If this database was built by hand rather than from schema.sql, check the
  -- real name first with `SHOW CREATE TABLE expenses;` and substitute it here —
  -- a wrong name aborts this whole ALTER TABLE.
  DROP FOREIGN KEY fk_expenses_payer,
  ADD CONSTRAINT fk_expenses_trip
      FOREIGN KEY (trip_id) REFERENCES trips (id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
  -- Makes a cross-trip expense (payer from another trip) impossible to store,
  -- not merely unlikely: 'expense in trip A paid by someone in trip B' is a
  -- data corruption the application should never have to defend against.
  ADD CONSTRAINT fk_expenses_trip_payer
      FOREIGN KEY (trip_id, payer_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE;


-- ----------------------------------------------------------------------------
-- 4. settlements — the ledger behind the "Settle Up" button.
--
--    A settlement is NOT an expense: it must not change the trip total or
--    anyone's share, or settling up would move the very numbers being settled.
--    So it lives in its own table as a directed cash transfer between two
--    travellers, and balances are:
--
--      balance = (expenses paid) + (settlements sent) - (settlements received)
--                - (fair share of the trip)
--
--    Summed over everyone that is always exactly zero, because every transfer
--    both credits the payer and debits the recipient.
-- ----------------------------------------------------------------------------
CREATE TABLE settlements (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id          INT UNSIGNED  NOT NULL,
  from_traveler_id INT UNSIGNED  NOT NULL,   -- the debtor handing over cash
  to_traveler_id   INT UNSIGNED  NOT NULL,   -- the creditor receiving it
  amount           DECIMAL(10,2) NOT NULL,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Composite keys keep both sides of a transfer inside the same trip.
  CONSTRAINT fk_settlements_trip_from
      FOREIGN KEY (trip_id, from_traveler_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_settlements_trip_to
      FOREIGN KEY (trip_id, to_traveler_id) REFERENCES travelers (trip_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT chk_settlements_amount_positive CHECK (amount > 0),
  -- Paying yourself is never a settlement.
  CONSTRAINT chk_settlements_distinct_party CHECK (from_traveler_id <> to_traveler_id),

  KEY idx_settlements_trip (trip_id),
  KEY idx_settlements_from (from_traveler_id),
  KEY idx_settlements_to (to_traveler_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci
  COMMENT = 'Cash transfers that clear balances without changing trip cost';


-- ----------------------------------------------------------------------------
-- 5. Verify
-- ----------------------------------------------------------------------------
-- Every traveller and expense should now belong to a trip, and nothing should
-- have been left behind:
--
--   SELECT (SELECT COUNT(*) FROM travelers WHERE trip_id IS NULL) AS travelers_without_trip,
--          (SELECT COUNT(*) FROM expenses  WHERE trip_id IS NULL) AS expenses_without_trip;
--
--   SELECT t.id, t.name,
--          COUNT(DISTINCT tr.id) AS travelers,
--          COUNT(DISTINCT e.id)  AS expenses,
--          COALESCE(SUM(DISTINCT e.amount), 0) AS total
--     FROM trips t
--     LEFT JOIN travelers tr ON tr.trip_id = t.id
--     LEFT JOIN expenses  e  ON e.trip_id  = t.id
--    GROUP BY t.id, t.name;
