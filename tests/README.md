# farm_financial_mgmt — kernel tests

Regression guard for the Phase 5 invariants. Two kinds of assertion live here,
deliberately kept distinct:

- **Golden-value tests** (`DepreciationEngineTest`): the IRS Pub 946 percentage
  tables are the external spec, so the expected schedules are hardcoded
  constants — the point is to detect drift *away* from the published tables.
- **Relationship tests** (`SingleSourceRelationshipTest`, `ThreeViewsCapitalTest`,
  and the fork in `Form4797RecaptureTest`): the spec is an **equality between two
  code paths**, asserted as a live identity, not a memorized number — so if both
  paths drifted together to a shared-but-wrong value, these still fail. These
  cover the failure mode boundary tests structurally cannot: cross-report
  disagreements (the two the drive found by hand are pinned here).
- **Degradation tests**: assert the engine *refuses* or degrades loudly — an
  unconfigured §179/bonus year surfaces $0/0% with a reason (never a stale year),
  and a 150%-DB mid-quarter year throws rather than computing with an
  untranscribed table. Testing the honest refusals matters as much as the math.

## Running

Kernel tests need the DB host that resolves from inside the container (the
default `db` in `phpunit.xml` does not resolve here — use the DB container name)
and `XDEBUG_MODE=off`:

```
docker exec -w /opt/drupal \
  -e XDEBUG_MODE=off \
  -e SIMPLETEST_DB="pgsql://farm:farm@farmosdev-db-1/farm" \
  farmosdev-www-1 \
  vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/contrib/farm_financial_mgmt/tests/src/Kernel/
```
