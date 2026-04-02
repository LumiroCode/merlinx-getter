# Decision log
- **App-level soft cap (100 items)**: once the merged offer list reaches 100 items, further bookmark follow-through is suppressed; already-fetched items are never trimmed. This cap applies only to explicitly requested view limits — materialized defaults do not gate follow-through.
- **API-level hard limit (MerlinX query)**: missing/invalid view limits are materialized to `100` in outgoing `/v5/data/travel/search` queries as a required request parameter, independent of the app-level soft cap.
- Bookmark follow-through is response-driven and stops on: missing/repeated bookmark, empty items, explicit-limit reach, or hard page cap.
- Retry mechanism is shared across MerlinX endpoints.
- Operations pass retry/error context only via `MerlinxHttpClient::request(..., array $context = [])`; operations must not import `Http/Auxiliary` or `Http/Models` internals.
- MerlinX `offerList.items` is normalized by full `offer.Base.OfferId`; duplicates preserve first-seen order and later payloads only fill gaps.
- `portalSearch()` contract target is `https://www.skionline.pl/wxp/?p=ofertyResultsJson`; tests and docs must remain aligned.
- `portalSearch()` retries `TimeoutExceptionInterface` once, then returns safe fallback JSON; only non-timeout transport failures raise `HttpRequestException`.
- Engine-owned response exclusions (`search_engine.response_filters.exclude_values_by_path`) are applied pre-merge and must not be reintroduced as MerlinX query variants.


