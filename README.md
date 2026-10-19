# SMM Panel

A small social-media-growth storefront with an admin panel, written in plain PHP
with no framework and no Composer. It runs on ordinary shared hosting: upload,
open the installer, done.

**Status:** complete and tested — storefront, ordering, payment, tracking and
the full admin panel.

---

## What it does

| | |
|---|---|
| **Storefront** | Platform switcher, category tabs, live pricing, order modal, FAQ, content pages, contact form, sitemap |
| **Ordering** | Order → payment details → transaction id → tracking by code, plus a JSON status endpoint |
| **Admin** | Dashboard, orders, services, provider import, platforms, categories, pages, FAQs, messages, payment methods, settings |
| **Providers** | Any SMM API v2 provider. Import a provider's catalogue, mark it up, and resell |
| **Orders** | Mark paid → send to provider → statuses come back on cron → completed |
| **Manual services** | A service with no provider is never sent to an API; you deliver it yourself |
| **Design** | Direction A from `mockups/` — clean light front, light sidebar admin |

Requirements: PHP 8.0+, the `mbstring`, `json` and `curl` extensions, and a
database — either **MySQL 5.7+ / MariaDB 10.3+** (`pdo_mysql`) or **SQLite**
(`pdo_sqlite`). SQLite needs no database server at all: the whole thing lives
in one file under `storage/`, which is already blocked from the web.

---

## Installing on cPanel

1. **Create the database** — only if you want MySQL. In cPanel → *MySQL
   Databases*, create a database and a user, and give the user *All Privileges*
   on it. cPanel prefixes the names, so they look like `myacct_smm`,
   `myacct_smmuser`.

   Picking **SQLite** in the installer instead skips this step entirely.

2. **Upload the files.** Put everything in `public_html` (or a subfolder if the
   panel is not on the main domain). `mockups/` and `tools/` are development
   aids and do not need to go up.

3. **Make three folders writable** (755 is usually enough, 775 if your host is
   strict): `config/`, `storage/logs/`, `uploads/branding/`.

4. **Open `https://yourdomain.com/install`.** It checks the server, lets you
   choose MySQL or SQLite, creates the tables and seed data, then asks for your
   admin username and password. It writes `config/config.php` with a random app
   key and cron key, and locks itself afterwards.

   Visiting any other page before this redirects here, so you cannot miss it.

5. **Delete the `install/` folder.** Everything keeps working without it.

6. **Add the cron job** (cPanel → *Cron Jobs*, every 5 minutes):

   ```
   php /home/youraccount/public_html/cron.php
   ```

   If your host does not allow CLI cron, use the URL form instead — the full
   address is shown in *Settings → Cron*:

   ```
   https://yourdomain.com/cron/your-cron-key
   ```

Log in at `https://yourdomain.com/admin`.

---

## Updating to a newer release

The database is the only thing an update has to touch, and the panel does that
part itself.

1. **Back up the database.** An update only adds tables, columns and settings,
   but a backup costs a minute and a restore does not.

2. **Upload the new files over the old ones.** Leave `config/`, `storage/` and
   `uploads/` alone — those are yours, and nothing in a release overwrites
   them.

3. **Open *Update* in the admin sidebar.** It compares the version in the files
   (`app/core/version.php`) with the version recorded in the database, lists
   the changes that are outstanding, and applies them one at a time with a
   progress bar.

   Every screen shows a banner while an update is outstanding, so there is no
   way to upload a release and forget this step.

Each change runs as its own request. A migration that rewrites a large table is
exactly the kind of thing a shared host cuts off at thirty seconds, and running
them one per request keeps each one well inside the limit. A change that has
already been applied is recorded and never runs twice, so pressing the button
again is safe.

---

## Themes

Two ship: **Clean Light** (soft gradients, light palette) and **Warm Gradient**
(pink-to-coral hero with the platform chooser sitting across it). Switch in
*Admin → Themes*; the new theme's own colour is applied unless you have picked
one yourself in Settings.

To add a third, copy a folder under `themes/`, change `theme.php` and edit what
you want. A theme only ships the views it changes — anything it leaves out comes
from the default theme — and no existing file needs touching for it to appear in
the admin.

