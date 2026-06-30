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
| Бронювання | `schedule-pavilion` | `/schedule` | `SchedulePavilion` (conversation) |
| Переглянути свої | `own-schedule` | — | `OwnSchedule` |
| Як доїхати? | `type:route` | — | `RouteCommand` |
| 📜 Історія бронювань | `booking-history` + `bh:week:YYYY-Www` | `/history` | `BookingHistory` (weekly paginated, last 30 days, photo status badges) |
| 📸 Завантажити фото | `photo-upload-info` | `/photo` | `PhotoUploadInfo` (lists open requests) |
| ℹ️ Інструкція та FAQ | `info-menu` / `info-topic:*` | `/info` | `InfoCommand` (edit `TOPICS` const) |
| 🗳️ Голосування | `voting-menu` / `bvote:<id>:yes\|no` | `/vote` | `VotingMenuCommand` (community vote-to-block) |
| 🏠 На головну | `main-menu` | `/start` | `StartCommand::__invoke` re-renders menu |
| (auto) photo upload | `onPhoto` event | — | `UploadPhotoCommand` |

**Slash menu must be pushed via `bin/console bot:menu:update --env=prod` after editing `BotMenuUpdateCommand::MENU`.** Nutgram's `setMyCommands()` has a null-scope bug; the command uses raw `sendRequest()` instead.

## Photo-obligation lifecycle

`PavilionPhoto` (artifact) and `PhotoUploadRequest` (obligation) are separate. The cron `pavilion:photo:check` (every 20 min) materialises requests for past sessions inside `PavilionPhotoService::LOOKBACK_HOURS` (26). Reminders fire at end+20/+40 min; block at end+`BLOCK_AFTER_MIN` (60), i.e. within the hour, so the photo stays fresh evidence of the pavilion's condition before the next booker can change it. Reminders/block that would land 23:00–09:00 Kyiv are deferred to 09:00. (Cadence aligns with the 20-min cron: +20 reminder1, +40 reminder2, +60 block.) After block, the user still has `UPLOAD_GRACE_AFTER_BLOCK_MIN` (120 min / 2h, counted from the actual deferred block instant) to self-upload and auto-unblock; past that the bot refuses the photo and points to the accountant. User-facing copy renders the window via `PavilionPhotoService::uploadGraceLabel()` (the `/info` FAQ string hardcodes "2 години" since it lives in a `const` array).

Sessions whose `end < OBLIGATION_START_AT` (constant in `PavilionPhotoService`, default `2026-05-24 00:00 Europe/Kyiv`) are grandfathered — no obligation, no badge. This is how pre-launch bookings stay "done".

Photos live at `public/uploads/pavilion-photos/YYYY/MM/<name>.jpg`. `pavilion:photo:cleanup` (daily 03:30) purges files + rows older than `--days` (default 30).

When an admin sets `is_active = true` in `/admin/users`, `PavilionPhotoService::forgiveBlockingRequests()` resolves any currently-blocking open request so the next cron tick doesn't re-block.

A user who uploads a photo **after** `blocked_at` triggers auto-unblock in `PavilionPhotoService::attachPhoto()` — `is_active` flips back to true if (a) debt is within threshold and (b) no other blocking open requests remain. Admin still has the `/admin/photo-requests` table for the rare cases this doesn't cover (a green "✅ Закрити (є фото)" button appears when a same-day photo already exists for the open request).

One-off bulk unblock: `bin/console pavilion:photo:bulk-unblock [--dry-run]` resolves every open blocked request, restores `is_active` (debt-permitting) and notifies users by Telegram. Used once on 2026-05-25 to forgive day-one missed-photo blocks.

## Community vote-to-block lifecycle

Admins open a `BlockVoteCampaign` per candidate via `/admin/block-votes` (by особовий рахунок). Eligible voters = **active apartment accounts** (`isApartment()`, candidate excluded); the count is **snapshotted at open** as the threshold denominator so a vote can't become un-winnable mid-run. Each account casts one `BlockVoteBallot` (unique `(campaign, voter_account)` — any family member owns it, changeable until the deadline) from the bot's 🗳️ menu. When YES crosses **strict majority** (`yesNeeded = ⌊eligible/2⌋+1`) — either instantly on a vote or at the **7-day deadline** (`block-vote:tally`) — the candidate is blocked for **30 days** via `Account.blocked_until` and `is_active=false`.

`blocked_until` is a time-box layered on the shared `is_active` flag. Every unblock path (debt recompute/import/web-upload, photo auto-unblock, admin manual unblock) now honours `Account::isUnderVoteBlock()` so a debt payment or photo upload can't lift a still-active vote-block; `BlockVoteService::autoUnblockExpired()` clears the window on expiry but **re-checks debt + open photo block** before restoring access (and admin manual unblock clears the window outright). Audit sources: `community_vote`, `vote_auto_unblock`.

`Account.vote_block_count` is a repeat-offender tally — incremented on every *passed* campaign (even if the account was already blocked). Surfaced in the bot voting menu (under the candidate), the block/unblock messages, `/admin/block-votes`, the `/admin/users` table + edit modal, and the `/admin/schedule` table. New DataTable columns are appended **last** because `telegram_users.js`/`schedule.js` `columnDefs` target by index. Editing those JS files means a deploy must run `npx encore production`.

## Crons (prod `crontab -l`, **must run as `www-data`**)

```
45 * * * * sudo -u www-data php …/city-park/bin/console RemindCommand
10 * * * * sudo -u www-data php …/city-park/bin/console WarmWeatherCommand
0 9 15 * * sudo -u www-data php …/city-park/bin/console DebtNotifyCommand
*/20 * * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:check --env=prod
30 3 * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:cleanup --env=prod
0 * * * * sudo -u www-data php …/city-park/bin/console block-vote:tally --env=prod
```

**The `block-vote:tally` hourly cron is required** — without it, deadline-passed campaigns never close and 30-day vote-blocks never auto-unblock. Install it on deploy.

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
