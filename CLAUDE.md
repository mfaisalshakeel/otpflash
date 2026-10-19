# CLAUDE.md

Conventions for this codebase. Written from the code that exists, so it
describes what is actually there rather than an intention.

## What this is

A social-media-growth storefront plus admin panel, both complete. Plain PHP 8,
MySQL or SQLite, no framework, no Composer, no build step. It has to run on ordinary cPanel shared
hosting, so: no CLI requirement beyond an optional cron job, no writable paths
outside `config/`, `storage/` and `uploads/`.

## Shape

```
index.php              the only web entry point
cron.php               CLI entry point
app/core/bootstrap.php config, errors, session, security headers
app/core/resolver.php  URL -> controller file
app/helpers/           the function library (see below)
controllers/           one file per URL
views/                 one file per screen
assets/                css + js, no build step
install/               installer, schema, seed
```

## Routing

File-based, no route table.

| URL | runs |
|---|---|
| `/` | `controllers/home.php` |
| `/track` | `controllers/track.php` |
| `/admin` | `controllers/admin/index.php` |
| `/admin/services` | `controllers/admin/services.php` |
| `/admin/services/edit/7` | same file, `$params = ['edit', '7']` |
| `/instagram`, `/instagram/followers`, `/refund-policy` | `controllers/_fallback.php` |
| `/order`, `/order/GK-8F42KD`, `/order/GK-8F42KD/pay` | `controllers/order.php` |
| `/api/track` | `controllers/api.php` |
| `/hook/zurvapay/16/GK-8F42KD` | `controllers/hook.php`, a gateway's own webhook |
| `/sitemap.xml`, `/robots.txt` | named in the resolver, since a dot fails the slug rule |
| `/admin/update`, `/admin/update/plan`, `/admin/update/step` | `controllers/admin/update.php` |
| `/install` | `install/index.php`, before any routing exists |
| anything else | `controllers/_404.php` |

Rules:

- **No URL contains `.php`.** `/install` is a directory with an `index.php`, so
  every server resolves it without a rewrite rule; `/install/index.php` 301s to
  `/install`.
- A URL segment must match `^[A-Za-z0-9_-]+$` or the request 404s. This is what
  stops path traversal — there is no other place to get it wrong.
- `current_path()` works out the mount point from the configured `base_url`,
  then from `DOCUMENT_ROOT` vs `BASE_PATH`, and only falls back to
  `SCRIPT_NAME`. Servers disagree about `SCRIPT_NAME` — PHP's built-in server
  reports the directory index it resolved, Apache reports the rewritten front
  controller — and taking its dirname eats a real URL segment.
- Files starting with `_` are includes, never routes.
- Every `controllers/admin/*` request runs `controllers/admin/_middleware.php`
  first. That is where the login check and the CSRF check live, so individual
  admin controllers never repeat them.
- Controllers are plain PHP files, not classes. They see `$params` and the
  helpers. They end by calling `view()` or `redirect()`.

## Helpers

`app/helpers/functions.php` is loaded for every request and holds the whole
general-purpose set. Add to it rather than writing a one-off inline:

- config / db — `cfg`, `db`, `q`, `one`, `all`, `col`, `insert_row`, `update_row`, `delete_row`
- settings — `setting`, `set_setting`, `settings_all`
- output — `e`, `money`, `qty_fmt`, `excerpt`, `when`
- urls — `base_url`, `url`, `asset`, `redirect`, `current_path`, `is_https`
- views — `view`, `render`, `partial`
- forms — `csrf_token`, `csrf_field`, `csrf_verify`, `old`, `keep_old`, `flash`, `flashes`
- auth — `admin_user`, `is_admin`, `require_admin`
- misc — `slugify`, `random_code`, `client_ip`, `rate_limit`, `rate_limit_hit`, `log_line`

Loaded on demand by the controllers that need them:

- `crud.php` — the shared admin CRUD (`crud_handle`, `options_from`)
- `SmmApi.php` — the only class in the codebase
- `detect.php` — platform/category guessing for the import screen
- `orders.php` — `send_order_to_provider`, `sync_order_statuses`, `map_provider_status`
- `cron.php` — `run_cron_tasks`, shared by `cron.php` and `controllers/cron.php`
- `migrate.php` — `migrations_pending`, `migration_apply`, `migrations_run`
- `update.php` — `update_available`, `update_steps`, `update_run_step`

## Database

