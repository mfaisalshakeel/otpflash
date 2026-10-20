# CLAUDE.md

Conventions for this codebase. Written from the code that exists, so it
describes what is actually there rather than an intention.

## What this is

OTP Flash: a shop that resells virtual numbers, temporary email inboxes and
rented numbers from [OTPGet](https://otpget.com/docs), with a customer portal,
a blog and an admin panel. Plain PHP 8, MySQL or SQLite, no framework, no
Composer, no build step. It has to run on ordinary cPanel shared hosting, so:
no CLI requirement beyond an optional cron job, no writable paths outside
`config/`, `storage/` and `uploads/`.

It was built from the codebase of an SMM panel. The machinery that is not about
what is sold - installer, updater, CRUD engine, payments, notifications, audit
log, themes, chat buttons - came across unchanged; providers, platforms,
categories, packages, import and link rules did not.

## Shape

```
index.php                 the only web entry point
cron.php                  CLI entry point
app/core/                 bootstrap (config, errors, session, headers), resolver, version
app/helpers/              the function library (see below)
app/controllers/          one file per URL; admin/ is the panel
app/views/admin/          admin screens, plus the CRUD list/form
app/payments/             one file per payment gateway
themes/default/           storefront, portal and blog views; assets/app.css, app.js
assets/                   admin css/js, flags/, brands/brands.svg, vendor/ (Summernote, jQuery)
install/                  installer, schema.sql, schema.sqlite.sql, migrations.php
tools/                    dev router, mock OTPGet, release builder, admin CSS checker
```

## Routing

File-based, no route table.

| URL | runs |
|---|---|
| `/` | `home.php` - the picker |
| `/whatsapp`, `/whatsapp/pakistan`, `/refund-policy` | `_fallback.php`: a page first, then a service (and country) landing page, which re-enters `home.php` |
| `/country/pakistan`, `/temporary-email/facebook`, `/rent-number/united-kingdom` | `country.php`, `temporary-email.php`, `rent-number.php` - landing pages, also through `home.php` |
| `/api/picker`, `/api/track` | `api.php` - JSON for the picker and the tracker |
| `/order`, `/order/OF-8F42KD3M`, `/order/OF-…/pay`, `/status`, `/change`, `/cancel`, `/retry` | `order.php` |
| `/login`, `/register`, `/forgot`, `/reset/{token}`, `/logout` | one file each |
| `/account`, `/account/orders`, `/account/rentals/7`, `/account/wallet`, `/account/profile` | `account.php` |
| `/blog`, `/blog/{slug}`, `/blog/category/{slug}`, `/blog/tag/{tag}`, `/blog/feed` | `blog.php` |
| `/hook/{gateway}/{method-id}/{CODE}` | `hook.php`, a gateway's own webhook |
| `/sitemap.xml`, `/robots.txt` | named in the resolver, since a dot fails the slug rule |
| `/admin/...` | `admin/{first-segment}.php`, after `admin/_middleware.php` |
| `/install` | `install/index.php`, before any routing exists |
| anything else | `_404.php` |

Rules:

- **No URL contains `.php`.** `/install/index.php` 301s to `/install`.
- A URL segment must match `^[A-Za-z0-9_-]+$` or the request 404s. This is what
  stops path traversal — there is no other place to get it wrong.
- `current_path()` works out the mount point from the configured `base_url`,
  then from `DOCUMENT_ROOT` vs `BASE_PATH`, and only falls back to
  `SCRIPT_NAME`, which servers disagree about.
- Files starting with `_` are includes, never routes.
- Every admin request runs `admin/_middleware.php` first: the login check and
  the CSRF check live there, so admin controllers never repeat them.
- Controllers are plain PHP files that see `$params` and the helpers, and end
  in `view()` (which exits) or `redirect()`. `view()` puts the rendered page in
  `$content` for the layout, so never pass a view variable called `content`.
- **A page slug may not shadow the shop.** The pages editor refuses the words
  the shop uses for itself and any service's slug, because `_fallback.php`
  looks pages up first.

## Helpers

`app/helpers/functions.php` is loaded for every request:

- config / db — `cfg`, `db`, `q`, `one`, `all`, `col`, `insert_row`, `update_row`, `delete_row`, `optional_count`, `optional_all`
- settings — `setting`, `set_setting`, `settings_all`
- output — `e`, `money`, `base_currency_symbol`, `qty_fmt`, `excerpt`, `when`
- urls — `base_url`, `url`, `asset`, `redirect`, `current_path`, `is_https`
- views — `view`, `render`, `partial`
- forms — `csrf_token`, `csrf_field`, `csrf_verify`, `old`, `keep_old`, `flash`, `flashes`
- auth — `admin_user`, `is_admin`, `require_admin`, `current_user`, `is_logged_in`, `require_login`
- misc — `slugify`, `random_code`, `client_ip`, `rate_limit`, `rate_limit_hit`, `log_line`, `order_label`, the chat-channel functions

Loaded by the controllers that need them:

- `OtpGet.php` — the API client, the only class in the codebase
- `catalog.php` — products, servers, sync, pricing, the name matching, the picker queries, `catalogue_explainer()`
- `numbers.php` — buying a number or inbox, checking it, changing, cancelling, expiring; `number_state()`
- `rentals.php` — starting, refreshing, renewing and cancelling rentals
- `wallet.php` — `wallet_credit`, `wallet_debit`, deposits, the statement
- `orders.php` — placing, paying (`confirm_order_payment`), fulfilling (`fulfil_order`), refunding
- `storefront.php` — the picker's state and its landing-page URLs
- `users.php` — sign-up, sign-in, password resets
- `seo.php`, `blog.php` — the head tags and JSON-LD; what is published, contents lists, related posts
- `crud.php`, `paging.php`, `upload.php`, `audit.php`, `notify.php`, `stats.php`, `charts.php`, `currency.php`, `rebase.php`, `impact.php`, `payments.php`, `payfields.php`, `qr.php`, `theme.php`, `mail.php`, `guard.php`, `cron.php`, `migrate.php`, `update.php`

`countries-data.php` and `services-data.php` are data, generated once: every
ISO country with its dialling code and the names OTPGet writes it under, and
180 apps with their brand icon, colour and aliases.

## Database

- **Two drivers.** `db_driver` in the config is `mysql` or `sqlite`; `db()`
  builds the right connection, and SQLite gets `foreign_keys`, WAL and a busy
  timeout turned on. A config with no `db_driver` means MySQL.
- **Write SQL both understand.** No `NOW()`, no `INTERVAL`, no
  `ON DUPLICATE KEY UPDATE`, no `||` or `CONCAT()` (the two disagree about
  joining strings), no `CAST(... AS INTEGER)` (write `column + 0`). Bind a
  `date('Y-m-d H:i:s')` instead of asking the database for the time, and spell
  an upsert out. The schema exists twice, `install/schema.sql` and
  `install/schema.sqlite.sql`; keep them in step, seeds included.
- Every query goes through `q`/`one`/`all`/`col`, which prepare and bind. Where
  a column or table name is dynamic it comes from PHP, never from the request.
- **Claim, don't check.** Anything two requests could do at once - mark paid,
  buy a number, credit a deposit, cancel a rental - is an UPDATE conditional
  on the state it moves away from (`claim()`), and only the request whose
  UPDATE touched a row carries on (`claim()` and `with_lock()` live in
  `guard.php`). The polling page, cron and an admin all
  arrive at the same order.
- Order status is an enum: `pending, paid, processing, completed, expired,
  cancelled, refunded, api_error`.
- `orders.product` says what was sold: `sms`, `premium`, `cheap`, `email`,
  `rental`, and two that are not catalogue products - `deposit` (wallet top-up)
  and `renewal` (more time on a rental). Revenue figures leave deposits out:
  they are the customer's money until spent.
- Foreign keys use `ON DELETE SET NULL` where history matters and `CASCADE`
  where it does not.

## OTPGet

`OtpGet` (app/helpers/OtpGet.php) wraps five endpoint families - SMS
(`handler_api.php`), Premium, Cheap, Email and Rental - which do not agree
about anything: some answer `ACCESS_NUMBER:id:phone:price`, some CSV, some
JSON with their own status codes. The class flattens all of them to the same
shapes - `buy()` returns `order_id, phone, price, validity`, `status()` returns
`state` (`waiting`, `received`, `cancelled`) and `code` - and every failure to
`['error' => CODE, 'message' => words]`. Nothing outside the class parses an
OTPGet reply.

- TLS verification stays on.
- `tools/mock-otpget.php` implements every endpoint the shop calls, so the
  whole shop can be driven without an account. Netflix never receives an SMS,
  Tinder is refused, Antarctica has no stock, and Indonesia has none on server 1
  only (which exercises buying from another server).

## The catalogue

- **An offer is one thing OTPGet sells**: product + server + OTPGet's country
  id + OTPGet's service id, unique on those four. It carries OTPGet's `cost`
  in OTPGet's currency and our `price` in the base currency.
- **Countries and services are ours**, matched by name. OTPGet writes "WA",
  "Whatsapp" and "WhatsApp Messenger" on different servers; the alias indexes
  built from the two data files fold them into one service, so the customer
  sees one WhatsApp with every server under it. A name that matches nothing is
  still sold, under OTPGet's words, with a lettered icon - never dropped.
- **A service is found by `match_key`, never by slug alone.** The slug is the
  admin's (it is the landing page's address); `match_key` is written by the
  sync and nothing else: the catalogue slug for a known brand, else the name
  with spaces squeezed out, so "Tik Tok" and "TikTok" are one row. Finding
  services by slug made a second WhatsApp the day someone renamed the first.
  Countries fall back to `iso2` for the same reason.