---

## Nginx

There is no `.htaccess` on nginx, so the rewrite and the deny rules have to go
in the server block:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/smm;
    index index.php;

    # Everything that is not a real file goes to the front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Application internals are never served
    location ~ ^/(app|config|storage)/ { deny all; return 404; }

    # A theme's code is private; its assets folder is not.
    location ~ ^/themes/[^/]+/views/ { deny all; return 404; }

    # The installer itself has to be reachable - it is how the panel is set
    # up - so only what is inside it that nobody should read is denied.
    location ~ ^/install/.*\.(sql|lock)$ { deny all; return 404; }

    # Nothing in uploads/ is ever executed. The ^~ is what makes this win
    # against the \.php$ block above; without it an uploaded .php would be
    # handed straight to PHP-FPM.
    location ^~ /uploads/ {
        location ~ \.(php|phtml|phps|php[0-9]|phar|pl|py|cgi|sh)$ { deny all; return 404; }
    }

    location ~ /\.          { deny all; }
    location ~ \.(sql|log)$ { deny all; }
}
```

### If Apache answers 500 and nothing has reached PHP

A `.htaccess` directive the server does not understand is not skipped - it is
a 500 for everything in that folder. Two of them are worth knowing about:

- **`php_flag` / `php_value`** only exist where PHP runs as an Apache module.
  On FastCGI, PHP-FPM or LiteSpeed - which is most cPanel hosting now - they
  are unknown directives. `php_admin_flag` and `php_admin_value` are worse:
  they are refused in a `.htaccess` on *every* server, module or not. The
  shipped `uploads/.htaccess` keeps the flag inside `<IfModule mod_php.c>`
  guards and stops PHP running there two other ways as well, so it is safe
  on either kind of host.
- **`Options`** needs `AllowOverride Options`. If the 500 is at the root and
  started with the upload, try the file without its `Options -Indexes` line;
  `IndexIgnore *` beside it says the same thing through another override
  class.

---

## Adding a provider and importing services

1. **Admin → Providers → Add provider.** Give it a name you will recognise, the
   provider's API endpoint (usually something ending in `/api/v2`) and your API
   key. Save, then press **Check balances** to confirm the key works — the
   balance appears in the sidebar.

2. **Admin → Import Services.** Pick the provider. The catalogue is fetched once
   and cached for 30 minutes; **Refresh from API** forces a new fetch.

3. **Filter down to what you want.** Search by name, or narrow by the provider's
   own category.

4. **Check the two settings above the table:**
   - *Auto-detect platform & category* reads each service name and fills in the
     platform and category for you. It reads the service name first and the
     provider's category name second, because the name is more specific —
     "Telegram Reactions" sits in a provider category called "Telegram Members"
     but belongs under Likes.
   - *Markup %* sets your selling price: `cost × (1 + markup/100)`.

5. **Tick the rows and press Import.** Services you already have are shown as
   *have it* and unticked by default. Importing them again with *update existing*
   on refreshes cost, price and limits but keeps any name or description you
   edited.

6. **Admin → Services** to tidy up: rename, write a description and feature
   ticks, set which are *most popular*, or use the bulk bar to activate, apply a
   markup, re-sync prices or deactivate in one go.

To sell something you fulfil by hand, add a service and leave **Buy from** set to
*Manual*. Those orders move to *processing* on payment and wait for you.

**Adding a provider tests it first.** Saving the form asks the provider for its
balance before the row is written: a URL or key it refuses is not saved, and
the reason is shown under the key field. One that answers is saved already
knowing its balance, the currency it bills in and whether it can report on
several orders in one request — so there is nothing to press afterwards. The
screen then says what else it found: a billing currency with no exchange rate
yet, or a catalogue that came back empty, are things to go and fix rather than
reasons to refuse a working key.

Editing re-tests only when the URL or the key changed. Changing a sort order
does not cost you a round trip to the provider, and the **Check** button on the
list is there for asking again whenever you want.

---

## How an order moves

```
pending ──mark paid──► paid ──send──► processing ──cron──► completed
   │                     │                │
   │                     └── api_error ◄──┘ (provider refused; the reason is kept)
   └── cancelled / refunded
