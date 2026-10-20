# OTP Flash

A virtual-number shop for OTP verification, with an admin panel, written in
plain PHP with no framework and no Composer. Numbers, temporary email inboxes
and rented numbers come from [OTPGet](https://otpget.com/docs); OTP Flash puts
its own prices on them, takes the payment, buys the number the moment it is
paid for and shows the code on the customer's screen as soon as it arrives.

It runs on ordinary shared hosting: upload, open the installer, paste your
OTPGet key, sync, done.

---

## What it does

| | |
|---|---|
| **Storefront** | Pick an app, a country and a server on the home page itself - searchable lists with brand icons and flags, sorted by popularity, price or name. Every choice is a real landing page (`/whatsapp`, `/whatsapp/pakistan`, `/country/pakistan`) |
| **Products** | SMS numbers on OTPGet's servers 1-6, Premium numbers, Budget numbers, Email OTP inboxes and Rentals (12 hours to a year, with an inbox). Each one switches on and off from the admin |
| **The number page** | Number with copy buttons (with and without the country code), a countdown, the code the moment it lands, *Change number* (free) and *Cancel* - no refresh needed |
| **Guests** | Buy without an account: choose, pay, keep the order code. A number that never gets its SMS can be swapped for a free one |
| **Customer portal** | Sign up / sign in, dashboard, wallet with one-tap purchases, deposits through any payment method, automatic refunds to the wallet, order history, rentals with a live inbox and renewal |
| **Payments** | Manual methods (JazzCash, EasyPaisa, bank - the customer sends a reference and an admin confirms) and redirect / QR gateways such as Paytm Business. Wallet balance for signed-in customers |
| **Admin** | Dashboard, orders, customers, rentals, wallet ledger, OTPGet connection and sync, catalogue with per-offer price pinning, services, countries, blog, pages, FAQs, SEO, payment methods, currencies, emails, notifications, audit log, themes, updates |
| **Blog / CMS** | Posts with the Summernote editor, image upload, cover image, categories, tags, scheduling, a live SEO checklist and Google preview, RSS feed |
| **SEO** | Titles and descriptions per page, canonical URLs, Open Graph and X cards, JSON-LD (Organization, WebSite search box, Breadcrumbs, Product price range, FAQ, BlogPosting), a sitemap index split by type with images, robots.txt, verification tags |

Requirements: PHP 8.0+, the `mbstring`, `json` and `curl` extensions, and a
database - either **MySQL 5.7+ / MariaDB 10.3+** (`pdo_mysql`) or **SQLite**
(`pdo_sqlite`). SQLite needs no database server at all: the whole thing lives
in one file under `storage/`, which is already blocked from the web.

---

## Installing on cPanel

1. **Create the database** - only if you want MySQL. In cPanel → *MySQL
   Databases*, create a database and a user, and give the user *All Privileges*
   on it. Picking **SQLite** in the installer instead skips this step.

2. **Upload the zip** (see *Building the upload zip* below) to `public_html`,
   or a subfolder, and extract it.

3. **Make these folders writable** (755 is usually enough, 775 if your host is
   strict): `config/`, `storage/`, `uploads/branding/`, `uploads/content/`.

4. **Open `https://yourdomain.com/install`.** It checks the server, lets you
   choose MySQL or SQLite, creates the tables and seed data, then asks for your
   admin login, the shop name and - optionally - your OTPGet API key. It writes
   `config/config.php` with a random app key and cron key, and locks itself.

5. **Delete the `install/` folder.** Everything keeps working without it.

6. **Add the cron job** (cPanel → *Cron Jobs*, **every minute**):

   ```
   php /home/youraccount/public_html/cron.php
   ```

   Every minute matters here: a number lives for twenty minutes, and cron is
   what notices an expired one and refunds it, catches a payment whose
   customer closed the tab, and keeps rental inboxes current. If your host
   does not allow CLI cron, use the URL form shown in *Admin → Cron*:

   ```
   https://yourdomain.com/cron/your-cron-key
   ```

Log in at `https://yourdomain.com/admin`.

---

## First hour: connecting OTPGet

1. **Admin → OTPGet → Connection & sync.** Paste your API key (from your
   OTPGet account) and save. The screen asks OTPGet for your balance straight
   away, so a wrong key is caught now rather than at the first order.
2. **Choose what to sell**: which products (SMS, Premium, Budget, Email,
   Rentals), which SMS servers, which email servers and rental providers. Give
   the servers names if you like ("Fast", "Cheap") - customers see those.
3. **Set your markup.** One default percentage, optionally a different one per
   product, the least profit you will take on any sale, a minimum price and
   how prices are rounded (always up).
4. **Sync.** Saving an accepted key on an empty shop starts the first sync by
   itself. OTPGet has no "everything at once" listing, and its catalogue is
   big - over a thousand countries and sites across its servers, a couple of
   hundred thousand prices - so it is read country by country over one shared
   connection, the most-bought countries first. **The shop is selling within
   about half a minute**; the whole catalogue is in after about three to four
   minutes (measured against otpget.com with a real key). You can leave the
   page at any point: with *keep the catalogue fresh* on, cron carries on and
   then keeps re-reading the oldest prices every two minutes.

   OTPGet answers HTTP 500 when it is sent many requests at once, so the shop
   never sends more than two together and slows down the moment it is told
   it is busy. Do not look for a setting to make it send more - it only makes
   it slower.
5. **Add a payment method** under *Payment methods*, and switch it on. Without
   one, guests have no way to pay (account holders can still use the wallet
   once you credit it).

The shop is selling from this point.

### How a price is made

`OTPGet cost → converted to your base currency → + markup (and at least your
minimum profit) → never below the minimum price → rounded up`. A price you type
on the Catalogue screen is **pinned**: it stays put when OTPGet's cost moves,
until you unpin it - but it can never be below cost plus your minimum profit.
The screen refuses such a pin, and if OTPGet's cost later rises past one, the
next sync lifts it and the audit log says so. The Catalogue and OTPGet screens
both carry a short explanation of these rules with the numbers in force.

### You never sell below cost

OTPGet only says what it charged after it has charged it, so the shop checks
first. When an offer's cost is more than ten minutes old (*Settings → Numbers*)
it asks OTPGet for today's price - once when the customer orders, and again
just before the number is bought:

- a price that went **up** while the customer was looking is shown to them,
  with the options redrawn, before they pay it;
- if it rose between paying and buying (a manual payment confirmed an hour
  later), the number is **not** bought: an account holder is refunded to their
  wallet at once, and a guest's order waits for you with a notification;
- whatever OTPGet actually charged is written back to the offer, so the next
  customer pays the right price.

Switch *Never buy a number for more than it sold for* off only if you mean to
sell at a loss.

An offer OTPGet stops listing is marked *missing* and leaves the shop by
itself; it comes back by itself if it is listed again. Switching a service or
a country off hides every offer it has.

### Services and countries

OTPGet names apps and countries in its own words ("Whatsapp", "WA",
"Pakistan (+92)"). The sync matches them to a built-in list of 180 apps with
brand icons and every country with its flag and dialling code, so the same app
from six servers shows up as one row with six options. Anything it cannot
match is still sold, under OTPGet's name, with a lettered icon. Rename, recolour,
reorder, hide or write a landing-page introduction for any of them under
*Services* and *Countries*.

---

## How an order moves

```
pending ──paid──> paid ──bought──> processing ──code──> completed
                                       │
                                       ├── no code in time ──> expired (refunded to the wallet)
                                       └── OTPGet refused ───> api_error (retry, or refund)
```

- **Paying** - a guest pays through a payment method; a signed-in customer can
  also pay from their wallet in one tap. A manual payment waits for an admin
  to press *Mark paid*; a gateway confirms itself.
- **Buying** - the number is bought from OTPGet the moment the order is paid
  (switch *buy at once* off to press *Deliver* yourself). If OTPGet's price
  has risen above what the customer paid since the last sync, the order waits
  for you instead of being filled at a loss.
- **Waiting** - the order page asks for the code every few seconds; cron asks
  too, so a closed tab does not matter.
- **No SMS** - after OTPGet's two-minute rule the customer can *Change number*
  (free, up to three numbers per order by default) or *Cancel*. A number that
  runs out of time is given back to OTPGet, which refunds us, and an account
  holder is refunded to their wallet automatically. A guest's order offers a
  fresh number instead.
- **Rentals** need an account. The number and its inbox live under
  *Account → Rentals*, every SMS is kept, and the customer can renew from the
  same page.

Every state change writes a line in the order's log, and every admin action is
in the audit log.

---

## The customer's wallet

`users.balance` is the figure and the *Wallet ledger* is the story of it: every
deposit, purchase, refund and admin adjustment is a line carrying the balance
it left behind, written in the same transaction as the change. A customer sees
the same lines, with your notes, in their portal.

- **Deposits** are ordinary orders for the amount, paid through any payment
  method; confirming one credits the wallet.
- **Adjustments** are made on the customer's own screen (*Customers → open →
  Adjust the wallet*) and always carry a note, because the customer reads it.
