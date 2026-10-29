-- Consumer-owned test fixture. The runtime never executes DDL on boot.
CREATE TABLE intents (
  id bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
  reference uuid PRIMARY KEY, scope text NOT NULL, subject text NOT NULL,
  version bigint NOT NULL DEFAULT 0, data jsonb NOT NULL,
  UNIQUE(scope,subject), CHECK ((data->>'amount_minor')::bigint > 0)
);
CREATE UNIQUE INDEX remote_identity ON intents ((data->>'account_identity'), (data->>'provider_id')) WHERE data->>'provider_id' IS NOT NULL;
CREATE TABLE reconciliation (
  reference uuid PRIMARY KEY REFERENCES intents(reference),
  reconcile_token uuid, reconcile_until timestamptz,
  reconcile_due timestamptz NOT NULL DEFAULT clock_timestamp(),
  reconcile_attempts integer NOT NULL DEFAULT 0
);
CREATE INDEX reconciliation_due ON reconciliation (reconcile_due) WHERE reconcile_attempts<8;
CREATE TABLE operations (
  id bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
  reference uuid PRIMARY KEY, intent_reference uuid NOT NULL REFERENCES intents(reference),
  scope text NOT NULL, account_alias text NOT NULL, kind text NOT NULL, request_key text NOT NULL, data jsonb NOT NULL,
  UNIQUE(scope,account_alias,kind,request_key)
);
CREATE TABLE transactions (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  intent_reference uuid NOT NULL REFERENCES intents(reference), account_identity text NOT NULL,
  remote_id text NOT NULL, kind text NOT NULL, minor bigint NOT NULL CHECK(minor>0), currency char(3) NOT NULL,
  UNIQUE(account_identity,remote_id,kind)
);
CREATE TABLE outbox (
  event_id uuid PRIMARY KEY, intent_reference uuid NOT NULL REFERENCES intents(reference),
  type text NOT NULL, version integer NOT NULL, payload jsonb NOT NULL,
  lease_token uuid, leased_until timestamptz, delivered_at timestamptz,
  attempts integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), next_due timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX outbox_pending ON outbox (created_at) WHERE delivered_at IS NULL;
CREATE TABLE refunds (
  reference uuid PRIMARY KEY, intent_reference uuid NOT NULL REFERENCES intents(reference),
  request_key text NOT NULL, remote_key uuid NOT NULL UNIQUE,
  minor bigint NOT NULL CHECK(minor>0), currency char(3) NOT NULL,
  state text NOT NULL CHECK(state IN ('PREPARED','SENDING','UNKNOWN','PENDING','CONFIRMED','COMPLETED','FAILED')),
  provider_id text, UNIQUE(intent_reference,request_key), UNIQUE(intent_reference,provider_id)
);
CREATE TABLE inbox (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  account_identity text NOT NULL, account_alias text NOT NULL, event_id text NOT NULL, resource_id text,
  state text NOT NULL DEFAULT 'RECEIVED', attempts integer NOT NULL DEFAULT 0,
  lease_token uuid, leased_until timestamptz, next_due timestamptz NOT NULL DEFAULT clock_timestamp(),
  UNIQUE(account_identity,event_id)
);
CREATE TABLE replays (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  request_key text NOT NULL UNIQUE,
  queue text NOT NULL CHECK (queue IN ('inbox','outbox','intents')),
  item text NOT NULL, actor text NOT NULL, evidence text NOT NULL,
  attempts_before integer NOT NULL CHECK (attempts_before>=8),
  replayed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