- **Two drivers.** `db_driver` in the config is `mysql` or `sqlite`; `db()`
  builds the right connection, and SQLite gets `foreign_keys`, WAL and a busy
  timeout turned on. A config with no `db_driver` means MySQL.
- **Write SQL both understand.** No `NOW()`, no `INTERVAL`, no
  `ON DUPLICATE KEY UPDATE` — bind a `date('Y-m-d H:i:s')` instead of asking the
  database for the time, and spell an upsert out. The schema exists twice,
  `install/schema.sql` and `install/schema.sqlite.sql`; keep them in step.
- Every query goes through `q`/`one`/`all`/`col`, which prepare and bind. There
  is no string interpolation of values into SQL anywhere. Where a column or
  table name is dynamic (the CRUD engine) it comes from a spec written in PHP,
  never from the request.
- `settings` is a key/value table read once per request.
- Order status is an enum: `pending, paid, processing, completed, partial,
  cancelled, refunded, api_error`.
- Foreign keys use `ON DELETE SET NULL` where history matters (an order keeps
  working when its service is deleted) and `CASCADE` where it does not.

## Shared admin CRUD

Simple admin screens describe their table once and `crud_handle()` runs the
list / new / edit / save / delete / toggle cycle against
`views/admin/crud/list.php` and `form.php`. Providers, platforms, categories,
payment methods, pages and FAQs all work this way.

Write a screen by hand only when it needs more than the spec can say — services
(bulk actions), import, orders (detail with actions), settings, messages.

`unique` on a field is unique in the whole table; **`unique:column`** is
unique only among the rows sharing that column's value. Categories are why:
the constraint on them is `(platform_id, slug)`, because every platform is
meant to have its own `/followers`, and a table-wide check would refuse the
second platform. Declaring it is not optional politeness - without it the
database raises the violation and the admin gets an error page instead of
the field saying what is wrong. `crud_save()` also catches a duplicate key
around the write itself and hands the form back, which covers a constraint
a spec never declared and two admins saving the same new slug at once.

Two spec keys are easy to confuse:

- `empty` is only the **label on a select's blank choice**. It says nothing
  about the column.
- `nullable` is what stores NULL when the field comes back blank. Without it a
  blank field falls back to `default`, then to `''`.

Getting that backwards is how "Detect from the balance reply" tried to write
NULL into `providers.currency`, which is `NOT NULL`.

## Service import

`controllers/admin/import.php`.

- The provider catalogue is fetched once and cached in `storage/cache/` for 30
  minutes. "Refresh from API" forces a refetch. If the provider is unreachable
  the cached copy is still shown, with a warning.
- Auto-detect matches the **service name first, the provider's category name
  second** — the name is more specific. Word lists live at the top of
  `detect.php`; matching is whole-word and allows a plural, because providers
  write "Followers" not "Follower".
- Markup sets the price: `cost × (1 + markup/100)`.
- A service already in the catalogue is matched on
  `(provider_id, provider_service_id)`. Re-importing with "update existing"
  refreshes cost, price and limits but keeps the name and description, because
  an admin may have rewritten them.
- **`services.service_type` is the provider's own word** - Default, Package,
  Custom Comments, Subscriptions, whatever they sell - stored as they wrote it
  rather than mapped onto a list of ours, because a type nobody anticipated is
  still the answer to "why does this one behave unlike its neighbours". It is
  refreshed on re-import like the cost, not kept like the name: it is their
  fact. The Services screen shows it as a chip and filters on it, and the
  filter offers only the types actually present plus **No type given**, which
  is how the services somebody added by hand are found. The form takes it as a
  free text box with suggestions, for exactly those.

## Themes

- A theme is a folder under `themes/` with `theme.php` beside `views/` and
  `assets/`. Nothing keeps a list — `themes_available()` scans the directory —
  so adding one never means editing a file that already exists.
- `render()` looks in the active theme's views, then the **default theme's**,
  then `app/views`. A theme therefore ships only the views whose structure
  differs and inherits the rest; `viralborn` ships four files and gets the
  order page, the tracking page and the modal from `default`.
- Because inherited views come with the default theme's class names, a theme's
  stylesheet has to cover that whole vocabulary. Both shipped themes are
  written in the same order so they read side by side.
- `theme_asset()` falls back the same way, so a theme with no `app.js` of its
  own still gets one — which is why a theme must keep the ids the script
  needs: `#platStrip`, `#catTabs`, `#serviceCards`, `#svcTitle`, and the
  card's `data-` attributes. `#svcTitle` is rewritten with `textContent`, so
  it may not hold markup that has to survive a switch. `#heroCta` is optional
  — the shipped hero has no button and the script skips it — but a theme that
  adds one keeps the id and keeps it to plain text, for the same reason.
  The hero headline is **not** in that list: it is one fixed sentence, so
  nothing rewrites it and a theme may say whatever it likes there.