- **Two syncs at once never duplicate.** `services.slug`, `countries.slug`
  and the offer key are UNIQUE, and every insert that loses that race catches
  the duplicate and adopts the winner's row instead of failing.
- **The real catalogue is big**: measured with a live key, 1,076 units (six
  SMS servers of 72-201 countries, premium, cheap, email, rentals), 220,000
  offers and about 2,400 apps. A full first import takes ~200 seconds; the
  most-bought countries are ranked first (`sync_units()`), so the shop sells
  after the first few steps and the stepper says so (`liveAfter`).
- **OTPGet refuses crowds.** Four requests at once were fine once; six drew
  500s, twelve drew mostly 500s, and after a few rounds it answered "Service
  temporarily unavailable" (with a 200) to everything. So
  `OtpGet::MAX_IN_FLIGHT` is 2, `fetchAll()` drops to 1 at the first sign of
  busy, and a 5xx, a dropped connection or that BUSY text is asked again after
  a pause - **reads only**: `getNumber` is a purchase, and no buy, cancel or
  renew is ever retried. Do not raise the width to go faster.
- **The speed is the shared connection.** A request on a fresh connection
  took 0.70s, 0.56s of it the TLS handshake; on an open one, 0.13s. Every
  handle shares one connection cache (`OtpGet::share()`), over HTTP/2 where
  the host's curl has it, with gzip on.