```

With *auto-send orders* on (Settings), marking an order paid sends it straight
to the provider. Cron then refreshes statuses, pulls back start count and
remains, and marks orders completed. Nothing is ever silently completed — an
unknown provider status stays *processing*.

---

## Emptying things out, and changing the base currency

**Admin → Maintenance** clears things in bulk. Three jobs, each needing the
word DELETE typed before it will run, each recorded in the audit log before it
runs:

- **Clear the catalogue** — every service and package. Orders keep the name,
  quantity and price they were placed with, so history still reads correctly.
- **Clear orders** — orders, their logs and any payment screenshots.
- **Start fresh** — both, plus messages, notifications, the email log and cron
  history. Keeps your admin account, settings, providers, platforms,
  categories, payment methods, currencies, pages, FAQs and the audit log.

Deleting a service no longer deactivates it when orders point at it. It did,
out of caution, but it was never needed: `orders.service_id` is
`ON DELETE SET NULL` and each order carries its own copy of what it bought.

**The base currency can now be changed after you have prices.** Currencies →
*Make base* shows how many services, packages, orders and rates will be
rewritten, asks you to type the currency code, then converts all of them at
one ratio in a single transaction. Nothing is re-fetched from providers:
dividing a stored amount by the rate gives exactly what rebuilding it against
the new base would.

Two things to know. Past order amounts are converted too, so they read in the
new currency rather than the one they were taken in. And money is stored to
two decimals, so switching back and forth drifts by a paisa or a cent per
row — take a backup before a change you might want to reverse.

---

## Chat buttons: WhatsApp, Telegram and email

The shop can be reached on any of three channels, and **which button appears
where is a setting**, not something baked into the theme.

Fill the channels in under **Admin → Settings → Contact**: a WhatsApp number
with its country code, a Telegram username (`@yourshop`, `yourshop` or the
whole `https://t.me/yourshop` link — all three are accepted) and a support
email. A channel you leave blank is simply not offered anywhere.

Then tick which of them belongs in each place under **Settings → Chat
buttons**:

| Place | What it is |
|---|---|
| Floating bubble | The bubble in the corner of every page |
| Header and menu | The "Need help?" link beside Track Order |
| Home page band | The "not sure which package" block |
| Contact page | The buttons above the message form |
| Order and payment pages | Sending a receipt, and the "something wrong?" line |

Tick none for a place and no button appears there. Tick two and both appear,
side by side or stacked.

**No button is named after its app.** A customer looking for help is choosing
a way to reach you, not a brand, and a shop that moves from one messenger to
another should not have to hunt down every button that named the old one.
Which app it is, is the icon's job — and the name is in every button's `title`
and `aria-label`, so a screen reader still says it.

- The **floating bubbles are round and carry no words at all**, one circle per
  channel, stacked in the corner.
- In-page buttons say **Chat** (WhatsApp), nothing (Telegram — its icon alone)
  or the address (email).
- The menu inside the burger is the exception: it is a list of words with no
  icons, so there each channel is named.

Two things worth knowing:

- **A receipt is prefilled where the channel can carry one.** WhatsApp and
  email both take a message; Telegram's `t.me` links cannot, so that one just
  opens the chat — which is why the order code is always on screen with a copy
  button beside it.
- **The number the order form asks the customer for is still a WhatsApp
  number.** That is how you reach *them*, and it is a separate thing from how
  they reach you.

---

## Taking payment with Paytm Business

Paytm Business collects into a UPI id, so the customer is shown a QR for the
exact amount and the order confirms itself when the money lands. Nobody types a
transaction id and nobody has to match one up afterwards.

1. **Admin → Payment Methods → New**, pick **Paytm Business (UPI QR)**.
2. Fill in your **Merchant ID (MID)** and **Business UPI ID**, both from the
   Paytm Business dashboard, and optionally the name you want shown in the UPI
   app (the site name is used otherwise).
3. Save it active.