- **The hero is one partial**, `partials/hero.php`, rendered by both themes:
  the band, the drawn phone and the floating chips are the same shape
  whatever the colour, and the colour is mixed from `--brand` by whichever
  stylesheet is loaded. A theme that wants a different hero drops in its own
  copy of that partial, which is the same inheritance as every other view.
  **It carries the headline and four points** — no badge, no sentence of
  promises, no button. The headline is one fixed sentence rather than the
  platform and category being viewed: those are already named by the lit
  tab, the heading over the cards and the cards themselves, and saying it
  again in the largest type only pushed the platform bar further down. The
  points are two words each because the prose line they replaced was three
  lines of reading on a phone between the headline and that bar, which is
  the choice the whole page hangs off and so should be first to tap.
  `support_channel_line()` writes the fourth one's second line from the
  channels that are actually switched on — "On Telegram" under a headline is
  a promise, and a shop with no Telegram would be making it to everyone.
- A page that opens on the hero gives the header to the band — no bar, no
  line, white type on the colour. `body:has(.hero) > header` asks the
  question the layout cannot, because the layout renders the header before it
  knows what the page is; a browser without `:has()` keeps the white bar,
  which is a plain page rather than a broken one.
- A manifest may declare `brand`, the colour the theme was drawn around.
  Activating a theme adopts it — but only while the colour on record is still
  the **previous theme's** own, so a colour the admin chose is never
  overwritten.

## Service packages

- **A service with no packages still shows quantity cards.** `service_tiers()`
  falls back to a ladder of round numbers (`QUANTITY_LADDER`), trimmed to what
  the provider accepts and spread across the range, priced at the service's own
  rate. This is what makes a fresh install look like the reference without an
  admin hand-entering five rows per service; `auto_packages` in Settings turns
  it off. A generated tier carries **no id**, so the order posts a quantity
  alone and the server prices it from the rate — the same number the card
  showed. *Generate from the service* on the Packages screen writes the ladder
  out as real rows so the prices can then be edited.
- What tells a fixed-quantity card from a typed one in the script is whether
  it **has a quantity input**, not whether it has a package id — a generated
  tier is fixed and has no package behind it.
- A service can be sold in preset packages — "500 followers for 165" — as well
  as by quantity. A package carries **its own price**, not a rate, which is the
  whole point: a bigger package can genuinely cost less per thousand, and the
  admin list shows the per-thousand figure so that discount is visible.
- `bonus_quantity` is delivered free on top. The order's quantity is
  `quantity + bonus_quantity`, because that is what the provider is asked for;
  the cost is still the rate times that, so margin stays honest.
- **`catalogue_mode` decides what a category sells.** `services` (the default)
  shows every service in the category as its own card. `single` shows only the
  service the category names in `categories.service_id`, so the customer picks
  a quantity rather than a service — the way the big shops do it. A category
  that names none falls back to its first active service rather than rendering
  an empty tab.
- The front cards come from `partials/cards.php`: one card per package for a
  service that has them, the ordinary quantity card for a service that does
  not, so nothing ever disappears from the grid.
- The packaged services then share **one** custom-quantity panel underneath,
  with a service picker when there is more than one. A "custom" card per
  service sitting among the tiers gave no way to tell which service it
  belonged to. The panel is an ordinary `.card`, so choosing a service
  rewrites its `data-` attributes rather than re-rendering: the price maths
  and the order modal keep working on it unchanged.
- `order.php` reads the price **back from the package row**. The quantity the
  browser sends for a package order is not even looked at, and a package id
  that does not belong to the service is refused.

## Currency on the import screen

- A provider's catalogue is priced in **its own** currency. Every figure the
  import screen shows is converted with `currency_rate($provider['currency'])`
  before it is printed, because otherwise it is the provider's number wearing
  our symbol — which is worse than showing nothing.
- No rate means **no listing**. The screen says which currency is missing and
  offers to add it and fetch today's rate, then re-reads the catalogue. A
  provider that never said what it bills in is offered Check instead.
- `currency_add()` inserts the row and calls the rate feed; `KNOWN_CURRENCIES`
  only exists so it arrives with a name and a symbol rather than three bare
  letters.

## Explaining the settings to the admin