- **Sync goes 48 units a step** (`SYNC_STEP_UNITS`), about ten seconds.
  `sync_units_run()` sends a step through `OtpGet::parallel()`; each unit is
  stored on its own in one transaction, new offers a hundred to an INSERT,
  existing ones written only when something moved (the rest get one shared
  `synced_at` UPDATE). **Every `sync_unit()` case must ask OTPGet before it
  writes anything**: `parallel()` runs each piece up to its first request to
  learn the URL and throws that run away.
- Cron reads the stalest units for up to forty seconds every two minutes, then
  rebuilds the picker's cached lists so no visitor waits for them.
- The first sync starts by itself: saving an accepted key on an empty shop
  lands on `/admin/otpget?sync=1`, whose stepper autostarts. The reload when
  it finishes drops the query, or it would start again.
- An offer OTPGet stops listing is marked `missing`, not deleted - orders point
  at it - and is sold again the moment it reappears.
- **`offer_sellable_sql()` is the one answer** to "may a customer buy this
  offer": the picker, the landing pages, the sitemap and `order.php` all ask
  it, because an offer the picker hides but the order form accepts is a price
  nobody meant to sell at.

## Pricing

`reprice_offers()` prices each distinct cost once and writes the offers that
moved in groups by their new price: 172,000 prices in 1.2s, where row by row
was 25s - long enough for a shared host to cut a settings save off midway.

`offer_price()`: OTPGet's cost → base currency (`to_base`) → plus the
product's markup (or the default), **and at least `min_profit` over cost** →
never below `min_price` → rounded **up** to `price_rounding`. A price typed on
the Catalogue screen is stored in `price_override` and wins until unpinned -
but never below `offer_cost_floor()` (cost + min profit): the screen refuses
it, and a sync that finds the cost has risen past a pin lifts the pin and
says so in the audit log. `reprice_offers()` rebuilds every unpinned price,
and runs whenever a setting or a rate that feeds it changes.