- A balance can never go below zero - the debit is one conditional UPDATE, so
  two purchases in two tabs cannot both spend the same rupee.

---

## The blog and SEO

**Blog posts** are written in Summernote. Pictures dropped into the editor are
uploaded to `uploads/content/`, never pasted in as base64. Use *Heading 2* for
sections: they become the contents list at the top of the post and the
"On this page" links beside it.

Beside the editor, *Search appearance* shows the post as Google would, counts
the title and description, and runs a checklist - length, headings, images
with alt text, links, and your focus keyword in the title, description, first
paragraph and address. It is advice, not a gate.

A publish date in the future schedules the post: it stays out of the blog, the
sitemap and the feed until then. *Hide from search engines* keeps a post
reachable by link but out of the index.

**Admin → SEO** holds what every page shares: the title format, the default
description, the share image, your business name and social profiles, the
Google and Bing verification codes, extra robots.txt lines, and the switch for
service / country landing pages. It also shows how many addresses the sitemap
holds and links to it - submit `https://yourdomain.com/sitemap.xml` once in
Google Search Console and Bing Webmaster Tools; it keeps itself up to date.

---

## Updating to a newer release

1. **Back up the database.**
2. **Upload the new files over the old ones.** Leave `config/`, `storage/` and
   `uploads/` alone - nothing in a release overwrites them.