Two settings between them decide whether a service is shown at all and what
shape it takes, and neither is visible from the screen you are standing on.
`catalogue_explainer()` writes that in words **once**, and Services and Import
both render it through `admin/_explain` — so the two screens cannot drift into
describing the same system differently. The note opens the first time and
stays shut after, remembered per screen.

## Service names on the storefront

A card carries two names: `data-name`, the raw one the provider gave, and
`data-label`, the words a person reads. **The customer only ever sees the
label**, and `card_label()` decides what it is, because the two catalogue
modes ask different questions:

- `single` — the category names one service and the customer picks only a
  quantity. Every card is the same service, so its raw name says nothing and
  "Instagram Followers" is the honest label.
- `services` — every service gets its own card, and the name is now the only
  thing telling one card from the next. Hiding it leaves a grid of identical
  headings, which is what it did until a label fixed to platform + category
  was pointed out.

The rule lives in one function so the three places that draw a card - the
package card, the service card and the custom panel's picker - cannot drift.
The order modal clamps the label to two lines: the card behind it carries the
whole name, and four lines there push the order button off a phone.

## Card feature lines

The ticked points on a card come from `service_feature_lines()`: the service's
own `features`, else `default_features` from Settings. A freshly imported
service therefore has ticks on it without anyone typing them. Set them for
many services at once from the Services screen's bulk bar; an empty box clears
them, which puts those services back on the default set.

## The storefront

- `controllers/home.php` renders `/`, `/{platform}` and `/{platform}/{category}`
  — `_fallback.php` looks the rows up and leaves them in `$GLOBALS`.
- Only platforms, categories and services that are active are shown, and a
  category with no active service is left out of the tabs entirely.
- The platform strip and the category tabs are **real links**, so the site works
  and is crawlable without JavaScript. `assets/js/app.js` only adds the live
  price and the order modal; the order form posts normally when it is blocked.
- Meta title and description come from the category, then the platform, then a
  sensible generated line. Every page sets a canonical URL.
- The theme colour from Settings is written into `--brand` in the layout.

## Order flow

`pending → paid → processing → completed`, with `api_error` parked to one side.

- **Placing finishes in the modal.** The form posts over `fetch`, `order.php`
  answers with JSON, and the modal swaps to a success panel carrying the order
  code and a copy button. The code is the only thing the customer has to keep,
  so it is the one thing the panel makes easy to take away. The panel sits
  **after** `</form>`: showing it hides the form, and a panel inside the form
  would be hidden along with it.
- With exactly one active redirect gateway there is nothing to choose, so the
  panel offers it straight away; with two or more it links to the order page,
  where the choice lives. `sole_redirect_method()` is that test.
- **No payment method means no order.** The form refuses before it writes a
  row, because an order nobody can pay for is a row nobody will ever action.
- The name is copied onto the order as `service_label` when it is placed, not
  looked up later. A label read back through the service row would change
  under a finished order when an admin renames the service, and single-service
  mode has to keep its promise on the tracker too.
- `send_order_to_provider()` is the only place an order is handed to an API. A
  failure stores the provider's own words in `api_error` and leaves the order
  where it is, so it can be retried.
- A service with no provider is manual: the order moves to `processing` and a
  person delivers it. No API call is made.
- `map_provider_status()` turns provider wording into our enum. **An unknown
  status maps to `processing`, never to `completed`** — never silently finish an
  order we are not sure about.
- `sync_order_statuses()` groups open orders per provider and uses the
  multi-status call, so a hundred open orders is a handful of requests.
- Whether a provider answers a batch is read from the **shape** of its reply,
  not its contents: keyed by order id means yes, a flat status object means it
  read `order` and ignored `orders`. `multi_status_probe()` therefore needs no
  real orders — it asks about ids that will not exist — so a provider can be
  checked the moment it is added. The one reply it cannot read is a flat error
  for a batch of unknown ids, which is also what a batching provider says; that
  answers "cannot tell yet" and records nothing rather than guessing. Providers
  has its own **Multi-status** button, and Check asks as well.
- Every state change writes an `order_logs` row.

## Contact channels

The shop is reachable on WhatsApp, Telegram or email, and **which button goes
where is a setting**. `chat_channels($placement)` in `functions.php` answers
that once; the views only draw what comes back, so no theme decides it and no
two places can disagree.

- `CHAT_CHANNELS` is the three channels and where each one's address lives.
  A blank address means the channel is not set up, so `chat_channel()` returns
  null and nothing is drawn - there is no second "is it configured" test
  anywhere.