`catalogue_explainer()` writes these rules in words, with the numbers in
force, and both Catalogue and Connection & sync render it through
`admin/_explain` - so the two screens cannot describe it differently.

**The price is read back from the offer on every order.** Nothing the browser
says about money is trusted.

**Never sell below today's cost.** OTPGet says what it charged only after it
has charged it, and an SMS number cannot be handed back for two minutes, so
the check has to come first:

- `offer_refresh()` re-reads an offer's unit when its cost is older than
  `price_check_minutes` (10) - one request, shared by everyone buying it in
  those minutes.
- **Ordering** compares the price the customer was shown with the refreshed
  one. Up means refused, with the options redrawn at the new price
  (`repick`) - the next press buys at it. A price is never raised after the
  button that showed it was pressed.
- **Buying** - a number, a rental, a renewal - asks `order_loss_refusal()`,
  the one loss rule, on the refreshed cost. A refusal is `PRICE_UP`: a wallet
  customer is refunded at once, a guest's order waits for the admin, who is
  notified. `loss_guard` off lets an admin sell at a loss on purpose.
- **After buying**, what OTPGet actually charged is written back to the offer,
  so the next customer pays enough, and a number that did cost more than it
  sold for is logged on the order and audited.

## Numbers

`fulfil_order()` is the one door from "paid" to "delivered": deposits go to
the wallet, rentals to `rental_start()`, renewals to `rental_renew_from_order()`,
and everything else to `number_buy()` when `auto_buy_number` is on.

- An order can go through several numbers (`activations`, one row each, up to
  `max_numbers_per_order`). The order's own `number` and `otp_code` are the
  current ones; the history is in the activations.
- `number_check()` asks OTPGet about the live activation, throttled to one
  question every four seconds per order however many tabs are polling.
- **The SMS servers' two-minute rule is checked here first.** OTPGet refuses a
  cancel within two minutes of buying, so *Change number* and *Cancel* show a
  countdown instead of a button that fails.
- A number that runs out of time is released back to OTPGet (which refunds
  us), and `number_lapsed()` refunds an account holder's wallet when
  `auto_refund` is on. A guest's expired order offers a fresh number instead,
  since a guest has no wallet to refund into.
- `number_state()` is everything the order page draws - status words, the
  number with and without its dialling code, the code, every code, seconds
  left, what may be pressed. The page and `/order/{CODE}/status` both use it,
  so the live page and the first render cannot disagree.
- Stock errors (`NO_NUMBERS` and the like) are not notified to the admin -
  they are weather - but every other refusal is.
- **Out of stock or dearer is not the end of the order.** The customer bought
  "a WhatsApp number for Pakistan", not "server 3", so `number_buy()` tries
  `number_stand_in()` first: the same app and country on another server,
  cheapest first, today's cost re-read, and only if it still makes
  `min_profit` at the price already paid. The order keeps its price and label
  and the log says why it moved. Up to three stand-ins, numbers only.
- **The customer never reads OTPGet's words.** Not its name, not its
  messages, not "balance" - those go to the order log, the audit and the
  admin's notification. What the customer sees comes from
  `number_trouble()` and `number_refund_note()` (the wallet statement), and a
  rental's refusal from `rentals.php`'s own sentences.
- **A wallet customer is refunded whenever no code ever arrived** - the first
  number or a replacement. They are holding nothing for their money either
  way. A guest has no wallet, so a guest's order waits for the admin.
- **OTPGet may close a number on its side** - a status of `STATUS_CANCEL`, or
  a check refused with one of `NUMBER_CLOSED_UPSTREAM` (`NO_ACTIVATION` and
  the like). Either is acted on at the next check, page or cron, exactly like
  a number that ran out: refunded to a wallet, or a free replacement for a
  guest, logged and audited. It is never waited out.
- **`order_cancel()` is the one way to cancel**, for the customer and the
  admin alike: never once a code has arrived; an unpaid order just closes; a
  live number goes back to OTPGet first, inside its two-minute rule; the
  money goes to the wallet of the account the order belongs to. A guest has
  no wallet, so the Cancel box (`partials/cancel`, `order_cancel_choice()`)
  offers **sign in or create an account** - the order then moves into it and
  they land back on `?cancel=1` with the refund one press away - or
  **cancel anyway**, which says plainly that the money is lost.