3. **Open *Update* in the admin sidebar.** It lists the outstanding database
   changes and applies them one per request with a progress bar. Every screen
   shows a banner while an update is outstanding.

---

## Emptying things out, and changing the base currency

**Admin → Maintenance** clears in bulk, each job needing the word DELETE typed
first and recorded in the audit log before it runs: the catalogue (offers -
orders keep what they were sold as), orders, or everything transactional.

**Currencies → Make base** changes the base currency after you have prices:
offers, orders, wallets and the ledger are converted at one rate in a single
transaction. Money is stored to two decimals, so take a backup before a change
you might want to reverse.

---

## Chat buttons: WhatsApp, Telegram and email

Fill the channels in under **Settings → Contact**, then choose which belong in
each place under **Settings → Chat buttons** (floating bubble, header, home
page band, contact page, order pages). A blank channel is never offered. The
WhatsApp message on an order page is prefilled with the order code, the number
and the amount.

---

## Taking payment with Paytm Business

**Payment methods → New → Paytm Business (UPI QR)**, fill in your Merchant ID
and Business UPI ID, save it active. The customer scans a QR for the exact
amount and the order confirms itself when the money lands - the page polls,
cron polls, and a payment reported short of the order is not accepted. UPI
settles in rupees, so add **INR** under Currencies if your base is something
else.

---

## Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/otpflash;
    index index.php;

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
    location ~ ^/themes/[^/]+/views/ { deny all; return 404; }

    # The installer has to be reachable; only its private files are denied.
    location ~ ^/install/.*\.(sql|lock)$ { deny all; return 404; }

    # Nothing in uploads/ is ever executed. ^~ makes this win over \.php$.
    location ^~ /uploads/ {
        location ~ \.(php|phtml|phps|php[0-9]|phar|pl|py|cgi|sh)$ { deny all; return 404; }
    }

    location ~ /\.          { deny all; }
    location ~ \.(sql|log)$ { deny all; }
}
```

### If Apache answers 500 and nothing has reached PHP

A `.htaccess` directive the server does not understand is a 500, not a line it
skips. `php_flag` only exists where PHP is an Apache module, so the shipped
`uploads/.htaccess` keeps it inside `<IfModule mod_php.c>` and blocks PHP two
other ways as well. If the 500 is at the root, try the root `.htaccess`
without its `Options -Indexes` line.

---

## Layout

```
index.php              front controller; every URL lands here
cron.php               scheduled work (CLI)
app/core/              bootstrap, resolver, version
app/helpers/           functions.php, OtpGet.php (the API client), catalog.php,
                       numbers.php, rentals.php, wallet.php, orders.php,
                       storefront.php, seo.php, blog.php, users.php, cron.php …
app/controllers/       one file per URL; admin/ is the admin
app/views/admin/       the admin screens
themes/default/        the storefront and portal: views/, assets/app.css, app.js
assets/                admin css/js, flags, brand icons, vendor (Summernote, jQuery)
install/               installer, both schemas, migrations
tools/                 dev router, mock OTPGet, release builder, CSS checker
```

Routing is file-based: `/admin/catalogue` runs `app/controllers/admin/catalogue.php`.

---

## Running it locally

```bash
php -S 127.0.0.1:8000 tools/dev-router.php      # the shop, behaving like Apache
php -S 127.0.0.1:8001 tools/mock-otpget.php     # a fake OTPGet
```

Use the router: without it the built-in server answers `/sitemap.xml` and
`/robots.txt` from disk. Note that it serves the folder it lives in (its
parent), so to try an unpacked release zip, copy it into that release's own
`tools/`.

### Testing without a real OTPGet account

In *Admin → OTPGet*, set the base URL to `http://127.0.0.1:8001/stubs` and use
any key of 8+ characters. The mock implements every endpoint the shop uses -
SMS, Premium, Budget, Email and Rental - with a realistic catalogue, and
delivers a code a few seconds after a number is bought. Some names are wired
to misbehave so the hard paths can be seen:

| Choose | and you get |
|---|---|
| Netflix | a number that never receives an SMS - expiry and automatic refund |
| Tinder | OTPGet refusing the purchase - the `api_error` path |
| Antarctica | no numbers in stock |

`MOCK_SMS_DELAY=20` (seconds) and `MOCK_CANCEL_AFTER` change the timings, and
`MOCK_LATENCY_MS=400` makes every reply as slow as the real network. To see a
sync's requests arrive together, give the server workers:
`PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8001 tools/mock-otpget.php`.
The mock keeps its state in the system temp folder and must never be uploaded
anywhere real - the release script refuses to ship it.

---

## Building the upload zip

```bash
bash tools/build-release.sh
```

Writes `build/otp-flash-YYYY-MM-DD.zip` with only what the live site needs.

---

## What a customer sees

```
/                              buy a number: app → country → server
/whatsapp  /whatsapp/pakistan  landing pages for an app, and an app in a country
/country/pakistan              every app in one country
/temporary-email[/facebook]    email OTP inboxes
/rent-number[/united-kingdom]  rentals
/order/OF-8F42KD3M             their order: pay, then the number and the code, live
/login /register /account      the portal: dashboard, orders, rentals, wallet, profile
/blog  /blog/{post}            the blog, categories, tags, /blog/feed
/faq  /contact  /track         help and tracking by order code
/sitemap.xml  /robots.txt      for search engines
```

No URL has `.php` in it. The picker's lists are real links, so the site works,
and is crawled, with JavaScript blocked; the script adds live filtering, the
pay modal and the live number page. Nothing the browser says about money is
trusted - the price is read back from the offer on every order.

---

## MySQL or SQLite?

Both are first-class; the installer asks once and writes the choice into
`config/config.php` as `db_driver`. SQLite keeps everything in one file in
`storage/` (copy it to back up); MySQL suits a busier shop or a server shared
with other sites. There is no converter between them.