- `CHAT_PLACEMENTS` is the five places, and **it carries the defaults**. They
  are what the site did before any of this existed, so an install that never
  opens the screen looks unchanged - and one whose database is behind the code
  reads them from here rather than from a row that is not there yet. That is
  the same rule as the rest of the panel: nothing drawn on every page may
  assume a migration has run.
- A saved row that is **empty** means "no button here". So the default only
  applies when the row is absent altogether, which is why
  `chat_placement_keys()` looks at `settings_all()` rather than taking
  `setting()`'s fallback.
- The admin screen needs the chosen keys whether or not each channel has an
  address yet - it draws Telegram ticked and greyed, because "off because you
  have not given a username" is the answer the admin needs. That is why the
  keys and the ready-to-draw channels are two functions.

**No button is named after its app.** A customer is choosing a way to reach
us, not a brand, and a shop that changes messenger should not have to find
every button that named the old one. The icon says which, and the words live
in `title` and `aria-label` - the same trade the admin's icon row actions
make. The burger menu is the one exception: it has no icons, so there each
channel is named.

`label` is the word on the button and **an empty one is deliberate** - that
button is its icon alone, which is why every view that prints a label checks
for it first. Telegram's is empty: two pills both reading "Chat" say nothing
the icons have not. `title` is separate from it for the same reason, so an
unlabelled button still has a name to announce.

The floating bubbles are round and wordless at every width - a fixed square
with `border-radius:50%`, not a pill that happens to hold one icon, because
the stack has to stay a stack of equal shapes once there is more than one
channel in it.

Telegram's `t.me` links cannot carry a prefilled message, which is why
`chat_channel()` takes one and uses it only where the channel supports it, and
why the order code is on screen with a copy button either way.

## Adding a provider

**A provider is tested before it is saved.** `provider_probe()` asks for the
balance and touches no table, so the add form can prove the key works before
there is a row - a provider whose key is wrong is otherwise a row that accepts
services, takes orders and fails every one of them at the moment the money has
already changed hands.

- Only a **refused connection** stops the save. A billing currency with no
  exchange rate, or a batch-status question that cannot be answered until an
  order exists, are things to go and do - not reasons to throw away a key the
  provider just accepted. `connected` is that distinction; `ok` is "everything
  came back clean".
- The same call brings back the balance, the currency and the batch-status
  answer, so a provider that passes is **saved already knowing all three**.
  What the provider said fills the boxes left blank and never overwrites a
  currency the admin picked by hand, which is why the probe is told what was
  in that box. `supports_multi_status` is the one finding the admin can
  overrule, because "work it out automatically" is that select's blank choice.
- `provider_check()` is the same probe plus the write, so the Check button and
  the add form cannot drift into testing different things.
- Editing re-tests **only when the URL or the key changed**. On a shared host a
  slow provider is most of the request, and changing a sort order should not
  cost one.

## Payment gateways

A gateway is **one file in `app/payments/`** returning a manifest, and
`payment_gateways()` scans the folder - dropping a file in registers it, so
adding one never means editing a file that already exists. The admin screen
renders whatever `fields` the manifest declares into the method's `config`
JSON, which is why it needed no change for either gateway shipped after it.

Only `name` is required. `kind` is `manual` (show an account, take a
reference, a person confirms) or `redirect` (the gateway takes the payment).
Everything else is optional and falls back to the manual behaviour.

Two of the optional callbacks go together, and they are what a gateway writes
when the payment can be **watched instead of typed**:

- `qr` returns the text to encode and the amount to print. An `error` instead
  of them means there is nothing honest to draw, and the page says so rather
  than showing a QR that collects the wrong number.
- `poll` answers `paid`, `failed` or `waiting`. **Anything a gateway does not
  recognise is `waiting`, never `paid`** - the same rule as
  `map_provider_status()`, for the same reason: waiting too long costs a
  question, guessing costs the delivery.

`start` then redirects to `/order/{CODE}/qr`, which is ours: the QR is drawn
by `qr_svg()` and the page asks `/order/{CODE}/check` every few seconds, one
request at a time. Without the script that page is a button that does the
same thing in one round trip.

`confirm_order_payment()` in `helpers/orders.php` is the **only** place a
gateway's yes turns into a paid order. It claims the status rather than
checking it first, because the page polling in two tabs and cron arriving
together would otherwise both mark it paid and both set off a send. It is
also what the `check_payments` cron task calls, which is what catches the
customer who paid and closed the tab.