- **This browser's guest orders** are a signed cookie (`browser_orders()`),
  written when a guest orders and read at every sign-in
  (`user_claim_browser_orders()`), which moves those orders - and only
  those - into the account. The signature is what stops a typed code from
  claiming somebody else's order.
- Changing a number is **free** and says so: the button reads "Change number
  - free", its confirmation counts the free changes left, and it stays off
  once there are none.

## What OTPGet and the gateways said

Every request made for an order - to OTPGet or to a payment gateway - is
kept in `order_calls` with the request (keys, tokens and signatures masked by
`call_mask()`), the reply **word for word**, the HTTP status and the time.
The order page shows them under *OTPGet & gateway calls*; `/admin/api-log`
shows them across all orders, filterable to payments or OTPGet and to
failures, with the last day's failures counted on the sidebar.

- **OTPGet:** order work asks `otpget()->about($orderId)`, a copy of the
  client carrying the order, and only such a copy reports what it hears - a
  context set on the shared client would file the next sync under the last
  order cron touched. `get()` reports each exchange as it happens; when the
  reply is later read as a refusal, `refused()` corrects that same row, so a
  200 that says `NO_NUMBERS` is red, not green.
- **Gateways:** each gateway file calls `payment_wire($ch, $body)` right after
  its `curl_exec`; `payment_start/verify/qr/poll/ipn_check` collect what was
  said while they ran and write one row through `payment_record()`. A gateway
  answering HTTP 4xx/5xx, or unreachable, fails the call whatever its file
  made of it. A manual submission is kept too, with what the customer typed.
- **A poll that hears the same reply again** bumps `repeats` and `last_at`
  instead of writing a row every four seconds.
- `order_call()` never throws: before the migration, or on a full disk, the
  sale goes on and the line goes to the log file.

## Logs

`logs.php` knows the five tables that only ever grow - `order_calls`,
`audit_log`, `notifications`, `email_log`, `cron_runs` (`LOG_KINDS`) - and
`log_clear()` empties one, or what is older than 1, 7 or 30 days. The form
for it (`admin/_clear-log`) sits on each log's own screen and again in a
*Logs* box on Maintenance, and posts to `/admin/maintenance/logs`. The audit
entry is written **after** the delete, so clearing the audit log still leaves
the line saying it was cleared. Housekeeping trims the API, notification and
email logs past `log_keep_days` (90); the audit log and cron history keep
their own rules. Order logs and money are records, not logs - they go only
with their orders.

**Every list pages through `paging()` and draws `admin/_pager`** (the
portal: `account/_pager`), so page 2 means the same everywhere. The one
list that does not is Payment Methods, which is ordered by dragging. A rental
inbox pages too; only its newest page listens for new SMS, so an older page
is never redrawn under the reader.

## Rentals

Rentals need an account: the inbox lives in the portal, renewal is from the
wallet or a gateway, and a guest would have nowhere to come back to.
`rental_refresh()` reads the inbox (throttled to one call per ten seconds) and
stores each SMS once, keyed by a fingerprint of its sender, body and time, so
re-reading never duplicates. `rentals_expire()` closes what has run out; an
admin can also close a rental on our side or extend our record of it, which
never touches OTPGet's own expiry.

## The wallet

`users.balance` is the number and `wallet_transactions` is the story of it:
every change writes a line, in the same transaction, carrying the balance it
left behind. The balance only moves through `wallet_move()`, whose debit is
`UPDATE ... SET balance = balance - ? WHERE id = ? AND balance >= ?` - never
read, add, write - so two purchases in two tabs cannot spend the same rupee.
An admin adjustment always carries a note, because the customer reads it.

## Customer accounts

`users.php` follows the admin login's rules: `password_hash`, rehash on login,
`session_regenerate_id(true)` on every sign-in, 8 failures per IP per ten
minutes, one vague failure message, and a dummy hash verified when the email
is unknown so the answer takes the same time either way. Reset links are
single-use, an hour long, and stored only as a SHA-256 hash; asking for one
never says whether the address has an account. `safe_next()` only ever
returns a path on this site. A blocked account is simply not found by
`current_user()`, which signs it out at its next click.

## The storefront

- **The picker is the home page**: tabs for numbers, email and rentals, then
  panes for service → country → options. It is drawn complete on the server -
  every list item is a link to its own landing page and every option is a
  real form - so it works, and is crawled, without JavaScript. `app.js` then
  filters as you type and fetches only the pane that changed from
  `/api/picker`, keeping the address bar on the landing page of the choice.
