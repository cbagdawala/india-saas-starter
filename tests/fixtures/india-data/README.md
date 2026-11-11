# India data test vectors (copied, read-only)

`gst-state-codes.json` and `gstin.json` are GST test vectors (snapshot of 2026-11-11), the
source of truth for
`App\Modules\Invoicing\Support\Gstin` and `GstStateCodes`; `tests/Feature/Invoicing/GstinTest.php` runs every vector.
The kit does not depend on any external data at runtime. To refresh, replace the two files and re-run Pest;
if the state list changed, update `app/Modules/Invoicing/Support/GstStateCodes.php` to match.