A gateway that can call us instead declares `ipn` as well, and
`/hook/{gateway}/{method-id}/{ORDER-CODE}` is where it calls. The URL carries
all three facts the request has to be read against, and `payment_ipn_url()`
builds it when the payment is created, so there is nothing to register by
hand - the admin screen only prints it because a gateway may want the domain
approved first.

**A webhook is a faster poll, not a second way of deciding.** The gateway's
`ipn` answers one question - is this call really yours and really about this
order - as `check`, `ignore` or `reject`; `/hook` then reads the money with
`payment_poll()`, the same call the QR page and cron make, and finishes
through `confirm_order_payment()`. So the amount test and the short-payment
rule are written once however the news arrives, and a webhook landing while
a poll is in flight is the race `confirm_order_payment()` already claims its
way out of. Anything a gateway cannot authenticate is a plain 404, the same
rule as the cron URL, and the one answer that is **not** 200 is "signed, but
the gateway's own API does not say paid yet" - because a non-200 is what gets
us called again.

Paytm Business is the worked example: a UPI intent in the QR carrying the
order code in `tr`, and Paytm's order-status endpoint asked about exactly
that id. **A reported amount short of the order's is not a payment** - the
order stays where it is with both figures in its log. TLS verification stays
on; a payment status read over a connection nobody checked is one anybody in
the way can write.

## Versions and updating

- The release version lives in **one place**, `app/core/version.php`.
  `bootstrap.php` turns it into `APP_VERSION`; the installer reads the same
  file without booting the app. Bump it in the same commit as the migration
  that needs it.
- The database carries its own copy in the `app_version` setting. When the two
  differ, or a migration in `install/migrations.php` has not run, the admin
  shows **Update available** — a banner on every screen and a dot on the
  sidebar item.
- `/admin/update` does the work **one step per request**: the browser asks for
  the plan, then posts each step and draws the progress bar. A shared host
  cuts a request off at thirty seconds and a migration that rewrites a table
  is exactly what gets cut. `/admin/update/run` is the same list in one POST,
  for a browser that cannot run the stepper.
- The installer works the same way, for the same reason: `?step=2&task=plan`
  returns the task list, `?step=2&task=<key>` runs one. Submitting the form
  without JavaScript runs the identical list in one request.
- **The admin has to open on a database that is behind the code.** Uploading
  the files is the first half of an update and running the migrations is the
  second, and in between the panel is the only way to reach the button. So
  nothing drawn on every screen may assume a table or column that a migration
  adds: the sidebar counts go through `optional_count()`, which returns 0 for
  a missing table and re-throws anything else. A screen whose *own* table
  arrives with a migration is listed in `_middleware.php` and redirects to
  `/admin/update` rather than failing. Cron stops with a plain message instead
  of half-running against money. The storefront keeps selling throughout -
  `notify()` already swallows its own failures.

  This is not hypothetical: an `audit_log` count added to the layout took the
  whole panel to a 500 the moment anyone pulled the release, and the only
  screen that could have fixed it was the one that would not load. Check it
  before a release by pointing the new code at a copy of the old database.

- A step that fails because the thing it adds is already there is **skipped,
  not an error** — SQLite has no `ADD COLUMN IF NOT EXISTS`, and that is how
  both drivers end up behaving the same way. `migration_already_done()` holds
  the list of phrases; MySQL and SQLite word them differently, so both
  wordings have to be in it.

## The dashboard

- The figures live in `app/helpers/stats.php`, not the controller, because each
  one is a decision about what counts and they have to agree with each other.
  The rule throughout: an order is money only once it is paid, so `pending`,
  `cancelled` and `refunded` are out of every revenue, cost and profit figure
  while still counting as orders.
- **Never put the status list in a statement twice.** Placeholders bind by
  position, so a second `status IN (?, ?, …)` in the same query silently shifts
  every later binding and the answer comes back zero — no error, just wrong.
  Two queries, one clause each.
- Dates are worked out in PHP and bound. MySQL and SQLite disagree about
  `INTERVAL`.
- Charts are inline SVG from `app/helpers/charts.php`; there is no build step
  and a dashboard is not worth breaking that for. **One measure per chart, so
  one colour per chart** — orders and revenue are two charts, never one plot
  with two y-scales, because the alignment between two scales is arbitrary and
  invents a relationship the data does not have.
- Every chart folds the same numbers underneath as a table. A value you can
  only reach by hovering is a value some readers cannot reach at all.
- Bars for named things are one hue. Shading each bar by its own length would
  spend the only free channel restating the length.

## Admin components