- The `data-` names in `partials/picker.php` and `partials/options.php` are
  what `app.js` reads; a theme that redraws them keeps them.
- **A landing page with nothing on sale is `noindex`** and says so, rather than
  a page of empty lists pretending to be content. The sitemap only lists pairs
  that have stock.
- On a phone the panes become steps; the furthest one already chosen is shown
  first.
- **A list draws 60 apps, not 2,400.** Drawn in full the home page was
  1.4 MB; `picker_state()` keeps the most-bought `PICKER_SHOWN`, always keeps
  the chosen one, and says how many more there are. Typing searches the drawn
  list at once and the whole catalogue through `/api/picker?q=` after a pause.
- **The unnarrowed lists are cached** (`store_cached()`, five minutes, keyed
  on what is on sale): over the full catalogue each was a 0.15-0.36s GROUP BY
  that the home page and every footer asked for. An admin save on a catalogue
  screen clears them (`catalogue_changed()`, from `_middleware.php`).
- **The picker asks questions a customer would ask** - "Which app?", "Which
  country?", "Buy your number". Lists open in `store_rank()` order: popular
  first, then what sold most in the last thirty days (counted every ten
  minutes), then the admin's order, then the built-in order of the data file,
  then the name - so WhatsApp leads, not Amazon because it starts with an A.
  A three-way switch under each search re-sorts by **Cheapest** or **A-Z**.
  Countries are all on the page, so they sort in the browser; apps are the
  top sixty of thousands, so theirs is `/api/picker?sort=` and
  `picker_state()` sorts **before** the cut - cheapest of every app, not of
  the sixty drawn.
- **Nothing on the home page reloads it.** Tabs, choices, Back and the order
  itself all go over `fetch`; a tab swaps `<main>` for the fetched page's
  (`swapPage()`), and every wait has a skeleton in the shape of what is
  coming - list rows, the options card, whole panes for a tab - the same idea
  as the SMM cards. Placing an order covers the form with a loader whose words
  move on with it. The order page redraws itself the same way when its status
  changes. Any other link or form gets the thin bar along the top.
- **The cheapest option is chosen and shown; the rest fold under "More
  options".** Four radios labelled "Server 1/2/6" is a question most people
  cannot answer and should not have to.
- The hero carries the headline and one short `tagline`. The longer `lead`
  is still written for every landing page, but only for the meta description
  and the structured data.
- Ordering finishes without leaving the page: the options form posts over
  `fetch`; a wallet purchase lands on the order page, a gateway order opens the
  pay modal with the order code and a copy button. **No payment method means
  no guest order** - the form refuses before it writes a row.
- **One Pay button, signed in or not.** A wallet that covers the option pays
  from the wallet; one that does not is passed over and the customer pays in
  the box that opens. Never a detour to top up first - and `order.php` makes
  the same call if a short wallet is posted anyway.
- A guest's form carries **WhatsApp (optional)** and **Email (optional)**,
  each switchable in Settings (`checkout_fields()`). The number is checked
  for 7-15 digits and stored with its `+`; whoever gives an email gets the
  customer mails. Customer mail is **on until switched off**
  (`notification_mail_default()`), admin mail opt-in; `number_ready` is the
  receipt, sent with the first number, inbox or rental of an order.
- The order page (`/order/{CODE}`) polls `/order/{CODE}/status` while anything
  can still change and redraws only when the status does; the code pops in
  with the tab title flashing.
- **It is the OTP screen from the first second.** While the payment is being
  confirmed or the number bought, it already shows the number card - the
  number's shape, dimmed, and "your code will appear right here" - so nothing
  has to be opened or pressed between paying and the code. A paid QR payment
  goes straight there (not to a tracker).
- **No browser dialogs.** `confirm()`, `alert()` and `prompt()` are replaced
  by `ask()` in `app.js` and in `admin.js`, in the shop's own look: any form
  with `data-confirm` (plus optional `data-confirm-title`, `-ok`, `-tone`) is
  asked through it, and a refused number action is said in it rather than in
  a toast that is gone before it is read.
- **Copying works over plain http.** The clipboard API exists only on HTTPS,
  so the fallback selects the text in a field iOS will select, and when even
  that is refused the text is shown, selected, to long-press - it never says
  "Copied" without copying.
- `/track` is a page of its own: `?code=`, `/track/CODE` and a bare
  `/track?CODE` all go to the order, and a code that matches nothing is said
  on the same page.

