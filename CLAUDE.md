# city-park — context for Claude Code

Symfony 7 + Nutgram Telegram bot for ОСББ pavilion booking. Prod bot `@che_city_park_bot`, dev `@dev_che_city_park_bot`.

## Where things live

- Prod path: `/var/www/html/city-park/` (server REDACTED_HOST, `ssh -i ~/.ssh/REDACTED_KEY root@…`)
- Admin: https://citypark.shuba.dev/admin (login at `/login`, password = env `MAIN_ADMIN_PASSWORD`)
- Logs: `var/log/{prod,remind,photo-check,photo-cleanup,debt-notify,warm-weather}.log`

## Core domain

- `Account` is the tenant unit. One Account ↔ many `TelegramUser` (family members / conditional owners).
- `Account.is_active = false` is the single block flag — used by debt blocking AND photo-miss blocking. Toggled via `/admin/users`.
- `ScheduledSet` is one row per booked **hour** (no merging). A "session" = consecutive same-pavilion hours by one account, detected at query time.
- Booking limits live in `src/Validator/`: ≤ 4h / day, ≤ 20h / month, no cross-pavilion overlap same hour, no orphan-1h gaps between existing bookings, debt threshold (env `DEBT_BLOCK_THRESHOLD`, default 1300 UAH).

## Bot menu (callback wiring in `config/telegram.php`)

| Button | Callback | Handler |
|---|---|---|
| Бронювання | `schedule-pavilion` | `SchedulePavilion` (conversation) |
| Переглянути свої | `own-schedule` | `OwnSchedule` |
| Як доїхати? | `type:route` | `RouteCommand` |
| 📜 Історія бронювань | `booking-history` | `BookingHistory` (last 30 days, full info) |
| 📸 Завантажити фото | `photo-upload-info` | `PhotoUploadInfo` (lists open requests) |
| ℹ️ Інструкція та FAQ | `info-menu` / `info-topic:*` | `InfoCommand` (edit `TOPICS` const) |
| (auto) photo upload | `onPhoto` event | `UploadPhotoCommand` |

## Photo-obligation lifecycle

`PavilionPhoto` (artifact) and `PhotoUploadRequest` (obligation) are separate. The cron `pavilion:photo:check` (every 20 min) materialises requests for past sessions inside `PavilionPhotoService::LOOKBACK_HOURS` (26). Reminders fire at end+20/+40/+60 min, block at +80. Reminders that would land 23:00–09:00 Kyiv are deferred to 09:00.

Sessions whose `end < OBLIGATION_START_AT` (constant in `PavilionPhotoService`, default `2026-05-24 00:00 Europe/Kyiv`) are grandfathered — no obligation, no badge. This is how pre-launch bookings stay "done".

Photos live at `public/uploads/pavilion-photos/YYYY/MM/<name>.jpg`. `pavilion:photo:cleanup` (daily 03:30) purges files + rows older than `--days` (default 30).

When an admin sets `is_active = true` in `/admin/users`, `PavilionPhotoService::forgiveBlockingRequests()` resolves any currently-blocking open request so the next cron tick doesn't re-block.

## Crons (prod `crontab -l`, **must run as `www-data`**)

```
45 * * * * sudo -u www-data php …/city-park/bin/console RemindCommand
10 * * * * sudo -u www-data php …/city-park/bin/console WarmWeatherCommand
0 9 15 * * sudo -u www-data php …/city-park/bin/console DebtNotifyCommand
*/20 * * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:check --env=prod
30 3 * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:cleanup --env=prod
```

**Never run as root** — root-owned Symfony cache pool files break conversation state (incident 2026-05-03). After every deploy verify `ls -ld var/cache/prod/pools/app/` shows `www-data`.

## Deploy

```
ssh root@prod
cd /var/www/html/city-park
git pull origin master
npx encore production              # if assets/twig changed
rm -rf var/cache/prod && php bin/console cache:warmup --env=prod
php bin/console doctrine:migrations:migrate --no-interaction --env=prod   # if migration added
mkdir -p public/uploads/pavilion-photos
chown -R www-data:www-data var/cache var/log public/uploads
systemctl reload php8.3-fpm
```

Feature-branch workflow preferred for normal work; direct master only when explicitly approved.

## Memory pointers

User-level auto-memory at `~/.claude/projects/-home-ivan-hosts-city-park/memory/` mirrors most of this (project_photo_obligation, project_booking_rules, reference_prod_cron, reference_admin_panel, reference_deploy, reference_prod_paths). When using this repo from a fresh checkout on another machine, this CLAUDE.md is the portable copy; the per-machine memory files supplement it with cross-session preferences.