Three pieces of chrome are drawn by us rather than by the browser, and all
three work the same way: **the native control stays in the DOM and stays the
thing that submits.** The enhancement is drawn over it, so a browser that
never runs the script gets the plain control, styled.

- **`admin/_upload`** — an image field. The file input is moved out of sight
  and driven by its label, which is what a file input is built to allow. Adds
  a drop target and a preview.
- **The searchable select** — every `<select>` in the admin gets a button and
  a popup; the search box inside appears once there are enough options to
  scroll, or where the markup says `data-search` (a CRUD field spec asks with
  `'search' => true`). Choosing writes back to the real select and dispatches
  `change`, so a filter form still submits itself.
- **Tabs** — panels are only hidden once the script runs, so without it the
  page is the long column it used to be. Every field stays in the DOM
  whichever tab is showing: hiding a panel must never mean dropping what is
  in it.

Row actions are **icons**, not words: power, pencil, trash, plus whatever a
screen adds of its own. Colour carries what the button does — red is the
destructive one — and the words live in `title` and `aria-label`, so a row of
them can be read at a glance and still read aloud. A row of identical grey
text buttons gave no way to find the dangerous one.

**A class in the markup with no rule behind it renders as nothing**, which is
invisible in a diff and obvious on the screen: the Notifications empty state
shipped as loose text beside its own box, and the whole Themes screen as an
unstyled column, because `.empty-state` and `.theme-*` existed only in the
theme stylesheets. `php tools/check-admin-css.php` lists every admin class
with no rule and exits non-zero; run it before a release.

`[hidden]` is declared `display:none !important` in both the admin and the
theme stylesheets, because a rule that sets `display` otherwise wins and the
attribute silently does nothing.

## Security

These are the rules the code already follows; keep them.

- **Output:** everything printed in a view goes through `e()`. The two
  exceptions are deliberate and commented: page content and `head_code`, both
  written by an admin.
- **SQL:** prepared statements only, via the helpers.
- **CSRF:** every non-GET admin request is checked in `_middleware.php`. Forms
  use `csrf_field()`. A bad token ends the request with 419.
- **Passwords:** `password_hash` / `password_verify`, rehashed on login when the
  algorithm moves on.
- **Sessions:** `session_regenerate_id(true)` on login and on password change.
  Cookies are `httponly`, `samesite=Lax`, and `secure` over HTTPS.
- **Login:** throttled per IP, 8 failures per 10 minutes. Successful logins do
  not count against the budget. The failure message never says which field was
  wrong.
- **Uploads:** accepted only if `getimagesize()` can read them, saved with an
  extension we chose, and nothing in `uploads/` is ever executed.
- **A `.htaccess` directive the server does not understand is a 500**, not a
  line it skips - and a 500 in `uploads/` is every image on the site gone.
  So `php_flag engine off` lives inside `<IfModule mod_php.c>` guards: it is
  an unknown directive where PHP runs as FastCGI, PHP-FPM or LiteSpeed, which
  is most cPanel hosting now. **`php_admin_flag` and `php_admin_value` are
  refused in a `.htaccess` on every server**, module or not, so they belong
  in a vhost and never in this repository. `uploads/.htaccess` therefore says
  the same thing three ways - the guarded flag, `RemoveHandler`, and a
  `FilesMatch` deny - and only the last one works without knowing how the
  host wired PHP up, which is why it is the one that has to be right.
  Everything else in a shipped `.htaccess` is guarded by the module that
  understands it. `Options` cannot be, so it carries a comment saying it is
  the line to drop if a host refuses it.
- **Installer:** refuses to run once `install/install.lock` exists.
- **Cron URL:** `/cron/{cron_key}` compared with `hash_equals`; a wrong key is a
  plain 404, so the endpoint cannot be probed for.
- **Paths:** `app/`, `config/`, `storage/`, `themes/*/views` and
  `install/*.{sql,lock}` are denied in `.htaccess` - as rewrite rules, which
  are relative to the directory and so hold for a panel in a subfolder, and
  again through `mod_alias` for a server with no `mod_rewrite`. Each of those
  folders also carries its own deny file, because a rule in a parent is one
  careless upload away from being gone. The nginx equivalent is in the
  README, where `/install/` is **not** denied wholesale: the installer is how
  the panel is set up.
- **Money:** the order price is recalculated from the service row on the server
  every time. A price posted by the browser is ignored.
