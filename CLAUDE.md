# city-park — context for Claude Code

Symfony 7 + Nutgram Telegram bot for ОСББ pavilion booking. Prod bot `@che_city_park_bot`, dev `@dev_che_city_park_bot`.

## Where things live

- Prod path: `/var/www/html/city-park/` on the production server (host + SSH key live in per-machine memory, not in this file)
- Admin login at `/login`, password = env `MAIN_ADMIN_PASSWORD`
- Logs: `var/log/{prod,remind,photo-check,photo-cleanup,debt-notify,warm-weather}.log`

## Core domain

- `Account` is the tenant unit. One Account ↔ many `TelegramUser` (family members / conditional owners).
- `Account.is_active = false` is the single block flag — used by debt blocking AND photo-miss blocking. Toggled via `/admin/users`.
- `Account::isNonResidential()` blocks parking + storage units from booking *structurally* (checked before `is_active` — admins can't grant booking to a parking account without renaming the unit). Detects `apartment_number` containing паркінг/парковка/кладов/комірчина/parking/storage.
- `ScheduledSet` is one row per booked **hour** (no merging). A "session" = consecutive same-pavilion hours by one account, detected at query time.
- Booking limits live in `src/Validator/`: ≤ 3h / day, ≤ 12h / month, no cross-pavilion overlap same hour, bookings must be contiguous (one unbroken run per pavilion/day — no scattered hours), working hours 09:00–23:00 both pavilions (no night; last slot starts 22:00), per-account debt threshold computed as `area × tariff.price_per_meter × 1.5` (`DebtPolicy::getThresholdFor`). Fallback to env `DEBT_BLOCK_THRESHOLD` (1300 UAH) when either `Account.area` or `Tariff.price_per_meter` is missing/zero. Tariff is a single-row table set via `/admin/tariff`.

## Bot menu (callback wiring in `config/telegram.php`)

| Button | Callback | Slash | Handler |
|---|---|---|---|
| 🔑 Оренда квартир | `rental-menu` / `rent:new` / `rent:{view,page,photos,contact,phone,extend,remove}:<id>` | `/rent` | `RentalMenuCommand` + `RentalPublish` (conversation) |
| Бронювання | `schedule-pavilion` | `/schedule` | `SchedulePavilion` (conversation) |
| Переглянути свої | `own-schedule` | — | `OwnSchedule` |
| Як доїхати? | `type:route` | — | `RouteCommand` |
| 📜 Історія бронювань | `booking-history` + `bh:week:YYYY-Www` | `/history` | `BookingHistory` (weekly paginated, last 30 days, photo status badges) |
| 📸 Завантажити фото | `photo-upload-info` | `/photo` | `PhotoUploadInfo` (lists open requests) |
| ℹ️ Інструкція та FAQ | `info-menu` / `info-topic:*` | `/info` | `InfoCommand` (edit `TOPICS` const) |
| 🗳️ Голосування | `voting-menu` / `bvote:<id>:yes\|no` | `/vote` | `VotingMenuCommand` (community vote-to-block) |
| 🏠 На головну | `main-menu` | `/start` | `StartCommand::__invoke` re-renders menu |
| (auto) photo upload | `onPhoto` event | — | `UploadPhotoCommand` |

**Slash menu must be pushed via `bin/console bot:menu:update --env=prod` after editing `BotMenuUpdateCommand::MENU`.** Its array order is what Telegram renders, and it is kept in sync with the inline menu in `StartCommand::mainMenuMarkup()` — 🔑 Оренда is first in both (residents were not finding it at the bottom). Nutgram's `setMyCommands()` has a null-scope bug; the command uses raw `sendRequest()` instead.

## Photo-obligation lifecycle

`PavilionPhoto` (artifact) and `PhotoUploadRequest` (obligation) are separate. The cron `pavilion:photo:check` (every 20 min) materialises requests for past sessions inside `PavilionPhotoService::LOOKBACK_HOURS` (26). Reminders fire at end+20/+40 min; block at end+`BLOCK_AFTER_MIN` (60), i.e. within the hour, so the photo stays fresh evidence of the pavilion's condition before the next booker can change it. Reminders/block that would land 23:00–09:00 Kyiv are deferred to 09:00. (Cadence aligns with the 20-min cron: +20 reminder1, +40 reminder2, +60 block.) After block, the user still has `UPLOAD_GRACE_AFTER_BLOCK_MIN` (120 min / 2h, counted from the actual deferred block instant) to self-upload and auto-unblock; past that the bot refuses the photo and points to the accountant. User-facing copy renders the window via `PavilionPhotoService::uploadGraceLabel()` (the `/info` FAQ string hardcodes "2 години" since it lives in a `const` array).

Incoming photos are handled by `PhotoUploadFlow` (service), reached from two entry points: the global `onPhoto` handler (`UploadPhotoCommand`) and any **active conversation** — Nutgram routes every update from a user with a live conversation into that conversation, so `SchedulePavilion` and `OwnSchedule` both call `PhotoUploadFlow::interceptConversationPhoto()`, which ends the conversation and saves the photo inline (never "please resend" — the obligation window is only ~1h). **Add that call to any new multi-step conversation**, and never end a conversation from `__invoke()` via `Conversation::end()`: it reads `$this->bot`, which Nutgram initialises only inside `parent::__invoke()` and strips in `__serialize()`, so on a cache-restored conversation it throws, `/hook` answers 500 and Telegram retries the same photo for an hour (incident 02–16.08.2026: 3 residents blocked for photos they had sent; regression test in `tests/Telegram/BookingConversationPhotoGuardTest`). Prod errors are persisted to `var/log/prod_errors.log` — php-fpm has no `catch_workers_output`, so the stderr handler alone loses everything.

Sessions whose `end < OBLIGATION_START_AT` (constant in `PavilionPhotoService`, default `2026-05-24 00:00 Europe/Kyiv`) are grandfathered — no obligation, no badge. This is how pre-launch bookings stay "done".

Photos live at `public/uploads/pavilion-photos/YYYY/MM/<name>.jpg` (rental listing photos are a separate tree, `rental-photos/`, and a separate code path). `pavilion:photo:cleanup` (daily 03:30) purges files + rows older than `--days` (default 30).

When an admin sets `is_active = true` in `/admin/users`, `PavilionPhotoService::forgiveBlockingRequests()` resolves any currently-blocking open request so the next cron tick doesn't re-block.

A user who uploads a photo **after** `blocked_at` triggers auto-unblock in `PavilionPhotoService::attachPhoto()` — `is_active` flips back to true if (a) debt is within threshold and (b) no other blocking open requests remain. Admin still has the `/admin/photo-requests` table for the rare cases this doesn't cover (a green "✅ Закрити (є фото)" button appears when a same-day photo already exists for the open request).

One-off bulk unblock: `bin/console pavilion:photo:bulk-unblock [--dry-run]` resolves every open blocked request, restores `is_active` (debt-permitting) and notifies users by Telegram. Used once on 2026-05-25 to forgive day-one missed-photo blocks.

## Community vote-to-block lifecycle

Admins open a `BlockVoteCampaign` per candidate via `/admin/block-votes` (by особовий рахунок). Eligible voters = **everyone who may book the pavilion** (`canBookPavilion()` — apartments + parking, кладові excluded), **regardless of `is_active`** (debt/photo-blocked residents still vote), candidate excluded; the count is **snapshotted at open** as the threshold denominator so a vote can't become un-winnable mid-run. Each account casts one `BlockVoteBallot` (unique `(campaign, voter_account)` — any family member owns it, changeable until the deadline) from the bot's 🗳️ menu. When YES crosses **strict majority** (`yesNeeded = ⌊eligible/2⌋+1`) — either instantly on a vote or at the **7-day deadline** (`block-vote:tally`) — the candidate is blocked for **30 days** via `Account.blocked_until` and `is_active=false`.

`blocked_until` is a time-box layered on the shared `is_active` flag. Every unblock path (debt recompute/import/web-upload, photo auto-unblock, admin manual unblock) now honours `Account::isUnderVoteBlock()` so a debt payment or photo upload can't lift a still-active vote-block; `BlockVoteService::autoUnblockExpired()` clears the window on expiry but **re-checks debt + open photo block** before restoring access (and admin manual unblock clears the window outright). Audit sources: `community_vote`, `vote_auto_unblock`.

`Account.vote_block_count` is a repeat-offender tally — incremented on every *passed* campaign (even if the account was already blocked). Surfaced in the bot voting menu (under the candidate), the block/unblock messages, `/admin/block-votes`, the `/admin/users` table + edit modal, and the `/admin/schedule` table. New DataTable columns are appended **last** because `telegram_users.js`/`schedule.js` `columnDefs` target by index. Editing those JS files means a deploy must run `npx encore production`.

## Rental listings ("здається квартира")

`RentalListing` is a resident-facing noticeboard, not a tenancy record. An owner publishes one listing per Account through the `RentalPublish` conversation (rooms → price → description → confirm); every resident reads them under 🔑 Оренда. Apartment/address/area come from the Account, so the only inputs are the three above.

Deliberate rules, each of which someone will be tempted to "fix" later:

- **The list is an index of buttons, not a wall of text.** One button per listing (`кв. 85 · 1-кімн. · 20 000 грн/міс`, `📌` marks your own), tapped to open that listing's card (`rent:view:<id>`) where the description, contact, photo and owner controls live (`📷` in the index marks a listing that has a picture); 10 per page with `rent:page:<n>`. Rendering every description in the index meant scrolling a screen of text to reach the buttons under it. This also leaves room for photos later: a caption + inline keyboard is one editable message, so a card can become a photo card without breaking edit-in-place navigation, whereas a media group cannot carry a keyboard at all.
- **Reading the list needs no confirmed account; publishing does.** Anyone who opens the bot sees the listings, linked to an особовий рахунок or not — a listing is an advertisement, and hiding it from an unlinked newcomer only costs the owner the reader most likely to be flat-hunting. An unlinked reader gets **the list and nothing else**: no publish button, no explanation of the restriction, and *not* the accountant's phone — deliberately, don't "helpfully" add a note there (the usual mark-and-explain rule doesn't apply: they are not being denied anything they could otherwise do, and Alina's number isn't for unlinked strangers). Publishing needs the Account because apartment/address/area are read from it; `RentalPublish::askRooms` refuses and explains for anyone who reaches it. When an unlinked person uses the relay, the owner sees `(не підтверджений ОСББ)` instead of an apartment number and judges for themselves.
- **`is_active` is NOT checked.** A debt or a missed pavilion photo blocks *booking*; it must not block an owner from advertising their own property. `RentalListingService::canPublish()` only excludes storage and parking units (their listing line is written for flats). Regression test: `tests/Service/RentalListingRulesTest`.
- **Apartment photos are optional and never arrive through Telegram.** The owner's card has `📷 Фото (n/3)` → `rent:photos:<id>`, which mints a one-shot token (`RentalListing.photo_token`, 24h) and hands over a link to `/rent/photo/{token}` — a standalone mobile page (no Encore, no login; the token *is* the authorisation) that downscales in the browser to 1600px before uploading, because prod `upload_max_filesize` is 2M and a phone photo is 3–6 MB. `RentalPhotoService` re-encodes through GD as a backstop, which also strips EXIF/GPS. Paths live in `RentalListing.photos` (JSON, max 3), files under `public/uploads/rental-photos/YYYY/MM/`, purged when the listing is withdrawn/replaced/expired but **kept on an admin take-down** (the photo is usually why it was taken down).

  **Why not just accept a photo in the bot:** `pavilion:photo:check` materialises a `PhotoUploadRequest` only every 20 minutes, so for up to 20 minutes after a booking ends there is no open request. Any in-bot rule of the shape "no open obligation ⇒ this must be a flat photo" would swallow the pavilion photo of the resident who sent it *immediately* — the most conscientious one — and the cron would then block them for evidence already sent. Keeping this channel on the web means a picture sent to the bot is always pavilion evidence, with no rule to get wrong. `PhotoUploadFlow` is untouched by this feature; `RentalPublish` still carries the mandatory `interceptConversationPhoto()` guard (covered by the shared provider in `BookingConversationPhotoGuardTest`), and has no photo step of its own.
- **Phones are opt-in, never automatic.** The number is in the DB because the resident gave it to the ОСББ for нарахування, not for publication, so `RentalPublish` asks once (`askContact` step, number shown in full) and stores the consent as `RentalListing.show_phone` plus a display-formatted `contact_phone` **snapshot** — consent was for *that* number, so a later registry change doesn't silently republish a different one. Default is false, which is what the pre-2026-08-26 listings keep. Contact is otherwise a `t.me/<username>` button. Only ~48% of `telegram_user` rows have a username, so the relay path (`rent:contact:<id>`) is the common one, and when the interested resident has no username either it asks *them* for consent to pass their number (`rent:phone:<id>`) instead of the old dead end that told them to go reconfigure Telegram. `RentalListingService::formatPhone()` is the single normaliser — phones arrive as both `+380…` and `380…`.
- Listings expire after `RentalListing::LIFETIME_DAYS` (30). `rental:expire` (daily) sends a one-shot "ще актуально?" prompt `RENEW_PROMPT_BEFORE_DAYS` (3) before that and closes the rest. Queries filter on `expires_at` too, so a stale listing disappears even if the cron hasn't run.
- Publishing again **replaces** the account's active listing rather than being rejected — that is the edit path.

Admin: `/admin/rentals` lists everything with a take-down button (status `blocked`, stamped with the admin login). Debt is shown for context only.

## Crons (prod `crontab -l`, **must run as `www-data`**)

```
45 * * * * sudo -u www-data php …/city-park/bin/console RemindCommand
10 * * * * sudo -u www-data php …/city-park/bin/console WarmWeatherCommand
0 9 15 * * sudo -u www-data php …/city-park/bin/console DebtNotifyCommand
*/20 * * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:check --env=prod
30 3 * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:cleanup --env=prod
0 * * * * sudo -u www-data php …/city-park/bin/console block-vote:tally --env=prod
0 4 * * * sudo -u www-data php …/city-park/bin/console rental:expire --env=prod
```

**The `block-vote:tally` hourly cron is required** — without it, deadline-passed campaigns never close and 30-day vote-blocks never auto-unblock. Install it on deploy.

Opening a campaign no longer broadcasts synchronously in the admin request: `openCampaign()` dispatches one `App\Message\VoteBroadcastMessage` per eligible voter to the **Symfony Messenger `async` (Doctrine) transport** (`MESSENGER_TRANSPORT_DSN=doctrine://default`, table `messenger_messages`), handled by `VoteBroadcastMessageHandler` → `BlockVoteService::deliverOpenedNotice()`. A persistent **systemd worker `city-park-messenger.service`** (mirrors `doshka-messenger.service`, runs `messenger:consume async` as `www-data`) must be running on prod, else notices queue but never send. Verify with `vote:dispatch-test` (enqueues a harmless no-op). Block/unblock notices (single account) stay synchronous.

**The worker is a long-running PHP process — it holds the code loaded at start.** After any deploy that touches a Message/handler/`BlockVoteService`, run `systemctl restart city-park-messenger.service` so it picks up new code (otherwise it runs stale code until the hourly `--time-limit=3600` exit). Harmless to restart every deploy.

**Never run as root** — root-owned Symfony cache pool files break conversation state (incident 2026-05-03). After every deploy verify `ls -ld var/cache/prod/pools/app/` shows `www-data`.

## Deploy

```
ssh root@prod
cd /var/www/html/city-park
git pull origin master
composer install --no-dev --optimize-autoloader --no-interaction   # if composer.lock changed
NODE_OPTIONS=--openssl-legacy-provider npx encore production       # if assets/twig changed (flag needed for prod Node 17+ vs old webpack/terser)
rm -rf var/cache/prod && php bin/console cache:warmup --env=prod
php bin/console doctrine:migrations:migrate --no-interaction --env=prod   # if migration added
sudo -u www-data php bin/console bot:menu:update --env=prod          # idempotent; safe every deploy
mkdir -p public/uploads/pavilion-photos
chown -R www-data:www-data var/cache var/log public/uploads
systemctl reload php8.3-fpm
```

Feature-branch workflow preferred for normal work; direct master only when explicitly approved.

## Memory pointers

User-level auto-memory at `~/.claude/projects/-home-ivan-hosts-city-park/memory/` mirrors most of this (project_photo_obligation, project_booking_rules, reference_prod_cron, reference_admin_panel, reference_deploy, reference_prod_paths). When using this repo from a fresh checkout on another machine, this CLAUDE.md is the portable copy; the per-machine memory files supplement it with cross-session preferences.