## Blog and SEO

- **`post_live_sql()` is the one rule for "published"**: status published and
  a publish time that has arrived. The blog, the feed, the sitemap, related
  posts and the home page all use it, so a scheduled post cannot leak through
  one of them.
- Post and page content is admin-written HTML printed as written (the same
  trust as `head_code`). Images dropped into Summernote are uploaded to
  `uploads/content/` through `/admin/media/upload`, never inlined as base64.
- `post_toc()` gives every H2/H3 an id - keeping one the editor wrote - and
  returns the contents list; three or more headings earn one.
- `seo_head()` prints every head tag from the `seo` array a controller hands
  its view; no view writes its own `<title>` or canonical. JSON-LD is built by
  the `schema_*()` functions. Search results, empty lists, previews and pages
  marked hidden are `noindex, follow`.
- **Strip tags as spaces.** `strip_tags('a</p><p>b')` is `ab`; `excerpt()`,
  `seo_clip()`, `post_excerpt()` and the checklist all replace tags with a
  space first, so a description never reads "works.Pick a number".
- `seo_checklist()` is advice for the editor, never a gate.
- **Posts come in by the batch** at `/admin/posts/import`, and `blogcsv.php`
  holds both halves of that contract: `BLOG_CSV_COLUMNS` is what the AI
  prompt asks for and what the importer reads. The prompt is built from the
  shop as it is (`blog_prompt_facts()`): every post already written, so
  nothing is written twice, and the real landing pages with their prices, so
  the links lead somewhere. Its options are filled on the server
  (`blog_prompt_fill()`); the screen only asks for the text again.
- **Nothing imported is saved unseen.** `blog_csv_check()` says what each row
  would become - new, replacing, scheduled, left out - with problems that
  keep a row out (taken slug, a title that says the same as another post by
  word overlap, no body, an unreadable date) and warnings that do not (no
  internal links, links to pages that do not exist, a focus keyword another
  post already chases, lengths). The file is held in `storage/cache` between
  the check and the save, and checked again on save; the save is one
  transaction, so a batch that links to itself lands whole or not at all.
- Imported HTML is not trusted like typed HTML: `blog_csv_clean_html()` drops
  scripts, frames, forms and event handlers, turns `<h1>` into `<h2>`, reads
  tag-less text as Markdown, and marks links out of the shop `noopener`.
- **Images on pages are WebP.** `store_image_upload(..., ['webp' => true])`
  re-encodes a JPEG or PNG at most `UPLOAD_MAX_WIDTH` wide (turned upright
  first); a GIF keeps its animation and a branding image its format, since
  mail clients cannot show WebP. A cover also gets a 1200px JPEG twin
  (`image_share_path()`) for link previews. Post images load lazily
  (`post_lazy_images()`); the cover does not.

## Explaining the settings to the admin

`admin/_explain` is a note that opens the first time and stays shut after,
remembered per screen. Its lines are written by a helper, not the view, so two
screens describing the same rules render the same words.

## Themes

- A theme is a folder under `themes/` with `theme.php`, `views/` and
  `assets/`; `themes_available()` scans the directory. `render()` looks in the
  active theme, then `default`, then `app/views`, so a theme ships only the
  views it changes. One theme ships, `default` (Flash Light).
- A page that opens on the hero gives the header to the band through
  `body:has(.hero) .site-head`; a browser without `:has()` keeps a plain white
  bar.
- The theme colour from Settings is written into `--brand` in the layout.

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
  while still counting as orders - and so are deposits, which are the
  customer's money until they spend it.
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

- **Output:** everything printed in a view goes through `e()`. The exceptions
  are deliberate and commented: page and post content, and `head_code`, all
  written by an admin; and `catalogue_explainer()`'s lines, which escape their
  own values.
- **SQL:** prepared statements only, via the helpers.
- **CSRF:** every non-GET admin request is checked in `_middleware.php`; every
  storefront and portal form carries `csrf_field()` and is checked in its
  controller. A bad token ends the request with 419.
- **Passwords and sessions:** `password_hash` / `password_verify`, rehashed on
  login; `session_regenerate_id(true)` on every sign-in, admin or customer;
  cookies `httponly`, `samesite=Lax`, and `secure` over HTTPS.
- **Login:** throttled per IP, 8 failures per 10 minutes, for admins and
  customers alike. The failure message never says which field was wrong.