What the customer sees: they place the order, land on `/order/{CODE}/qr`, scan
the QR with any UPI app - or tap *Pay in your UPI app* on a phone - and the page
notices the payment within a few seconds and moves them on. With *auto-send
orders* on, the order goes to the provider the moment it is confirmed.

Two things worth knowing:

- **UPI settles in rupees.** If your base currency is not INR, add **INR** under
  Admin → Currencies so there is a rate to convert with. Without one the QR is
  refused rather than drawn for a figure nobody can stand behind.
- **The customer who pays and closes the tab is still covered.** The
  *Check watched payments* cron task asks Paytm about orders that are still
  awaiting payment, for a day after they were placed.

A payment Paytm reports for less than the order asks for is *not* confirmed: the
order stays where it is and both figures are written into its log, because the
alternative is delivering for nothing.

---

## Layout

```
index.php              front controller; every URL lands here
cron.php               scheduled work (CLI)
app/core/              bootstrap and the resolver
app/helpers/           functions.php, crud.php, SmmApi.php, detect.php, orders.php, cron.php
controllers/           one file per URL; controllers/admin/ is the admin
views/                 one file per screen, plus layouts/ and admin/crud/
assets/                css and js
install/               installer and schema (delete after installing)
mockups/               the HTML design mockups this was built from
tools/                 mock-provider.php, for testing without a real API
```

Routing is file-based: `/admin/services` runs `controllers/admin/services.php`.
No route table to keep in sync.

---

## Running it locally

```bash
php -S localhost:8000                         # the site
php -S localhost:8001 tools/mock-provider.php # a fake provider
```

That is enough: every page a person clicks works, including `/install`.

The one gap is `/sitemap.xml` and `/robots.txt`. PHP's built-in server answers
any URL with a file extension straight from disk and never reaches `index.php`,
so those two 404 locally — they are fine on Apache and nginx, and `/sitemap`
and `/robots` serve the same thing everywhere. If you want the dotted versions
locally too:

```bash
php -S localhost:8000 tools/dev-router.php
```

which makes the built-in server behave like Apache with the shipped
`.htaccess`. It is development only and is not in the release zip.

## Testing without a real provider

```bash
php -S 127.0.0.1:8001 tools/mock-provider.php
```

Add a provider with API URL `http://127.0.0.1:8001/` and any key. It returns 40
realistic services, accepts orders, and walks each order forward on every status
call, so you can watch the whole flow complete. An order whose link contains
`fail-me` is refused, which is a quick way to see the `api_error` path.


---

## Building the upload zip

```bash
bash tools/build-release.sh
```

Writes `build/smm-panel-YYYY-MM-DD.zip` with only what the live site needs. The
mockups and the development tools are deliberately left out — the mock provider
must never reach a real server.

---

## What a customer sees

```
/                        the default platform
/instagram               that platform
/instagram/followers     that platform and category
/faq  /contact  /track   content and tracking
/install                 the installer, until it locks itself
/order/GK-8F42KD         their order: summary, payment details, live status
/api/track?code=...      the same status as JSON
/sitemap.xml /robots.txt for search engines (also at /sitemap and /robots)
```

No URL anywhere has `.php` in it. The platform strip and the category tabs are
real links, so the site works — and is crawlable — with JavaScript blocked. The script only adds the live price
and the order modal; without it the same form posts normally.

The price is recalculated from the service row on every order. Nothing the
browser says about money is trusted.


---

## MySQL or SQLite?

Both are first-class; the installer asks once and writes the choice into
`config/config.php` as `db_driver`.

| | MySQL / MariaDB | SQLite |
|---|---|---|
| Setup | create a database and user first | nothing to set up |
| Where the data lives | on the database server | `storage/database.sqlite` |
| Backups | export through phpMyAdmin or mysqldump | copy the one file |
| Good for | any size, several sites sharing a server | a single shop; simplest possible install |

The SQLite file sits in `storage/`, which `.htaccess` and the nginx config
already deny, so it is not reachable over the web. Back it up the way you would
back up a database — it *is* the database.

To move between them, install fresh on the other driver and re-import your
services; there is no converter.