- **Links:** when a platform sets `url_prefix`, an order's link must be on that
  host, so an Instagram service cannot be ordered with a TikTok link.
  `link_is_on_host()` is the rule: the host must **be** that host or a
  subdomain of it. "Contains" is not good enough — `instagram.com.example.net`
  contains `instagram.com`. The browser applies the same rule as you type, but
  only to answer sooner; the server still decides.
- **...and the right kind of link on it.** Views are counted on one reel, so a
  reel order placed with a profile link is an order the provider cannot fill.
  **A link rule is a row**, in `link_rules`, not a line in the code: a name, a
  pattern, an example and the words to refuse with. A category points at as
  many as it accepts, in `categories.link_rules`, and **any one matching is a
  link we take** — which is what lets one tab sell reel views and post views.
  The rows this release ships with are the shapes it used to hold in code;
  they arrive seeded and are editable, switchable and deletable like anything
  else, and `/admin/link-rules` is where an admin writes the next one.

  `match_type` is `simple` or `regex`. Simple is the admin's language — the
  path with `*` for anything, matched anywhere in it, so `/reel/` takes every
  reel link there is — and it is quoted with `preg_quote()` so a dot is a dot.
  `regex` is the way out for the seeded rows and for an admin who means it;
  the form compiles whatever is typed before it saves, because a pattern that
  will not run refuses every link on the shop and explains nothing. The form
  also carries a **copy-ready prompt**: paste a link on the end of it, give it
  to an AI, and the answer drops into `pattern` with `match_type` on `regex`.

  **`link_rule()` is the one answer** to "what links does this category take":
  the field's label, its example, the sentence it refuses with, the regular
  expression the browser runs as you type and the check the server does
  afterwards all come back from that one call, so they cannot disagree. The
  combined pattern is one alternation, so the browser asks exactly what the
  server will ask. A category that has chosen no rules falls back to the kind
  read from the service name and then its own name — what every category did
  before rules were rows — so a shop that never opens the screen is unchanged.
  A platform nobody wrote a rule for is not checked at all: an admin adding
  their own platform gets the host rule and nothing in the way. `link_attrs()`
  is the one place a card says all of this, because three partials draw a card
  and a rule written three times drifts.

- **Spam:** the order and contact forms each carry a hidden honeypot field and
  a per-IP throttle.

## Style

- Comments explain **why**, not what. No comment that restates the line below it.
- Function and variable names are words, not abbreviations.
- Views contain presentation only; anything that needs a decision belongs in the
  controller or a helper.
- Keep the design tokens in `assets/css/admin.css` (`:root`) — do not hard-code
  colours in markup.
- British or American spelling, but match the file you are in.

## Design

The admin follows direction A in `mockups/admin-a.html` (light sidebar, indigo
accent). The front site will follow one of `mockups/front-*.html`. The mockups
stay in the repository as the visual reference; they ship with no PHP and are
not part of the deployed site.

## Releasing

`bash tools/build-release.sh` stages only what the live site needs and zips it
into `build/`. `mockups/` and `tools/` stay in the repository but never ship -
`tools/mock-provider.php` must not reach a real server. The script fails loudly
if anything on that list sneaks in.

## Testing

No test framework. The checks that matter:

```bash
# syntax
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# admin markup using a class the stylesheet never defines
php tools/check-admin-css.php

# a real run
php -S localhost:8000 tools/dev-router.php     # the app
php -S localhost:8001 tools/mock-provider.php  # a fake provider
```

Use the router script. Without it `php -S` answers `/sitemap.xml` and
`/robots.txt` from disk, finds no such file and returns 404, while Apache
rewrites both to the front controller — so the two servers disagree about
exactly the URLs the resolver names specially.

Run the install-to-completed pass **on both drivers**. The two bugs that only
one of them showed were `NOW()` left in the installer's admin INSERT, which
SQLite has no function for, and `current_path()` trusting `SCRIPT_NAME`, which
only broke once a router script changed what the server reported.

Then: install from an empty database, add the mock provider, import, place an
order, mark it paid, run `php cron.php` a few times and watch it complete. An
order whose link contains `fail-me` exercises the `api_error` path.

Then the upgrade path, which is the one the working copy never exercises:
take a copy of the previous release's database, point the new code at it, and
open every admin screen before running the update. The panel must stay usable
while the database is behind, because `/admin/update` lives inside it.

Before a release, do that run against the **zip**, not the working copy -
unpack `build/*.zip` somewhere clean and install it from zero. The one bug that
only showed up that way was a CRUD form field missing from a POST becoming NULL
in a NOT NULL column, which the working copy never hit because its forms always
carried every field.
