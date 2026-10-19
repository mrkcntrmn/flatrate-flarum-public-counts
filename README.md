# flatrate/flarum-public-counts

Read-only Flarum 1.8.19 count of guest-visible discussions and authored replies for one closed UTC week.

The count uses `Discussion::whereVisibleTo(guest)` and `Post::whereVisibleTo(guest)`. It does not replace those scopes with `hidden_at IS NULL`. A reply must be a comment whose Flarum `number` is greater than 1, so the opening comment and event posts are excluded. Post visibility already requires the parent discussion to be visible.

The result is what a guest can see at the time of the read, grouped by creation time. It is not a reconstruction of visibility at the historical week close.

`POST /api/flatrate/public-counts` accepts only `startUtc` and `endUtc`, exactly seven days apart, and requires `FLATRATE_PUBLIC_COUNTS_TOKEN`. The response is the two integers and a snapshot receipt.

Disposable coverage is `tests/integration/PublicVisibleWeekCountsTest.php` against Flarum core, tags, flags, and approval. `fof/byobu` is not part of the installed disposable package set. Private discussions are Flarum's `is_private` flag. Approval hides unapproved rows by marking them private.