- **The order code is the key.** It is eight random characters after the
  prefix, and whoever holds it can see the order and change its number - which
  is what lets a guest come back from another device. Cancelling is the
  exception: it refunds a wallet, so it also needs the account that placed the
  order. The number actions (`/status`, `/change`, `/cancel`, `/retry`) are
  POSTs carrying the CSRF token, and each is throttled per IP.
- **Money:** prices are read back from the offer; the wallet only moves
  through `wallet_move()`; every paid-state change is a claim. A webhook is a
  faster poll, never a second way of deciding (see Payment gateways).
- **Uploads:** accepted only if `getimagesize()` can read them, saved with an
  extension we chose, and nothing in `uploads/` is ever executed. A
  `.htaccess` directive a server does not understand is a 500, so
  `uploads/.htaccess` keeps `php_flag` inside `<IfModule mod_php.c>` and also
  blocks PHP with `RemoveHandler` and a `FilesMatch` deny - only the last works
  without knowing how the host runs PHP, which is why it has to be right.
  `php_admin_*` never goes in a `.htaccess`.
- **Installer:** refuses to run once `install/install.lock` exists.
- **Cron URL:** `/cron/{cron_key}` compared with `hash_equals`; a wrong key is a
  plain 404.
- **Paths:** `app/`, `config/`, `storage/`, `themes/*/views` and
  `install/*.{sql,lock}` are denied in `.htaccess` (as rewrite rules and again
  through `mod_alias`), and each of those folders carries its own deny file.
  The nginx equivalent is in the README.
- **Spam:** the order, contact and sign-up forms carry a hidden `website`
  honeypot and a per-IP throttle; every customer-facing POST is throttled.

## Style

- Comments explain **why**, not what. No comment that restates the line below it.
- Function and variable names are words, not abbreviations.
- Views contain presentation only; anything that needs a decision belongs in the
  controller or a helper.
- Keep the design tokens in `assets/css/admin.css` (`:root`) — do not hard-code
  colours in markup.
- British or American spelling, but match the file you are in.

## Design

The admin is the light-sidebar, indigo-accent panel the SMM codebase used.
The storefront is *Flash Light*: a violet gradient hero that the header sits
on, the picker as a white card overlapping it, and the same card language for
the order page and the portal. Brand icons come from Simple Icons and flags
from flag-icons, both bundled under `assets/` with their licences; nothing is
loaded from a CDN.

## Releasing

`bash tools/build-release.sh` stages only what the live site needs and zips it
into `build/otp-flash-YYYY-MM-DD.zip`. `tools/` stays in the repository but
never ships - `tools/mock-otpget.php` hands out numbers and codes to anyone who
asks and must not reach a real server. The script fails loudly if it sneaks in.

## Testing

No test framework. The checks that matter:

```bash
# syntax
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# admin markup using a class the stylesheet never defines
php tools/check-admin-css.php

# a real run
php -S 127.0.0.1:8000 tools/dev-router.php     # the app
php -S 127.0.0.1:8001 tools/mock-otpget.php    # a fake OTPGet
```

The mock answers instantly and the built-in server takes one request at a
time, which hides exactly what the parallel sync is for. To measure it, start
the mock as `MOCK_LATENCY_MS=400 PHP_CLI_SERVER_WORKERS=16 php -S ...`.

Use the router script: without it `php -S` answers `/sitemap.xml` and
`/robots.txt` from disk. **The router serves its own parent directory**, not
the server's `-t` - so to test an unpacked release, copy the router into that
release's `tools/` and start it from there, or you are testing the working
copy without knowing it.

Then, **on both drivers**: install from an empty database, point *OTPGet* at
`http://127.0.0.1:8001/stubs` with any 8-character key, sync, and walk:

- a guest buying with a manual method, an admin pressing *Mark paid*, and the
  guest's open order page turning into the number and then the code by itself;
- a customer signing up, being credited from *Customers*, buying from the
  wallet, an email inbox and a rental (whose inbox fills on the next cron);
- a Netflix number (never gets an SMS): wind its activation's `expires_at`
  back, run `php cron.php`, and watch it expire and refund the wallet;
- a Tinder number for the `api_error` path.

Then the upgrade path: take a copy of the previous release's database, point
the new code at it, and open every admin screen before running the update.
The panel must stay usable while the database is behind, because
`/admin/update` lives inside it.

Before a release, do the install against the **zip**, not the working copy.
