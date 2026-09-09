# city-park — context for Claude Code

Symfony 7 + Nutgram Telegram bot for ОСББ pavilion booking. Prod bot `@che_city_park_bot`, dev `@dev_che_city_park_bot`.

## Where things live

- Prod path: `/var/www/html/city-park/` on the production server (host + SSH key live in per-machine memory, not in this file)
- Admin login at `/login`, password = env `MAIN_ADMIN_PASSWORD`
- Logs: `var/log/{prod,remind,photo-check,photo-cleanup,debt-notify,warm-weather,resident-chat}.log`

## Core domain

- `Account` is the tenant unit. One Account ↔ many `TelegramUser` (family members / conditional owners).
- **`Account.unit_type` is what kind of property it is** — `apartment` / `parking` /
  `storage`, stored since 04.09.2026 and seeded from the old formula so nothing changed on
  the day it shipped. Everything goes through `getUnitType()`; `deriveUnitType()` (third
  digit of `account_number`, plus the legacy free-text check) is now only the fallback for a
  row that has none, and `/admin/objects` can correct one by hand. It was worth writing down
  because the formula reads a *position*: `42076` is five digits, so its third character is
  the `0` of what should have been `420076`, and the row answered "apartment" with full
  confidence. The type decides the label on the public board, the address in a complaint
  posted to the chat, and `canBookPavilion()` — one wrong row is three wrong things.
- `Account.is_active = false` is the single block flag — used by debt blocking AND photo-miss blocking. Toggled via `/admin/users`.
- `Account::canBookPavilion()` blocks **storage** units from booking *structurally* (checked before `is_active` — admins can't grant booking to a кладова without renaming the unit). Parking **is** allowed: its owners pay the yard fee. The unit type comes from the third digit of `account_number` (`getUnitTypeDigit()`: 0 apartment, 5 storage, 7 parking), with `isStorage()` also matching legacy free-text `apartment_number` (кладов/комірчина/storage). There is no `isNonResidential()` — this file claimed one until 02.09.2026.
- `ScheduledSet` is one row per booked **hour** (no merging). A "session" = consecutive same-pavilion hours by one account, detected at query time.
- Booking limits live in `src/Validator/`: ≤ 3h / day, ≤ 12h / month, no cross-pavilion overlap same hour, bookings must be contiguous (one unbroken run per pavilion/day — no scattered hours), working hours 09:00–23:00 both pavilions (no night; last slot starts 22:00), per-account debt threshold computed as `area × tariff.price_per_meter × 1.5` (`DebtPolicy::getThresholdFor`). Fallback to env `DEBT_BLOCK_THRESHOLD` (1300 UAH) when either `Account.area` or `Tariff.price_per_meter` is missing/zero. Tariff is a single-row table set via `/admin/tariff`.

## Bot menu (callback wiring in `config/telegram.php`)

| Button | Callback | Slash | Handler |
|---|---|---|---|
| 🔑 Оренда та продаж | `rental-menu` / `rent:new` / `rent:new-sale` / `rent:deal:<all\|rent\|sale>:<page>` / `rent:{view,page,photos,contact,phone,extend,remove}:<id>` / `rent:pic:<id>:<n>` | `/rent` | `RentalMenuCommand` + `RentalPublish` (conversation) |
| Бронювання | `schedule-pavilion` | `/schedule` | `SchedulePavilion` (conversation) |
| Переглянути свої | `own-schedule` | — | `OwnSchedule` |
| Як доїхати? | `type:route` | — | `RouteCommand` |
| 📜 Історія бронювань | `booking-history` + `bh:week:YYYY-Www` | `/history` | `BookingHistory` (weekly paginated, last 30 days, photo status badges) |
| 📸 Завантажити фото | `photo-upload-info` | `/photo` | `PhotoUploadInfo` (lists open requests) |
| ℹ️ Інструкція та FAQ | `info-menu` / `info-topic:*` | `/info` | `InfoCommand` (edit `TOPICS` const) |
| 🗳️ Голосування | `voting-menu` / `bvote:<id>:yes\|no` | `/vote` | `VotingMenuCommand` (community vote-to-block) |
| 🏘 Чат мешканців | `resident-chat` | `/chat` | `ResidentChatCommand` (hands out the join-request link) |
| 🔧 Заявки | `complaints-menu` / `cmp:{view,photos,page,del,delok,edit}:<id>` / `cmp:pic:<id>:<n>` / `cmp:status:<id>:<status>` / `cmp:new` | `/problem` | `ComplaintMenuCommand` + `ComplaintCreate` / `ComplaintEdit` (conversations) |
| 🛡 Хто зараз в альтанці | `guard-board` | `/guard` | `GuardCommand` (guards only — **not** in the pushed slash menu) |
| 🔒 QR для охорони | `guard-qr` | — | `GuardQrCommand` (resident, only while a booking runs); scanned via `/start g-…` → `GuardScanCommand` |
| 🏠 На головну | `main-menu` | `/start` | `StartCommand::__invoke` re-renders menu |

The contact block under the menu header names four people — the head of the ОСББ, the
accountant, **Сергій as «відповідальний за заявки»** (added 07.09.2026) and the developer —
each with one italic line saying what they are for. The two officers get `t.me/+<phone>`
because neither has a @username; Сергій and the developer get theirs, and **Сергій's phone
is deliberately not published**: the officers' numbers are the ОСББ's own published lines,
his is a private one that would reach 457 people the moment it were printed there.
The header also names the head of the ОСББ (Людмила Осипенко) with her number and a
`t.me/+<phone>` link — she has no Telegram @username, the registry field is empty. Shown to
linked residents only, the same call already made for the accountant's number: an unlinked
visitor browsing 🔑 Оренда is not owed the officers' phones.
| (auto) photo upload | `onPhoto` event | — | `UploadPhotoCommand` |

**Slash menu must be pushed via `bin/console bot:menu:update --env=prod` after editing `BotMenuUpdateCommand::MENU`.** It is registered for **private chats only** (`scope: all_private_chats`), and the command also clears the default scope — registered without a scope the commands show in the residents' group too, where a tap does nothing because the global middleware drops group updates. Setting the private scope does not empty the default one; both calls are needed. Its array order is what Telegram renders, and it is kept in sync with the inline menu in `StartCommand::mainMenuMarkup()` — 🔑 Оренда is first in both (residents were not finding it at the bottom). Nutgram's `setMyCommands()` has a null-scope bug; the command uses raw `sendRequest()` instead.

## Photo-obligation lifecycle

`PavilionPhoto` (artifact) and `PhotoUploadRequest` (obligation) are separate. The cron `pavilion:photo:check` (every 20 min) materialises requests for past sessions inside `PavilionPhotoService::LOOKBACK_HOURS` (26). Reminders fire at end+20/+40 min; block at end+`BLOCK_AFTER_MIN` (60), i.e. within the hour, so the photo stays fresh evidence of the pavilion's condition before the next booker can change it. Reminders/block that would land 23:00–09:00 Kyiv are deferred to 09:00. (Cadence aligns with the 20-min cron: +20 reminder1, +40 reminder2, +60 block.) After block, the user still has `UPLOAD_GRACE_AFTER_BLOCK_MIN` (120 min / 2h, counted from the actual deferred block instant) to self-upload and auto-unblock; past that the bot refuses the photo and points to the accountant. User-facing copy renders the window via `PavilionPhotoService::uploadGraceLabel()` (the `/info` FAQ string hardcodes "2 години" since it lives in a `const` array).

Incoming photos are handled by `PhotoUploadFlow` (service), reached from two entry points: the global `onPhoto` handler (`UploadPhotoCommand`) and any **active conversation** — Nutgram routes every update from a user with a live conversation into that conversation, so `SchedulePavilion` and `OwnSchedule` both call `PhotoUploadFlow::interceptConversationPhoto()`, which ends the conversation and saves the photo inline (never "please resend" — the obligation window is only ~1h). A photo arriving with **no pavilion booking behind it at all** no longer ends the
conversation: `interceptConversationPhoto()` checks `hasPavilionContext()` first (open
request, running session, or one that ended inside `LOOKBACK_HOURS`) and, finding none,
keeps the conversation alive and says so via its `keptNotice`. This does not weaken the
invariant — the case it protects, a session that ended before the 20-minute cron
materialised its request, *requires a session to exist*. Found on 02.09.2026 when filing a
complaint with a photo instead of text threw the draft away and answered «Фото вже
отримано» to somebody who had never booked anything. That message is now split too: "вже
отримано" only for a resident who actually had a session.

**Guard on `PhotoUploadFlow::isIncomingPhoto($bot)`, never on `$bot->message()?->photo`.**
On a callback query `$bot->message()` is the message the button hangs on — and a complaint
or rental card that has pictures *is* a photo message. The naive check therefore fires on a
**tap**: the conversation answers «📷 Фото сюди не додається», returns before
`parent::__invoke()`, never advances its step, and every later text lands on a step waiting
for a callback. From the outside it is «натискаю — нічого не міняється, ввожу текст —
нічого», with nothing in the logs, because nothing failed. Found 07.09.2026 on complaint #6,
which had photos; #11, which had none, worked perfectly and hid it for an afternoon.
`BookingConversationPhotoGuardTest` walks every conversation and fails on the naive form.

**Add that call to any new multi-step conversation**, and never end a conversation from `__invoke()` via `Conversation::end()`: it reads `$this->bot`, which Nutgram initialises only inside `parent::__invoke()` and strips in `__serialize()`, so on a cache-restored conversation it throws, `/hook` answers 500 and Telegram retries the same photo for an hour (incident 02–16.08.2026: 3 residents blocked for photos they had sent; regression test in `tests/Telegram/BookingConversationPhotoGuardTest`). Prod errors are persisted to `var/log/prod_errors.log` — php-fpm has no `catch_workers_output`, so the stderr handler alone loses everything.

Sessions whose `end < OBLIGATION_START_AT` (constant in `PavilionPhotoService`, default `2026-05-24 00:00 Europe/Kyiv`) are grandfathered — no obligation, no badge. This is how pre-launch bookings stay "done".

Photos live at `public/uploads/pavilion-photos/YYYY/MM/<name>.jpg` (rental listing photos are a separate tree, `rental-photos/`, and a separate code path). `pavilion:photo:cleanup` (daily 03:30) purges files + rows older than `--days` (default 30).

When an admin sets `is_active = true` in `/admin/users`, `PavilionPhotoService::forgiveBlockingRequests()` resolves any currently-blocking open request so the next cron tick doesn't re-block.

A user who uploads a photo **after** `blocked_at` triggers auto-unblock in `PavilionPhotoService::attachPhoto()` — `is_active` flips back to true if (a) debt is within threshold and (b) no other blocking open requests remain. Admin still has the `/admin/photo-requests` table for the rare cases this doesn't cover (a green "✅ Закрити (є фото)" button appears when a same-day photo already exists for the open request).

One-off bulk unblock: `bin/console pavilion:photo:bulk-unblock [--dry-run]` resolves every open blocked request, restores `is_active` (debt-permitting) and notifies users by Telegram. Used once on 2026-05-25 to forgive day-one missed-photo blocks.

## Votes of the house (block, and questions)

**One entity, two kinds** (`BlockVoteCampaign.kind`, since 08.09.2026), the same call the
rental board made about rent and sale: everything around a vote is identical — who may vote,
one ballot per account changeable until the deadline, the eligible count snapshotted at open,
the 7-day deadline, the async broadcast, the final-day reminder, the tally cron, the archive
— and what differs is the subject and the consequence. Two entities would be two copies of
that machinery and one of them would rot.

- **`block`** — somebody is proposed for a 30-day block. Has a threshold, and crossing it
  *does* something, so it ends `passed` (and blocks) or `failed`.
- **`question`** — «Чи встановлюємо шлагбаум?». **Advisory, and that is the design.** No
  threshold (`yesNeeded()` is 0 and callers must not print «треба N» — a target beside
  something that enacts nothing is a promise the bot cannot keep), no candidate, ends
  `STATUS_CLOSED` with the counts. Declaring «рішення прийнято» off three ballots out of a
  hundred and eighty would be the bot inventing a mandate nobody gave it, so the ОСББ reads
  the numbers and decides. Yes/no only: a question needing three options is a question
  needing a meeting, and pretending otherwise produces a figure the ОСББ then has to explain
  away. `applyBlock()` refuses a question outright whatever calls it —
  `HouseQuestionRulesTest` pins that, and pins the absent threshold, because "make the two
  kinds consistent" is a tempting and wrong tidy-up.

**Every vote is posted into the chat's `🗳 Голосування` topic** and the *same message* is
edited with the result when it closes — `editMessageText` has none of `deleteMessage`'s
48-hour limit and a vote runs for seven days, so the announcement stays where the discussion
under it is. A thread carrying «відкрито голосування» and no ending is exactly how the same
question comes back next spring. The «↗️ Проголосувати в боті» button goes when the vote does:
a live one under a closed vote is worse than none. The DM broadcast is **not** replaced by
this — a DM reaches the people who may vote, the post is what makes the vote something the
house can see happening and carries the result to everyone afterwards, including the flats
with nobody in the bot who cannot vote but live here. Silent when
`RESIDENT_CHAT_TOPIC_VOTES` is unset, and never fatal.

**A vote is final, and it is on the record.** `BlockVoteBallot` stores who cast it
(`voter_user`, SET NULL) and when, and `/admin/block-votes` lists every ballot under its
campaign — flat, name, @username, time. Changing a vote used to be allowed until the
deadline, which is a fair rule for an anonymous poll and a bad one for a recorded ballot: a
record that can be rewritten until the last minute is not a record, and it removes the shape
where somebody watches the tally and flips at the end. `recordVote()` refuses a second
ballot and answers with what the account already voted, since a repeat tap is usually
somebody checking. In the bot the row becomes «✅ Ви проголосували: За» — an inert pill, not
a button, because a live button under a final vote invites a tap that can only be refused.
**Residents never see who voted how**; only the four panel logins do. That is a real change
for a block campaign — voting to block a neighbour is now attributable — and it was made
knowingly.

**The main menu carries the count, and the vote carries a 🔄.** «🗳️ Голосування (1)» is the
only thing on the main screen that says the house is deciding something — before it, an open
vote reached the people who opened the section or read the chat post, and a vote ends on a
count, so the residents it missed were the ballots it needed. `StartCommand::votingLabel()`
asks `BlockVoteService::openVoteCount()` at render time (which is why that service is
`public: true`, same trap as the guard buttons), and it counts what is **open to this
reader**, not what they still owe: a ballot here is final, so a to-do badge would vanish the
moment they voted and take the notice with it — and half its value is telling somebody who
*has* voted that the vote is still running, so they mention it to a neighbour. Same reading
as «🔧 Заявки (5)» and «🛠 Послуги (3)».

The tally inside the message is a snapshot from when it was drawn, and a vote runs a week,
so «🔄 Оновити» re-renders it in place. **A refresh that changes nothing must not send a
second menu**: Telegram refuses an identical edit, `respond()` falls through to
`sendMessage()` on any edit failure — right everywhere else — and on this button the
unchanged case is the *common* one, so it would post one more copy of the whole menu per
tap. The unchanged branch answers with a toast and returns; any other failure still falls
through. `VotingMenuTest` pins both, and pins that every public callback constant on the
menu is actually wired in `config/telegram.php` — an unrouted button spins, which reads as
the bot being down.

**📜 Минулі голосування** — the archive, in the bot for everyone and on `/admin/block-votes`
in full. Both kinds in one list: they are the same act, and splitting them would hide how
rarely either happens. Cancelled campaigns are left out — one an admin withdrew before the
deadline is not a decision the house took, and listing it would put a name beside a result
nobody voted for. It is also the only evidence the house has that voting does anything:
«за це вже голосували, ось як» is what stops the same question returning to the chat every
spring.

## Community vote-to-block lifecycle

Admins open a `BlockVoteCampaign` per candidate via `/admin/block-votes` (by особовий рахунок). Eligible voters = everyone who may book the pavilion (`canBookPavilion()` — apartments +
parking, кладові excluded) **and has a resident linked in the bot**, **regardless of
`is_active`** (debt/photo-blocked residents still vote), candidate excluded — see the
threshold section for why the second half is load-bearing; the count is **snapshotted at open** as the threshold denominator so a vote can't become un-winnable mid-run. Each account casts one `BlockVoteBallot` (unique `(campaign, voter_account)` — any family member owns it, changeable until the deadline) from the bot's 🗳️ menu. When YES crosses the threshold (`yesNeeded = ⌊eligible × PASS_FRACTION⌋+1`, PASS_FRACTION
**0.30**) — either instantly on a vote or at the **7-day deadline** (`block-vote:tally`) — the candidate is blocked for **30 days** via `Account.blocked_until` and `is_active=false`.

`blocked_until` is a time-box layered on the shared `is_active` flag. Every unblock path (debt recompute/import/web-upload, photo auto-unblock, admin manual unblock) now honours `Account::isUnderVoteBlock()` so a debt payment or photo upload can't lift a still-active vote-block; `BlockVoteService::autoUnblockExpired()` clears the window on expiry but **re-checks debt + open photo block** before restoring access (and admin manual unblock clears the window outright). Audit sources: `community_vote`, `vote_auto_unblock`.

**`TelegramUser.role`** (owner / family / tenant, NULL = не вказано) records what the person
is *to the flat*.

**`tenant` is the one role that decides anything** (since 08.09.2026, via
`TelegramUser::owesForTheFlat()`): the bot no longer tells a tenant that the flat's arrears
are theirs. Gone for them are the debt figures beside the особовий рахунок in the menu
header, the «📌 (це ви)» marks on the podium and in the full list, the «📌 Ваша квартира у
списку» line, the «📌 Моя квартира» jump button, and the monthly `DebtNotifyCommand`
reminder. **The board itself is not hidden** — it names every flat in the house to every
resident, so their neighbour reads the same line, and hiding it would leave a tenant less
informed than anybody else while protecting nothing. What is switched off is the bot
*claiming* the debt is theirs. NULL is not treated as tenant: most of прод is unlabelled and
the accountant has not said those people rent, so treating them as tenants would quietly
stop the debt reaching most of the house. Everything else the role touches is still
descriptive — booking, the chat, заявки and послуги are unchanged, and the block stays on
the account, so a tenant of a blocked flat still cannot book. `TenantIsNotTheDebtorTest`
pins each gate, because this is exactly the kind of asymmetry somebody tidies away while
making the roles consistent. The bot cannot derive it — it holds no owner names, only a flat and a
phone — so the accountant sets it from what she is told («у мене орендатори», «я орендар»).
Deliberately **not** seeded by the "only person on a flat must be the owner" heuristic: that
is right most of the time and confidently wrong wherever a tenant registered first, and a
field wrong in an unknown subset is worse than one honestly empty. It lives on the person,
not the account: a tenant and the owner share one rahunok and must not share one label.

**`/admin/users` shows everyone, and must keep doing so.** Anyone who has ever pressed
/start has a `TelegramUser` row — verified on prod 03.09.2026: **449 rows, 181 linked to a
flat, 268 not**. (An earlier note here said "274 against 172"; that was wrong, and the
DataTables footer is the quickest check — «filtered from N total entries» is the honest
row count now that no default hides anything.) For one day (02–03.09.2026) the table filtered to `account IS NOT NULL` by default,
because rows with no о/р and no address read as "who are all these people?". **That default
is reverted and must not come back.** On 03.09.2026 Аліна searched a resident's phone here,
found nothing and told him «немає номера в базі» — his row had been in the table for an hour
and a half, unlinked and therefore invisible. She refused to add him without an
identification he would not give, and he took it to the residents' chat. A default that
hides rows turns "I did not find him" into "he is not in the system", and from the outside
nobody can tell the two apart.

Both halves stay as explicit filters in the radio group: `✅ Підтверджені мешканці`
(`status_filter=linked`, Людмила's view) and `⏳ Чекають прив'язки` (`status_filter=unlinked`).
They are the two filters that swap the set of rows rather than narrowing it, and they live in
`TelegramUserRepository::buildDataTablesFilters()` — a static method precisely so the rules
are testable without a database (`tests/Repository/AdminUserSearchTest`). A row with no
account renders as `⏳ Не прив'язаний`, never as a red «Заблокований»: `is_active` is NULL
for someone the bot has no flat for, and calling that "blocked" accuses it of blocking a
person it never heard of.

**Retire a column with `visible: false`, never by deleting it from `$dataTableFields`.**
Every `columnDef` in `telegram_users.js` / `schedule.js` targets its column by **index**, so
removing one from the middle shifts the rest and repaints the wrong cells (the debt renderer
onto area, area onto the threshold). Retired this way and drawn inside a neighbouring cell
instead: `street`, `house_number` (in the address), `debt_threshold` (under the debt),
`last_name` (with the first), `additional_phones` (a «+1 родич» count by the phone), `start`
(under the last activity), `vote_blocks` (a red note by the status, only when non-zero).
Column titles come from the `th_titles` map in the template — a field appended to the entity
later must be added there or it renders as its raw English name. The whole row opens the edit
card; the first cell, `.dtr-control`, `.dtr-details` and the expanded `tr.child` are excluded
so the responsive expander still works on a narrow screen.

**Sorting resolves through an explicit map with an `?? 'b.id'` fallback.** The old shape
prefixed known columns with `a.`/`b.` and passed anything else through bare, so a click on
`vote_blocks`, `role`, `area` or `debt_threshold` reached Doctrine as `ORDER BY vote_blocks`
— Semantical Error, 500, "DataTables warning: Ajax error" (03.09.2026). Columns with no
stored counterpart map to what they derive from: `debt_threshold` → `a.area`, `photo_status`
→ booking time, `vote_blocks` → `a.vote_block_count`.
`AdminUserSearchTest::testEveryTableColumnIsSafeToSortBy` walks `$dataTableFields` and fails
on any column the map forgot.

**Never `array_unique()` the bound values.** They are keyed placeholders and `array_unique`
deduplicates by *value*: the same phone typed into both the global `Search:` box and the
«Телефон» column dropped one key, and the query reached Doctrine with an unbound placeholder
— a 500, surfaced as "DataTables warning: Ajax error" (03.09.2026). Duplicate values are
normal here; duplicate keys are impossible.

`Account.vote_block_count` is a repeat-offender tally — incremented on every *passed* campaign (even if the account was already blocked). Surfaced in the bot voting menu (under the candidate), the block/unblock messages, `/admin/block-votes`, the `/admin/users` table + edit modal, and the `/admin/schedule` table. New DataTable columns are appended **last** because `telegram_users.js`/`schedule.js` `columnDefs` target by index. Editing those JS files means a deploy must run `npx encore production`.

## Rental listings ("здається квартира")

`RentalListing` is a resident-facing noticeboard, not a tenancy record. An owner publishes one listing per Account through the `RentalPublish` conversation (rooms → price → description → confirm); every resident reads them under 🔑 Оренда. Apartment/address/area come from the Account, so the only inputs are the three above.

Deliberate rules, each of which someone will be tempted to "fix" later:

- **The list is an index of buttons, not a wall of text.** One button per listing (`кв. 85 · 1-кімн. · 20 000 грн/міс`, `📌` marks your own), tapped to open that listing's card (`rent:view:<id>`) where the description, contact, photo and owner controls live (`📷` in the index marks a listing that has a picture); 10 per page with `rent:page:<n>`. Rendering every description in the index meant scrolling a screen of text to reach the buttons under it. This also leaves room for photos later: a caption + inline keyboard is one editable message, so a card can become a photo card without breaking edit-in-place navigation, whereas a media group cannot carry a keyboard at all.
- **Reading the list needs no confirmed account; publishing does.** Anyone who opens the bot sees the listings, linked to an особовий рахунок or not — a listing is an advertisement, and hiding it from an unlinked newcomer only costs the owner the reader most likely to be flat-hunting. An unlinked reader gets **the list and nothing else**: no publish button, no explanation of the restriction, and *not* the accountant's phone — deliberately, don't "helpfully" add a note there (the usual mark-and-explain rule doesn't apply: they are not being denied anything they could otherwise do, and Alina's number isn't for unlinked strangers). Publishing needs the Account because apartment/address/area are read from it; `RentalPublish::askRooms` refuses and explains for anyone who reaches it. When an unlinked person uses the relay, the owner sees `(не підтверджений ОСББ)` instead of an apartment number and judges for themselves.
- **`is_active` is NOT checked.** A debt or a missed pavilion photo blocks *booking*; it must not block an owner from advertising their own property. `RentalListingService::canPublish()` only excludes storage and parking units (their listing line is written for flats). Regression test: `tests/Service/RentalListingRulesTest`.
- **Apartment photos are optional and never arrive through Telegram.** The owner's card has `📷 Керувати фото (n/3)` → `rent:photos:<id>`, which mints a one-shot token (`RentalListing.photo_token`, 24h) and hands over a link to `/rent/photo/{token}` — a standalone mobile page (no Encore, no login; the token *is* the authorisation) that downscales in the browser to 1600px before uploading, because prod `upload_max_filesize` is 2M and a phone photo is 3–6 MB. `RentalPhotoService` re-encodes through GD as a backstop, which also strips EXIF/GPS. Paths live in `RentalListing.photos` (JSON, max 3), files under `public/uploads/rental-photos/YYYY/MM/`, purged when the listing is withdrawn/replaced/expired but **kept on an admin take-down** (the photo is usually why it was taken down).

  The card is a **carousel**: one picture at a time (a caption carries one, and a media group carries no inline keyboard) with `⬅️ 🖼 2/3 ➡️` under it (`rent:pic:<id>:<n>`, index wraps around; the counter is a dead button, `rent:noop`). The arrows call `editMessageMedia`, the one edit a photo message accepts, so the whole listing stays **one message** — the first shape of this shipped a separate message per photo (25.08.2026) and buried the card under pictures. `addCardControls()` builds the rest of the keyboard for both the initial render and every swap, so leafing through cannot produce a card with a different keyboard than the one you opened. The owner's `📷 Керувати фото (n/3)` opens the upload page (add/delete), not a viewer. Leaving a photo card (`⬅️ До списку`) deletes it rather than leaving the picture hanging above the list — `editMessageText` cannot turn a photo back into text.

  **Why not just accept a photo in the bot:** `pavilion:photo:check` materialises a `PhotoUploadRequest` only every 20 minutes, so for up to 20 minutes after a booking ends there is no open request. Any in-bot rule of the shape "no open obligation ⇒ this must be a flat photo" would swallow the pavilion photo of the resident who sent it *immediately* — the most conscientious one — and the cron would then block them for evidence already sent. Keeping this channel on the web means a picture sent to the bot is always pavilion evidence, with no rule to get wrong. `PhotoUploadFlow` is untouched by this feature; `RentalPublish` still carries the mandatory `interceptConversationPhoto()` guard (covered by the shared provider in `BookingConversationPhotoGuardTest`), and has no photo step of its own.
- **Phones are opt-in, never automatic.** The number is in the DB because the resident gave it to the ОСББ for нарахування, not for publication, so `RentalPublish` asks once (`askContact` step, number shown in full) and stores the consent as `RentalListing.show_phone` plus a display-formatted `contact_phone` **snapshot** — consent was for *that* number, so a later registry change doesn't silently republish a different one. Default is false, which is what the pre-2026-08-26 listings keep. Contact is otherwise a `t.me/<username>` button. Only ~48% of `telegram_user` rows have a username, so the relay path (`rent:contact:<id>`) is the common one, and when the interested resident has no username either it asks *them* for consent to pass their number (`rent:phone:<id>`) instead of the old dead end that told them to go reconfigure Telegram. `RentalListingService::formatPhone()` is the single normaliser — phones arrive as both `+380…` and `380…`.
- **Every label names the building, never the apartment alone.** `RentalListingService::place()`
  / `placePlain()` (short «б.» form for inline buttons, whose captions Telegram truncates)
  render «буд. 21, кв. 45» for the card, the index button, the contact button, the relay line
  that tells an owner who is asking, and the expiry notice. The five buildings repeat their
  apartment numbers, so «кв. 85» names two flats: a reader cannot tell which one to walk to
  and an owner cannot tell who wrote. Same rule as `DebtBoardService::place()`, which this
  feature was missing until 03.09.2026. Regression test:
  `RentalListingRulesTest::testEveryRentalLabelNamesTheBuilding`.
- **Rent and sale are one entity, told apart by `deal`.** Everything around the advert is
  identical — 30 days, the renewal prompt, three photos, the phone consent, the admin
  take-down — and what differs is one word and whether the price is per month, so
  `priceLabel()` drops «/міс» on a sale and `dealIcon()` puts 🔑 or 🏡 first in the index
  button, before the price, because «що це» has to read before the number. Two buttons
  under the list («🔑 Здаю квартиру», «🏡 Продаю») rather than a first question inside the
  conversation: the owner already knows which they came to do. The index grows a
  «Усі / 🔑 Оренда / 🏡 Продаж» row **only when both kinds exist** — one kind needs no
  filter above it and an empty tab is a dead end — and the tab rides in the paging
  callback (`rent:deal:<kind>:<page>`) so leafing through «Продаж» cannot drop back
  into the mixed list. `RentalCallbackWiringTest` checks every `rent:` button against
  the regexes in `config/telegram.php`: an unrouted one does not error, it just spins.
  The sale glyph is 🏡 and not 🏷 — the latter rendered as an empty box in Telegram
  Desktop.
- **Every listing is also posted to the residents' chat**, into the `🔑 Оренда` topic
  (`RESIDENT_CHAT_TOPIC_RENTALS`), silently, and the post is **deleted when the listing
  closes** — withdrawn, replaced, taken down or expired (`RentalListing.chat_message_id`).
  A classifieds thread holding flats that are long gone is worse than none: the reader
  cannot tell which of two dozen posts is still true. The post carries the building, the
  deal and the price and **never a phone** — consent was for the card, not for a message
  the whole house reads — and sends the reader to the bot for the contact. Neither the
  post nor its deletion is ever fatal: an unreachable chat must not stop somebody
  publishing. `RentalListingRulesTest` pins both rules.
- Listings expire after `RentalListing::LIFETIME_DAYS` (30). `rental:expire` (daily) sends a one-shot "ще актуально?" prompt `RENEW_PROMPT_BEFORE_DAYS` (3) before that and closes the rest. Queries filter on `expires_at` too, so a stale listing disappears even if the cron hasn't run.
- Publishing again **replaces** the account's active listing rather than being rejected — that is the edit path.

Admin: `/admin/rentals` lists everything with a take-down button (status `blocked`, stamped with the admin login). Debt is shown for context only.

## Послуги («🛠 Послуги»)

The house's own list of who does what: плиточник, електрик, манікюр вдома, репетитор.
`ServiceOffer` is the entity, `ServiceOfferService` the whole of the judgement, and the
shape is the rental noticeboard's — one button per offer, a one-message card with a photo
carousel, three photos uploaded from a tokenised web page, 30 days with a renewal prompt,
opt-in phone, admin take-down. What differs from it differs on purpose:

- **A card is two facts: the trade and a number.** Nothing else — no price, no description.
  Both were asked for on the day the board shipped and came back out the same day
  (08.09.2026). A price written a month earlier into a classified is a guess or a promise
  nobody meant to make; what a job costs is settled between the two people once one of them
  has said what needs doing, and «від 500 грн» on a card does not save that conversation, it
  only gives the reader a figure to be disappointed by. The free-text «розкажіть про себе»
  went for the plainer reason: the board is an index of who does what, not a CV, and every
  extra step is a reason to close the bot and write in the chat instead. Photos say more
  about a плиточник than the paragraph did, and they are added *after* publishing, so
  giving up at that point still leaves the trade on the board.
- **There are no categories, and the first field is free text.** «Сантехніка / Електрика /
  Ремонт» was the obvious first shape and it breaks on the first плиточник: he is neither,
  so the list either grows a pigeonhole per trade until nobody reads it or he picks the
  closest wrong one and becomes invisible to whoever searched for tiling. `title` (60
  chars) is what the person writes and what the index button shows. 141 flats will not
  produce enough offers for a filter to earn its place; when they do, what to divide them
  by will be visible in the data — the same rule by which the «Продаж» tab appeared on the
  rental board only after flats were actually being sold. Do not "tidy this up" into an
  enum. The section name is «🛠 Послуги» for the same reason «Будівельні послуги» was
  rejected: that word is a category smuggled into the heading, and it tells the манікюрниця
  and the репетитор they are in the wrong place.
- **The number need not be the poster's own.** «Я хочу розмістити телефон свого друга
  електрика» — so `contact_phone` is a plain typed field, normalised through
  `RentalListingService::formatPhone()`, and the resident's own number is offered as a
  one-tap button rather than filled in for them (it is in the database because they gave it
  to the ОСББ for нарахування). Somebody else's is typed, or attached from the phone book
  with 📎 → «Контакт», which is where it actually lives and the one path that cannot mistype
  a digit — the step reads `$bot->message()?->contact` as well as the text.
  **Telegram cannot be asked to open that picker from a button**: `request_contact` returns
  the user's own number and nothing else, which is what «Мій номер» already is, and
  `request_users` returns Telegram ids, not phone numbers. So there is no button to add here
  — don't go looking for one. The prompt says out loud that somebody else's number goes
  on a board the house reads, so ask them first — the responsibility sits with the person
  publishing, which is the only place it can honestly sit. There is no `show_phone` flag any
  more: a number, or none.
- **The flat on the card is labelled «👤 Розмістив», never left bare.** This follows
  directly from the rule above: a bare «буд. 19, кв. 85» under «Електрик» says the
  electrician lives there, which is false the moment somebody posts a friend's number.
  Labelled, the same line says who vouches for the card — which is the entire difference
  between this board and a number off a lamppost, and it is why the flat is on it at all.
  `ServiceOfferRulesTest` pins it for both the card and the chat post.
- **Up to `ServiceOffer::MAX_PER_AUTHOR` (3) live offers per *person*, not per account.**
  A person really can be an electrician *and* fit kitchens, and «Електрик, ремонт під ключ»
  crammed into one 60-character button serves neither trade; three is where it stops being
  «I do a few things» and starts being one resident holding the first page, and this board
  is the only place in the bot where somebody broadcasts to the whole house. Per person
  because father recommends his electrician and daughter posts her manicurist on the same
  особовий рахунок. The cap is checked on the button *and* in `mayPublishMore()`, since a
  resident can reach «➕ Пропоную послугу» from a keyboard drawn before they published their
  third somewhere else; at the ceiling the button is replaced by a sentence saying why,
  never silently removed.
- **Publishing adds; editing is its own path.** While one offer per person was the rule,
  «✏️ Змінити» restarted the publish flow and let it *replace* whatever was there, which is
  a reasonable spelling of "edit" for exactly as long as there is only one. With three, that
  edits the wrong advert — so the button carries the id (`svc:edit:<id>`) and
  `ServiceOfferService::update()` changes the row in place. In place matters: the photos,
  the chat post (edited where it stands), the expiry date and the clicks recorded against
  that id all hang off the row, and a typo fix must not restart the 30-day clock or orphan
  three pictures. `ServicePublish::targetOffer()` re-loads and re-checks ownership rather
  than trusting the id it was started with — a conversation outlives the keyboard it began
  on.
- **«📌 Мої оголошення»** opens straight onto the card when there is one, and onto a
  filtered list (`svc:my:<page>`) when there are several. Shown only to somebody who has
  something in it: an empty filter is a dead end, the same call the complaints register
  makes.
- **Reading needs a confirmed account** — the opposite call to the rental board. An
  advertisement for a flat wants every reader it can get; this list names which flat each
  tradesperson lives in and an unverified stranger gains nothing the house wants them to
  have. Same call as the debtors' board and the complaints register. Unlinked visitors are
  told what the section is and pointed at `/phone`, not silently shown an empty screen.
- **`is_active` is not checked and neither is the unit type.** A debt blocks *booking*;
  blocking a debtor from advertising their labour takes away the thing that lets them pay.
  And unlike a rental listing — whose card is written about a flat — somebody whose only
  object is a паркомісце can still lay tiles. `ServiceOfferRulesTest` pins both.
- **The chat post carries a deep link into the card.** `t.me/<bot>?start=s-<id>` as a
  **url** button — the only kind that works in a group, since the global middleware drops
  every update from there and a callback button would spin forever. It matters more here
  than anywhere: the post deliberately carries no phone and no photos, so «деталі — у боті»
  was the whole payload and it asked the reader to go find one row in a list. `start
  {payload}` is now routed by `StartPayloadCommand` on the payload's prefix (`g-` guard QR,
  `s-` service advert), because the QR was wired straight to its handler and the day a
  second link appeared everything that was not a QR would have been answered «цей QR-код
  зчитує охорона». An unknown payload opens the main menu. `openFromDeepLink()` re-checks
  the account: a link is forwardable and renderCard() would otherwise walk straight past
  the residents-only rule. `StartPayloadRoutingTest` pins each prefix — `GuardWiringTest`
  only asserts the config *string*, which stayed true through the rewiring and would stay
  true through a broken router.
- **A republish edits the chat post in place; it never deletes and re-posts.** Telegram
  refuses to delete a message older than 48 hours and an offer lives 30 days, so the
  delete-then-post shape left the old advert standing and added a second one beside it —
  two posts for one service, which is the classifieds rot the deleted-on-close rule exists
  to prevent. Editing has no age limit and keeps the post where it was rather than bumping
  it to the bottom of the topic on every typo fix. When a **close** cannot delete for the
  same reason, `unannounce()` strikes the post through («⛔ <s>…</s> Оголошення знято»)
  instead of leaving a live-looking advert.
- **«🔗 Поділитися» hands over a block that survives leaving Telegram.** The inline button
  under a chat post is Telegram's alone: forward that message into Viber and the button is
  simply not there, leaving a summary that ends «кнопка нижче» under nothing. The ЖК's Viber
  group still holds ~653 people — it is the group this bot exists to replace and has not
  replaced yet — so pasting an advert there is the normal case, and it is where «хто дасть
  номер майстра з дверей?» is still being asked. `shareText()` is plain text with the url
  spelled out, no markup at all, because somebody selects it with a thumb and drops it in
  another app. Offered to **everyone**, not only the author: a neighbour recommending the
  electrician they used is the point of the board. A recipient who is not yet linked lands
  on the `/phone` prompt, which is a funnel rather than a dead end.
- **Adding photos changes nothing in the chat.** The post is text and never carried them;
  the author gets a DM with the updated card instead.
- **The chat post carries the number** — the one place this board parts company with the
  rental one, which prints only a number the owner opted into. Both follow from the same
  question, «who reads this post compared with who reads the card»: the services board and
  the residents' chat are gated by the same list, so an extra tap protects nobody; the
  rental board is open to *anybody* who opens the bot, so its post is the **smaller**
  audience and a number already on the card has been seen more widely. What does not move
  on either board is the consent gate itself — an owner who kept their number private keeps
  it private everywhere.
- **The chat post is skipped entirely when `RESIDENT_CHAT_TOPIC_SERVICES` is unset**, where
  a rental listing falls back to General. Rentals were always written in the chat and the
  bot merely took that over; this board exists to *reduce* «хто робив вам ремонт?» traffic,
  so posting every advert into General unasked would add exactly the noise it removes.
  Create the topic with `resident-chat:topics`.
- **Editing is republishing, and it must keep the photos.** «✏️ Змінити» on the card
  restarts the publish conversation, and `publish()` closes the author's previous offer —
  so the first version purged its photos by symmetry with a withdrawal, which meant fixing
  a typo in «Електрик» silently deleted three pictures of the author's work, files and all.
  The photos are now carried across and the replaced row is detached from them without
  deleting (so the withdrawal/expiry purges cannot reach them either). A withdrawal is the
  author saying they are done; a republish is them saying it differently. Verified against
  a real file on disk.
- **The owner's own card is one tap from everywhere.** «📌 Моє оголошення (змінити / зняти)»
  sits under the list and on the publish confirmation. Before that, the only route was
  «До списку» → find your own row, the one marked 📌 → tap it, which is three taps and a
  guess that tapping your own advert is where «змінити» and «зняти» hide. The legend now
  says so in words too.
- Photos matter more here than on any other board, because they are the only thing on a
  card besides the trade and the number. Offered right after publishing, while the author is
  still holding the phone. They go through the web (`/service/photo/{token}`), never the bot — same invariant as
  the rental and complaint boards, and for the same reason: a picture sent to the bot is
  always pavilion evidence. `ServicePublish` carries the mandatory
  `interceptConversationPhoto()` guard and has no photo step; it is in the shared provider
  in `BookingConversationPhotoGuardTest`. Files live under
  `public/uploads/service-photos/YYYY/MM/`, through `ImageStore` (the GD re-encode is what
  strips EXIF/GPS).
- `service:expire` (daily) sends the renewal prompt and closes the rest — **a new cron
  line, install it on deploy.** Without it a trade board rots into the pinboard by the lift.
- `ServiceCallbackWiringTest` checks every `svc:` literal against the regex in
  `config/telegram.php`; `ServiceMenuButtonTest` pins that the menu button is actually
  drawn, under 🔑 Оренда and only for a linked resident — the guard buttons shipped invisible
  on 07.09.2026 and nothing anywhere said so.

Admin: `/admin/services`, take-down only. Deliberately **no debt column**, unlike
`/admin/rentals`: a debt is context for a flat being rented out because the ОСББ takes calls
about it, but what a resident charges for laying tiles is between them and their neighbour,
and putting their arrears on the same screen invites a decision nobody asked the panel to make.

**The panel has two roles, not one.** `ROLE_ADMIN` (main_admin, alina, luda_boss) is
everything. `ROLE_COMPLAINTS` (serhii, added 07.09.2026 — he answers for repairs) gets the
complaints register in full, plus **read-only** people and objects: he needs to see which
flat reported what, and nothing else. Everything that changes a resident — linking them to
a flat, moving them, the role, the conditional phones, blocking, chat moderation, the owner
group, the debt upload, the tariff, the area registry — stays with the accountant, because
those are the actions that decide who gets into the house chat and whose booking is
blocked. The boundary is enforced twice: in `access_control` (GET-only on
`^/admin/(users|objects)`, plus the DataTables POST or his list comes up empty and looks
broken rather than restricted) and in the templates, which hide every control he cannot
submit — a form that renders and then 403s is reported as «панель не працює».
`ComplaintsRoleTest` pins the routes; `AdminResidentPageTest` pins that the card renders
without a single one of its forms.

## A number the bot uses against somebody shows where it came from

**Rule.** «Поріг блокування: 1 024.65 грн» is unarguable and unverifiable at the same time:
it reads as a figure chosen for this flat, and the first thing a person does with a number
they cannot check is ring the accountant to dispute it — so the message meant to end a
conversation starts one. The sum is arithmetic on two things the resident already knows,
their own area and the published tariff, and printing it turns an accusation into a receipt:
«Це 50.6 м² × 13.50 грн/м² × 1.5 — півтори місячні нарахування ОСББ.»

It stays **silent** when the formula is not what produced the number —
`DebtPolicy::getThresholdFor()` falls back to the flat `DEBT_BLOCK_THRESHOLD` when the area
or the tariff is missing, and explaining a sum with numbers that did not make it is worse
than not explaining it. The factor is read from `DebtPolicy::OVER_FACTOR`, never retyped
into the copy.

**And it names every object of the household that owes, not only the one the reader is
linked to.** A debt on any object blocks booking for the whole owner group while
`TelegramUser` points at exactly one Account — so somebody whose flat was clean and whose
комірчина was over its threshold read «заборгованість понад допустимий поріг» beside a flat
with no debt on it. `DebtPolicy::getBlockingSiblings()` was written for exactly this and was
never called. **The debts are not summed and must not be:** each threshold comes from that
object's own area (50.6 м² → 1 024 грн, a 4 м² комірчина → 81), so adding two debts to compare
against one threshold compares a total against half a rule. Each object gets its own line,
its own threshold and its own arithmetic. `BlockNamesEveryDebtorTest` pins it.

`NumbersExplainThemselvesTest` pins this, and it is not decoration: both numbers it covers
decide whether somebody may use the альтанка.

**The vote threshold is the same rule, and it hid a dead feature.** `PASS_FRACTION` is
**0.30** — `yesNeeded() = ⌊eligible × 0.30⌋ + 1`, strictly more than 30%, **not** the strict
majority this file claimed until 08.09.2026 (it was changed on 01.07.2026 and the docs were
not). Voters were every object that may book the pavilion, counted straight off
`findAll()` — which was fine at 175 objects and became nonsense when
`objects:import-registry` brought in the whole register: 774 eligible against roughly 180
with a linked resident, so a campaign needed 233 votes out of ~180 possible and could not
pass, ever. Nothing said so, because the button still worked. `BlockVoteService::mayVote()`
now also requires somebody in the bot behind the object, and it is the one definition used
by both the roll call and the ballot check — a voter counted but refused, or refused but
counted, is a campaign that adds up wrong.

## Every published thing shows when it was published

**Rule: a list of things residents publish is a list of buttons, and every button leads
with the date.** `08.09 · Двері, монтаж 📷 📌` on the services board, `🆕 08.09 · Ліфт не
працює 📷 📌` in the complaints register.

These lists are read for freshness as much as for content: «Електрик 08.09» and «Електрик
12.08» are different offers to somebody deciding who to ring, and «ліфт не працює» from
yesterday is a different thing from the same words from March. Without a date the reader
has to open a card to find out how old it is, which is the one thing the index exists to
save them.

**Date first, at a fixed width**, so the dates line up down the column and the list is
scanned in one movement — a trailing date cannot do that, because the titles are ragged.
`d.m` only: nothing on these boards outlives its year, and the four extra characters come
straight out of the title. **Badges last** (📷, 📌): they qualify a row rather than identify
it, and Telegram truncates captions from the right, so the two things that must survive —
when and what — sit where they cannot be cut.

The rental board is the deliberate exception: its buttons carry the flat, the rooms and the
price, and a flat is chosen on those rather than on freshness.

`ListButtonShapeTest` pins the shape on both boards that follow it.

## Every post in the residents' chat links back into the bot

**Rule, not a preference: a post the bot puts in the group must carry «↗️ Відкрити в боті»,
pointing at the thing the post is about.** The post is always a summary — short on purpose,
never the photos, on most boards never a phone — and everything it leaves out lives on a
card the reader then has to hunt for: leave the chat, open the bot, find the section, find
the one row among a list. Every board shipped that dead end and every board had it removed
afterwards; writing the rule down is cheaper than removing it a fifth time.

`ResidentChatPostsLinkBackTest` enforces it at the source: any `sendMessage()` carrying a
`message_thread_id` (which in this codebase only a group post does) must also pass
`$this->links->button(...)`. A post that genuinely has nowhere to point goes in that test's
`NO_TARGET` with its reason.

**The other half of the rule: every board can hand over a paste-ready block.** An inline
button is Telegram's alone — forwarded into Viber it is simply not there, leaving a summary
that ends «кнопка нижче» under nothing — and the ЖК's Viber group still holds 653 people,
which is where «хто дасть номер майстра з дверей?» is still asked. So each board has
`shareText()` and a «🔗 Поділитися» button: plain text, url spelled out, **no markup at
all**, sent with the link preview disabled so Telegram does not stack a bot card over the
text somebody is trying to select. Offered to everyone, not only the author. The complaint
block carries the status (that is the whole point of the register) and never the author's
contact. `ShareBlocksTest` pins all three.

**Ids are never reused** (`SERIAL`), so a shared link is either the advert it was made for
or plainly stale — never somebody else's. It survives an edit, because editing changes the
row in place; it dies when the advert is withdrawn or expires, and then opens the list with
«вже неактуальне» rather than an error.

`DeepLink` is the one place a link is built *or* read — prefix map, button, and the click
record together, so a new board cannot add a button the router does not understand. A **url**
button is the only kind that works in a group: the global middleware drops every update
arriving from one, so a callback button there spins forever, while a `t.me/…?start=…` link
sends no update at all. Kinds today: `s-` service, `r-` rental, `c-` complaint, `d-` the
debtors' board, `g-` the guard's QR (which shares the router and nothing else — it is signed,
carries no id, and is deliberately not recorded as a click).

The debt link's id is the `DebtSnapshot`, not the destination: the board it opens is always
the current one, and carrying the snapshot means the click log answers «which month's post
did people actually open», which is the only interesting question about a post that repeats.

## Who followed a link out of the chat

`/admin/links` — every tap on «↗️ Відкрити в боті» under a post in the residents' chat:
which advert, who, when, plus a per-post summary of **natискань** and **людей** (a
neighbour who opens the same electrician three times is interested, not three people).

**It exists because Telegram answers nothing else.** The read list of a message is shown
only to whoever sent it, these posts are sent by the bot, and the Bot API has no
read-receipt method at all — so «did anybody look at this» has exactly one available
answer. It is also the better one: «seen» means somebody scrolled past, a tap means they
wanted the thing. Do not go looking for a read-receipt API; there is none, and the group
would have to be under 100 members for even a human sender to get one.

**Only links are recorded.** Opening the same card from inside the bot writes nothing, and
the guard's QR scan is excluded outright. The question is «did the chat post work», not
«what is this resident reading» — four people can read this table, and that boundary is
the whole reason it is defensible. Иван was told plainly what it records before it was
built and said «пусть будет»; do not widen it to card renders for the sake of a bigger
number.

`LinkClick.user` is nullable and SET NULL: a click is a fact about the post and must
survive the person being unlinked or removed. Read by both roles, GET only — same call as
the sign-in log. No retention job; a few hundred rows a year.

**A kind this page does not know must say so, never fall into the last branch.** Both
tables were an if/else ending in a bare `else` that meant "complaint", and the controller
resolved titles for three kinds out of five — so the Face ID vote, 56 clicks across two
posts and the busiest thing in the house that week, rendered as «🔧 заявка #4 (видалено)»
and every one of those links opened the **rental** register. Nothing failed and nothing was
logged; the page simply said something untrue and stayed that way until somebody followed a
link (08.09.2026). The icon, the word and the destination now come from three macros with
an explicit branch per kind and a neutral «↗️ посилання» fallback, and
`AdminLinksPageTest::testEveryRecordedKindIsNamedByThePage` walks `DeepLink::PREFIXES`.

**The row it sends you to is marked.** `#offer-4` / `#listing-9` / `#complaint-11` scrolled
the page and then said nothing about which of a dozen near-identical lines was meant — a
scroll position, not an answer. One `:target` rule in `base.html.twig` (a soft fill, a bar
on the first cell, a flash that fades) covers the table, the other table and the stack of
cards, so the next page to receive an anchor gets it for free. No `!important` on that
background: an important declaration beats an animation in the cascade and the flash would
never play, and it is not needed anyway — the stripe rule paints the `tr` while this paints
the `td`. `AdminLinksPageTest` pins that every anchor the log builds still exists on the
page it points at; renaming one is a one-word change over there with nothing else to catch
it.

**The per-board totals print the zeroes.** «За розділами» lists every kind — послуги,
оренда, заявки, голосування, борги — including the boards nobody has opened, because the
per-post table below only holds posts that were clicked, and a board with no row reads as
"no such thing" rather than as nought. Whether the chat post works *for that board* is the
one number this page exists to produce.

**`DeepLink` is the only place a link is built or read.** The prefix map lives next to both
halves so a new board cannot add a button the router does not understand — the failure that
would cause is a link opening the main menu, which reads as the bot forgetting what you
tapped. `StartPayloadRoutingTest` walks the map and fails on a kind the router never
mentions. All three boards now carry the button; the guard's QR shares the router and
nothing else.

## Residents' faces in the panel

`/admin/users` is a table of phone numbers and Telegram nicknames, and a face is the one
thing that turns «Ника, кв. 85» into somebody the accountant has actually met. Иван asked
for it on 08.09.2026; the resident card shows the photo, the list deliberately does not —
see below.

**Fewer than half of them have a photo we may have, and that is the ceiling.**
`getUserProfilePhotos` returns nothing when the person's «Фото профілю» is «Мої контакти»:
a bot is not a contact. Measured on prod before building it — **36 of 80** linked
residents, and Иван's own account is in the other 55%, photo and all. No API gets past
that, so do not go looking for one. It is why the initials circle is not a fallback for a
rare case but the normal rendering: it is coloured from the id (the same person is the same
colour every time) and it has to look deliberate rather than like an image that failed.

- **The cache lives in `var/avatars/`, never under `public/`.** A face on a guessable URL is
  published; served through `/admin/avatar/{id}` it sits behind the same login as the phone
  number and the debt on the same card. Both roles may read it — Сергій already opens the
  resident register — and the gate is in `access_control` with every other one.
- **`photo_path` is a bare file name.** It is written only by the sync, but it is read to
  build a filesystem path, and a column that has ever held «../» can serve any file on the
  box. `AvatarService::fileFor()` refuses anything else; `AvatarRulesTest` pins it.
- **A photo that disappears from Telegram is deleted here.** Somebody who closes their
  profile has taken it back, and a copy that outlives the withdrawal is the panel keeping
  something the person withdrew. `sync()` returns `removed` for exactly that case.
- **Tapping a face opens it full size.** Two files are cached, not one: the middle size
  draws the 28px circle and the 72px card, the largest opens in the lightbox. Serving the
  large one everywhere would put ~60 KB per row on a page of 25 circles, on the
  accountant's phone, to make a rare click instant. `/admin/avatar/{id}/full` **falls back
  to the small file** rather than 404 — the big copy only exists for people synced since it
  was added. The trigger is a real `<a href>`, which matters twice: without JS it still
  opens the picture, and the users table's row handler opens the resident's card on a click
  anywhere *except* inside `a, button, input, …`, so the link is what stops one tap doing
  both. The overlay itself is plain JS in `base.html.twig`, delegated from the document so
  it covers rows DataTables draws later.

- **Weekly, by cron, never on page render.** Three Telegram calls per person against 449 of
  them is minutes; a table that renders in eight seconds because it is downloading pictures
  is worse than one with none. `telegram:avatars:sync` skips anybody asked about inside
  `AvatarService::STALE_AFTER_DAYS` (7); `--all` re-asks about everybody, `--dry-run` says
  who would be asked.
- **In the list too, and as a row key rather than a column.** It was scoped to the card
  first and Иван asked for the table the same evening. The picture rides on the JSON row
  (`avatar`, `initials`) and is drawn inside the name cell by the renderer at index 11 —
  DataTables hands the whole row to a renderer, so it needs no `<th>`, no hidden
  `columnDef` and, above all, no shifting of the indexes every other def in
  `telegram_users.js` is written against. `block_reason_label` travels the same way for the
  same reason. 28px in the table, 72px on the card. Editing that JS means
  **`npx encore production` on the deploy**.

## Who signed in to the panel

`/admin/logins` — one row per sign-in attempt: the login as typed, when, the IP and a
one-word device, successful or not. Written by `AdminLoginSubscriber` from Symfony's own
`LoginSuccessEvent` / `LoginFailureEvent` (not from the login controller, which is not
where authentication happens) and **never fatal**: a failed write is logged and the
person is still let in, because the panel is how the accountant works.

**Failed attempts are the more interesting half** — a success answers «це я заходила
вчора?», a run of failures against `alina` is the only thing on the page that is news.
The password is never read; only the name that was typed.

**Read by everyone who can sign in, including `ROLE_COMPLAINTS`.** Иван asked for it as
«чисто для меня» and then widened it himself twice («хотя можно и не только для меня» →
«пусть будет для всех кто зашел в админку»): four colleagues seeing their own last visit
and each other's is what makes an entry nobody recognises get mentioned out loud the same
day, whereas a log only the owner opens is an audit trail nobody reads. GET only —
nothing on the page is anybody's to change.

No retention job: four people signing in a few times a day is a few hundred rows a year,
and the page draws the last `AdminController::LOGINS_SHOWN` (200), about a fortnight.

**The panel signs everybody out at midnight Kyiv** (`DailySignOutSubscriber`, 08.09.2026),
and that exists *for* this page. Nothing expired before it: PHP's session GC never runs
against a custom `save_path` on this distribution, so a session file sat on disk
indefinitely — somebody who signed in once in July was still signed in in September, and
four colleagues produced a handful of rows a year. The page answers «хто заходив» and can
only answer with sign-ins that happened. Иван's call: «не важно кто, в 24:00 всех
разлогин».

**A calendar day, not a rolling 24 hours.** A rolling window drifts — sign in at 10:32 and
you are asked again at 10:32, then 11:05, then noon — and the log stops reading as a
day-by-day list. The day is stamped into the session at login (`panel.signed_in_on`) and
checked on every `/admin` request; `/login` is deliberately outside the check, or an
expired session bounces between the two forever. A session carrying **no** stamp is given
today's rather than thrown out, so shipping the rule does not sign everybody out
mid-afternoon. The form says why it is asking again (`?expired=1`) — being asked for a
password with no explanation reads as the panel having broken overnight.

**`loginUser(new InMemoryUser(...))` does not sign anybody in here.** The session user
would carry a null password, the in-memory provider refreshes it into the configured hash,
Symfony reads the difference as "the user changed" and drops the token — every request
then answers 302 to `/login`. `ComplaintsRoleTest` was written that way and accepted 302
as a refusal, so the whole class passed while proving nothing about the role split it
exists to pin (found 07.09.2026). Load the user from
`security.user.provider.concrete.users_in_memory` instead, and assert **403 exactly** on a
refusal.

## Two registers: people and objects

`/admin/users` is the register of **people**, `/admin/objects` the register of **objects**,
and they answer different questions on purpose:

- **A person may own several objects.** A flat, a parking space and a storage room are three
  `Account` rows with three особові рахунки, because that is how the ОСББ bills them.
  `TelegramUser.account_id` links a person to exactly one — «у людини може бути 5 об'єктів,
  а я ж тільки до 1 підв'язую» (Аліна, 03.09.2026). `Account.owner_group_id` is the admin's
  statement that some of those rows are one household.
- **An object may have several owners, of different kinds** (`TelegramUser.role`: owner /
  family / tenant). That side always worked, but was only visible from one person's card at
  a time.

Before `/admin/objects` existed there was no way to look at a property at all: you found one
by finding somebody linked to it, so **an object with no linked resident was invisible** —
and those are exactly the ones whose debt reaches nobody, since every notice the bot sends
goes to a `TelegramUser`. The page marks them (`❓ Без власника`) and counts them.

**What an owner group actually changes** (this is not cosmetic, and it already worked before
the page existed — it was simply never used: one group of two on prod as of 04.09.2026, the
first one created the day `/admin/objects` shipped):

- booking limits are counted across the group — `ScheduledSetRepository`, five queries
  through `COALESCE(owner_group_id, a.id)` — so a flat + parking owner cannot book 3 hours
  twice in one day;
- a debt block on **any** object blocks booking for the whole group
  (`DebtPolicy::isOwnerGroupBlocked`), and `getBlockingSiblings()` names the object that
  owes. **Debts are deliberately not summed**: each object has its own threshold
  (area × tariff × 1.5), and adding two debts to compare against one threshold compares a
  total against half a rule.
- **the bot's menu header names every object of the group**, each with its own особовий
  рахунок **and what that object owes** (`StartCommand::renderHeader()` over
  `PropertyRegistry::objectsOfAccount()`). «без боргу» is spelled out rather than left
  blank — on a list of two lines a missing annotation reads as missing data, not as zero —
  and no figure is printed at all unless `DebtBoardService::isAvailable()` says so, since
  the «станом на» date lives one block below in the same message and a sum without one is
  what the board's staleness rule exists to prevent.
- **the header also names everyone else on the рахунок** («👥 На цьому рахунку також:
  Конакбаєва Марина Василівна (член сім'ї)»), through `TelegramUser::getDisplayName()` — the
  registry name when the ОСББ knows it, the Telegram one otherwise. The bot is the only
  place a resident can check that a linking actually happened: on 08.09.2026 Віталій asked
  for his wife to be added, she was, and nothing on his screen changed — so the next thing
  he does is ask again. It also lets somebody notice a name that should not be on their
  flat, which is otherwise visible only to an admin. Silent for a household of one.
  `TelegramUser.account_id` points at one Account, so before 04.09.2026 the other objects
  of a household existed nowhere in the bot at all — and that number is exactly what the
  accountant asks for on the phone. A household of one keeps the old singular wording word
  for word: all but two of the 173 objects on prod are somebody's only one.
- the debtors' board's viewer line covers the group too — reading only the linked object
  answered «боргів не має» to an owner whose own комірчина was on the list two lines below.
  `isViewer()` matches on an **explicit** `owner_group_id`, never on a bare id, so an
  ungrouped account whose id happens to equal another household's group number is not
  marked as theirs. The **podium** carries the same «📌 (це ви)» mark as the full list: five
  near-identical lines and a "ви п'яті" underneath meant checking the building number by
  hand to find which line was yours.

**Unlinking the member the group is named after renumbers the rest.** The group id is the
smallest member's own id, so removing that member while two others stay leaves it — now
ungrouped — still resolving to the same `COALESCE(owner_group_id, id)`: its bookings would
keep counting against the household it just left. `OwnerGroupService::unlink()` re-keys the
remainder onto their own smallest member; `OwnerGroupRulesTest` pins it.

**Nor a pavilion name.** `SchedulePavilionService::pavilionName()` is the one definition
of «Перша» / «Друга»; the ternary behind it had been written out by hand in six files.

**Nothing in the bot builds a place label of its own.** `Account::getUnitLabel()` is the one
definition of «кв. 85» / «комірчина 168» / «паркомісце 138», `getPlaceLabel()` prefixes
«буд. N» and `getStreetPlaceLabel()` the street. The menu header had its own hardcoded
`кв. %s` until 04.09.2026 and so called a комірчина a flat — the same mistake
`DebtBoardService::place()` was corrected for a day earlier.

**A person can be taken off an object without being put on another one.**
`/admin/users/{id}/account/unlink` (ROLE_ADMIN, a button on the resident's card) clears
`TelegramUser.account_id`. Until 08.09.2026 there was no such control: the only way to undo
a link was the move form, which refuses an empty особовий рахунок — so somebody attached to
the wrong flat, or one who had sold theirs, could not be detached at all. It is **not** a
delete: the row stays, and so do their bookings, complaints and listings, because the person
really did press /start and really did report that lift; they simply become
«⏳ Не прив'язаний», which is what they now are. The card spells out the consequences rather
than leaving them to be discovered — no booking, no debts, no заявки, no послуги, and the
chat gate refuses a *new* request — and it says plainly that **removing them from the chat
they are already in is a separate button**, since the gate closes the entrance, not the exit.
It warns when the person still has hours booked ahead: `ScheduledSet` points at the
`TelegramUser` and the guard's board resolves the flat through their account, so unlinking
leaves «❓ без особового рахунку» against an hour that really is taken. Warned about, not
forbidden — sometimes that is the intent. `AdminResidentPageTest` pins the button, the
warning and its absence for somebody with no flat; `ComplaintsRoleTest` pins that Сергій
gets 403.

**Objects are created on `/admin/objects`, not by moving a person.** A resident's card can
only ever attach somebody to an object that already exists — «Прив'язати» and «Перенести»
answer «Рахунку N немає в базі» rather than creating one, because a кладова entered on a
person's card would take its owner off their flat, and because an object that exists on
paper and has nobody in the bot (a storage room, a parking space, a flat whose owner never
opened it) still has to be creatable: those are exactly the rows whose debt reaches nobody.
(This file said the opposite until 08.09.2026 — that a typed unknown number creates the row
and drags the resident onto it. It has not done that for some time.) The objects form
refuses a duplicate особовий рахунок outright — that is what the debt import matches on, and
a second row silently sends somebody's arrears to the wrong place.

**And the object is picked from a search, never typed.** All three forms that attach a
person to an object — «🔗 Прив'язати», «📦 Перенести» and the owner group — were a bare text
box asking for an особовий рахунок. Nobody knows 966 of them, so the actual workflow was to
open the register in a second tab, search, copy the number and come back; and a mistyped
number that happens to exist is accepted in silence, attaching somebody to another
household's flat. Every other mistake on that card is visible on it — this one is only
visible from the *other* flat, whose owner is not looking. They are select2 pickers over
`/admin/objects/search` now (`PropertyRegistry::narrow()`, so a word that finds an object in
the register finds it here: «85», «комірчина», «Козацька 19»), and each line carries the
address, the kind and how many people are on it, because «230085» is exactly the string
nobody can check. The picker posts the особовий рахунок, so every controller behind these
forms is untouched.

It **pages on scroll and never caps** — `OBJECTS_SEARCH_LIMIT` (30) a page, with
«знайдено об'єктів: N» drawn over the list. The first shape answered the first thirty and
said how many it had matched, and on an empty search that reads as «у нас тільки тридцять
квартир» — asked within the hour of it shipping — and now that the picker is the only way
an object is ever chosen, all 966 have to be reachable. **Occupied objects stay in the
list on purpose**: attaching a family member to the flat their mother is already on is the
commonest linking there is, and each line says which case it is — «👤 2» or «❓ без
власника». Filtering the taken ones out would break exactly that.
`AdminResidentPageTest` pins that none of the three fields is a bare `<input>` again.

`OwnerGroupService` is the only writer of `owner_group_id`; the users page reaches it over
JSON and the objects page over a plain form, and the merge rules (an existing group beats a
fresh id, the smaller id survives a merge, a group of one dissolves on unlink) live there
once — `OwnerGroupRulesTest` pins each.

**`/admin/objects` pages on the server, 60 cards at a time.** It was rendered whole with a
client-side filter box — right while the bot knew 172 objects, wrong the moment
`objects:import-registry` brought in the ОСББ's own register: 966 objects rendered to
**2.4 MB of HTML, 969 cards and a page 117 000 px tall**, opened from the accountant's
phone. The three questions (text, kind, building) are answered by
`PropertyRegistry::narrow()` and AND together, the counter is always spelled out
(«Показано 1–60 з 969»), and `?object=<о/р>` still lands in the search box rather than
hiding everything else — a filter that quietly drops rows is how «я не знайшла» turns into
«його немає в системі». The type is searchable by every word anyone uses for it: the
accountant's file says «паркінг», the bot says «паркомісце», the register says «Комора».
`PropertyRegistryNarrowTest` pins that.

**`objects:import-registry <file.xlsx>`** creates the house's objects from the ОСББ's
register of особові рахунки (`ID | Тип прим. | № прим. | Особовий рахунок | Загальна площа`).
It creates and back-fills area, and does nothing else: never a debt (those come from the
debt file), never `is_active`, never a link to a person, and **never a delete** — an о/р in
the bot that the register does not carry is reported and left alone. The building comes
from the first digit of the рахунок (1→17, 2→19, 3→21, 4→23, 5→27, 6→25), the type from
the third, and on the day it first ran the type it derived agreed with the register's own
wording in all 966 rows.

## The panel is read on a phone

Аліна searches a resident while standing in the під'їзд and Людмила moves a заявка from
her sofa, so every page here is a phone page that also happens to work on a desktop.
`base.html.twig` carries the viewport meta and the 16px minimum on inputs (iOS zooms the
whole page in on anything smaller); what kept breaking on top of that was the filter
furniture.

**A row of filter buttons is a `.chip-row`, never a `.btn-group`.** Bootstrap squares a
group's inner corners, which is right exactly while the group fits on one line: wrapped, it
is a grid of square blocks with borders in the wrong places, and both admin tables shipped
that way — six status filters and six buildings on `/admin/users`, four groups in one flex
row on `/admin/schedule` (08.09.2026). Separate chips with a gap wrap the way
`/admin/objects` — the page that never had the problem, because it was written as plain
chips — always did. `.chip-row` lives in `base.html.twig` with the DataTables mobile
rules, because **both tables render as `#telegramUserTable`** and two copies of that CSS is
one copy that gets fixed.

**DataTables centres its own controls under 767px, and again under 640px**, so «Пошук:» and
the length select sat on the centre line above a table that starts at the left edge. They
are re-aligned there too, with the search input `box-sizing: border-box` (its own 5px
padding pushed a `width: 100%` box off the screen).

**The table scrolls inside its own box; the page does not widen.** Everything above it is
laid out against the DataTables wrapper, and the wrapper is as wide as whatever the table
needs — so one long cell was sizing the search input and wrapping the filter chips off the
right edge, three screens above the data that caused it.

**`/admin/users` folds its seven per-field boxes behind «🔎 Пошук за полями» under 768px**
and opens them by itself above it. The summary says «· активний» while something inside is
filtering: a folded panel that quietly narrows the list is a short list with nothing on
screen explaining why. The Bootstrap 5 utility names (`me-2`, `ms-auto`) that had been
written into `/admin/schedule` against Bootstrap 4 did nothing at all and are gone.

## The gate («Охорона Ситипарк»)

The guard's job is one sentence: walk up to whoever is in the альтанка, ask who they are,
and check that against a list. The list lived on the accountant's screen and in the bot of
the person who booked, so the check was «зателефонуйте Аліні» or nothing. `/guard` →
`GuardCommand` renders what is running **now** and then the rest of today, with a 🔄 that
edits the message in place.

- **One board, and it names the flats to every confirmed resident** (09.09.2026). It
  shipped a day earlier with two versions — the guard's naming flats, everybody else's
  saying «зайнято» — on the reasoning that telling 457 people a named household is out
  between 18:00 and 21:00 was a different feature from the one asked for. Иван opened it,
  and by then two things had undone that reasoning: **scanning a resident's QR already
  names the flat** to whoever reads it, so the house was shown the same fact through one
  door and refused it through another; and a booking means the household is *twenty metres
  away in the yard*, not out for the evening. The debtors' board publishes flat and sum to
  the same readers every month. Their **own** booking is still marked «📌 це ви», matched
  across the whole `owner_group_id` household and on an explicit group id on both sides,
  never a bare one (the trap `DebtBoardService::isViewer()` is written around). The
  `namesFlats` switch is gone with the second version; the gate that remains is the one
  that matters — an unlinked visitor sees none of it.
- **Guards are Telegram ids in `.env.local`** (`GUARD_TELEGRAM_IDS`), same shape as
  `COMPLAINT_MANAGER_TELEGRAM_IDS`, and **an empty list means nobody, never everybody**.
  It is **empty on prod since 09.09.2026**: the ЖК has exactly one guard («Охорона
  Ситипарк», +380 68 369 47 58) and he has not opened the bot yet, so the two ids in there
  were Иван and Сергій — testers, and the scan log was labelling their checks «Охорона»,
  which is simply untrue. Nothing was lost by emptying it: since the board names flats for
  every resident, the flag now decides only two things — the «охорона» label in `QrScan`
  and whether somebody with **no особовий рахунок** may read the board at all. Put the
  guard's id in the day he presses /start. `GuardBoardRulesTest` pins the empty-means-nobody
  rule.
- **An unlinked visitor gets neither version.** Like the debtors' board and the complaints
  register, this says what is happening in the ЖК's own yard; somebody who opened the bot
  through 🔑 Оренда to browse flats is not part of the house. A guard is admitted whether
  or not he has an особовий рахунок — he is staff.
- **The flat, never a name or a phone.** «буд. 19, кв. 85» is the whole check: the person
  says which flat they are from and it matches or it does not. Same call the complaints
  register makes about the author's contact, and the registry holds no owner names anyway.
- **A session is running from 20 minutes before its start to 20 minutes after its end**
  (`EARLY_MINUTES` / `GRACE_MINUTES`). People arrive early, and the pavilion photo is due
  within the hour of the end — the guard moving them along while they photograph it is
  the one interaction this feature exists to prevent.
- **Consecutive hours merge only within one household on one pavilion.** Two flats booked
  back to back must stay two lines, or the guard reads one apartment number and waves
  through whoever is there at 21:30. The grouping is `GuardService::group()`, static and
  database-free so the rule is testable.
- **Anything the menu resolves at render time must be `public: true`.** `StartCommand` is
  static and reaches its collaborators through `$bot->getContainer()->get(...)`, which only
  exposes public services; a private one is inlined at compile time and the lookup throws
  into the `catch (\Throwable) { return false; }` that every one of those helpers carries.
  Right for a decoration, and exactly why it fails silently: on 07.09.2026 both guard
  buttons shipped and simply did not appear — no error, nothing in any log, indistinguishable
  from «ще не задеплоїли». `MenuContainerLookupsTest` walks the lookups in `StartCommand`
  against `config/services.yaml`.
- The guard is **staff, not a resident**: with no особовий рахунок he gets his own
  one-button menu and header, because the resident menu over an empty header is a screen
  of buttons he cannot use. A guard who *is* a resident (which is how it gets tested, and
  could be how a resident earns a shift) keeps the full resident menu with the guard
  button on top — collapsing it would take his own flat, bookings and debts away the
  moment his id joined the list.
- `/guard` is registered as a handler but deliberately **left out of
  `BotMenuUpdateCommand::MENU`**, which is pushed to all private chats. The resident's
  «🏛 Альтанки зараз» sits directly above «Бронювання» — look, then book — and is hidden
  from a guard, who already has his own copy at the top of the menu under a name that says
  what his version is for.

**The QR half** (Иван's idea, 07.09.2026): «🪪 Мій QR-код» renders a deep link,
`t.me/<bot>?start=g-<account>-<signature>`, so nobody needs a scanner app — a camera opens
Telegram and `GuardScanCommand` replies. ✅ with the flat, the pavilion and the hours for a
guard, or ❌ «броні на зараз немає», which is the answer that matters: a screenshot from
last Saturday has to read as plainly wrong rather than as a bot error.

**It is a resident's pass, not a booking ticket, and the whole house can read it**
(09.09.2026). Both halves were narrower on the day it shipped and both were opened by Иван:
«я хотел бы чтоб кто угодно из ЖК мог проверить кого угодно, а не только охрана», «считать
код и получить информацию может любой подтвержденный житель ЖК».

- **One rule for both ends: a confirmed resident.** `GuardService::mayHoldQr()` /
  `mayScan()`. The button used to appear only while a booking was running — right for a
  booking ticket, and the reason almost nobody knew it existed.
- **A block is irrelevant to both** — debt, a missed pavilion photo, an admin's hand, a
  vote of the house. Every one of them decides whether somebody may *book the альтанка*;
  none of them decides whether they live here, and that is all the code says. Withholding
  it would turn the pass into a public statement that its holder owes money, made to
  whichever neighbour scanned them. `GuardBoardRulesTest` pins it, because «зробити
  консистентно з бронюванням» is a tempting and wrong tidy-up.
- **One answer, the same for everybody, the flat included**: «✅ Код дійсний · це мешканець
  нашого ЖК · буд. 19, кв. 85» plus «🏛 Зараз бронь: Друга альтанка 18:00–21:00», or a
  plain «броні на зараз немає». A guard-only version that hid the flat from neighbours was
  tried for an evening and dropped as one rule too many: the code is shown deliberately, by
  its owner, to somebody standing in front of them, and «це мешканець» without saying which
  flat answers nothing they could not already see. The board is the opposite case and
  stays that way — it lists every flat's hours to a reader nobody chose.
- An **unlinked** visitor is out of both halves: the bot has no flat for them, so there is
  nothing to mint and nothing to be told. `GuardScanAudienceTest` drives the handler
  through FakeNutgram and asserts on what was actually sent — its first version matched
  the source with a regex, and a deliberately broken version passed it. Prove such a test
  fails before believing it.
- **`start {payload}` cannot swallow a plain `/start`.** Nutgram anchors command patterns,
  so the deep-link handler needs the space and a payload while `/start` alone still reaches
  `StartCommand`. `GuardWiringTest` pins it: getting that wrong takes the main menu away
  from 457 people and shows up as «бот не відповідає», not as an error anywhere.
- Needs `endroid/qr-code`, so a deploy that brings this in must run `composer install`.

## Passes for the people a flat lets in («👷 Пропуски»)

The crew arrives at eight and the guard has no way to tell an expected builder from
anybody else with a toolbox — the only check available was «нам сказали, що нас чекають у
85-й». Иван asked for this on 09.09.2026: a flat mints a pass, forwards it to the бригадир,
and the guard's camera answers.

**One code per crew, switched on for a window at a time.** The obvious shape — a fresh pass
every morning — puts the work in the wrong place. The resident's taps are cheap; *re-sending
a new picture to the бригадир every morning* is not, and on the third day they stop looking
at it and ask the guard to take their word again. So the picture is minted once and
forwarded once, `GuestPass.active_until` is the moment it was switched on until, and a scan
outside that window answers «пропуск зараз не активний». The lifetime is enforced by the
**answer**, not by the code's existence: a screenshot from yesterday has to read as *wrong*,
never as a bot error the guard resolves by letting them in.

**The window is a moment, not a day** (asked for the same night): a delivery is two hours
and a renovation is all day, and «до кінця дня» handed to a courier is a key they keep until
midnight. The card offers ⏱ 2 години / ⏱ 4 години / до кінця дня, and «⏹ Вимкнути зараз»
for the van that came and went at 11:20. `activate()` **clamps to 23:59 whatever is asked**
— four hours at 22:00 would otherwise run into tomorrow, which is a second day nobody chose,
and one day is the outer limit the feature was asked for with. Switching off is not
revoking: tomorrow the same picture works again.

- **Stored, unlike the resident's own QR**, which is a bare HMAC over an account id and
  needs no row. This one carries a day, a name, a revocation and a scan history — every one
  of them a fact somebody needs after the event. The token is signed all the same
  (`p-<id>-<sig>`): a bare id could be guessed, and the guard would then confirm somebody
  nobody invited.
- **Three live passes per flat** (`GuestPass::MAX_PER_ACCOUNT`), checked on the button *and*
  in `mayCreate()` — a keyboard drawn before the third one was made is still tappable.
- **Revoking is forever**: the picture in somebody's phone stops working. That is the answer
  to «бригада змінилась», and it is why the pass is a row rather than a signature.
- **The flat sees when its crew arrived.** The card lists the last checks («🕘 Перевіряли»),
  and the *first* valid scan of a day DMs the issuer — «тем самым будет знать когда пришли
  строители». Once a day: a guard who checks the same people twice at the door has not made
  them arrive twice, and a second message teaches the resident to mute the first. Who
  scanned is given as «охорона» or «мешканець» and never by name — naming the neighbour who
  checked turns a security tool into something people avoid using.
- **Anybody linked to the рахунок may issue one**, and the flat on the card is who vouches.
  Same reading as the services board's «👤 Розмістив».

## Who scanned whose code (`/admin/scans`)

Every scan of either QR kind is a `QrScan` row: who pointed the camera, whose code it was,
what the bot answered, and whether it was the guard. Asked for in the same breath as opening
the scan to the house, and it is what makes that defensible — the moment 457 people can
check each other, «хто когось перевіряв» stops being hypothetical. It is also the only
thing in this system that can answer the question asked after anything happens in the yard:
«хто їх пустив і коли вони заходили».

- Both sides are nullable and SET NULL, with the flat and the person kept as **labels** so
  a row still reads like a person after the FK is gone — the `ComplaintComment.author_label`
  rule.
- Read by everyone who can sign in, **GET only**. A security log that can be edited is not
  a log.
- The page lists the live passes first: «які пропуски зараз ходять по ЖК» is what it is
  opened with more often than the log itself.
- **Every result is named explicitly** with a neutral fallback, because `/admin/links`
  spent a week drawing votes as complaints from an unguarded `else`.
- Neither QR kind is a `LinkClick`: `DeepLink::record()` refuses `g-` and `p-` outright.
  `/admin/links` answers «did the chat post work», and a pass is nobody's post.

**A chat post can link into one topic of the instructions** — `i-<id>`, resolved through
`InfoCommand::LINKABLE`. «Читайте в боті» under an announcement is the same dead end
«↗️ Відкрити в боті» was introduced to remove: fifteen topics and the reader must find the
one the post was about. The ids are **explicit and permanent**, never positions in the
`TOPICS` array — a click recorded last month has to mean the same topic after somebody adds
a sixteenth. These *are* recorded as clicks, unlike the QR kinds: «did anybody actually read
it» is the only interesting question about an announcement.

## Backups

`db:backup` dumps the database and **delivers it off the server** — a copy that lives on the
machine it protects covers a bad migration and nothing else. The whole house is in this
database and nowhere else: 449 people, who lives in which flat, every phone the ОСББ has,
the arrears, the bookings, the complaints. None of it is reconstructible — the debt file is
the accountant's and only covers debts, and the resident↔flat mapping exists because Аліна
built it one person at a time over months.

Two independent channels, because a backup channel is exactly the thing that turns out to be
broken on the day it is needed: `BACKUP_TELEGRAM_CHAT_ID` (the bot sends the dump as a
document — no new credentials, readable from a phone) and `BACKUP_EMAIL` (an attachment over
`MAILER_DSN`, which survives losing the Telegram account too). Either failing does not stop
the other; with neither configured the command **warns loudly** rather than exiting green,
since a silently local-only backup is the state somebody thinks they are protected in.
`--keep` (14) prunes the on-disk copies, the DB password goes through the environment and
never onto a command line `ps` can read, and `BACKUP_PASSPHRASE` optionally encrypts
(AES-256) before delivery. Restore is plain
`gunzip -c <file> | psql -d <db>`.

**The dump subprocess is given an explicit working directory** (`$this->projectDir`),
never the inherited one — see the cron section for the night-after-night failure that
cost. A backup that fails is also the one failure nobody notices, so the cron line logs
to `var/log/backup.log` instead of running `-q` into silence.

## Contacting the ОСББ

Every message that ends with "ask the ОСББ" renders `OsbbContacts::ACCOUNTANT_LINE` /
`CHAIR_LINE` / `BOTH_LINES` — name, a `tel:` link and a `t.me/+<phone>` link. The sentence
used to be copy-pasted into twelve files as a bare number, which is a number to write down
and dial: a resident reading a block notice on a phone at 22:40 does neither. It also meant
that the day one of them changes their number, eleven messages still point at the old one.

The developer's line (`DEV_LINE`) is a plain `@username`, because he has one; both officers
fall back to `t.me/+<phone>` because neither does — the head of the ОСББ's registry field is
empty and the accountant's Telegram is not on the number she publishes. The shapes differ
because the facts do, not as a style choice.

**They are constants, not method calls**, because the FAQ keeps its whole text in a `const`
array and PHP constant expressions cannot call anything; concatenating constants is allowed.
`t.me/+<digits>` rather than a @username, because the head of the ОСББ has none (the
registry field is empty) and one shape that works for both beats a link that works for one.
`OsbbContactsTest` walks `src/` and fails on any file that keeps its own copy of a number.

## Debtors' board («дошка пошани»)

The house's total debt plus the three largest debtors, rendered above the main menu on
every `/start` / «🏠 На головну», with `💸 Звіт боржників` opening the full list — **paged**,
`DebtBoardService::PAGE_SIZE` (15) per page with ⬅️/➡️ and a «📌 Моя квартира» jump
(`debt-board:page:<n>`). It used to fill one message up to a character budget and stop with
«показано перших N із M», which published the top ~40 of 149 and hid the rest: the wrong 40,
since the extremes are already on the menu podium and the neighbour a resident actually
wonders about is in the middle. The page number is clamped, not trusted — a callback from an
older, longer list must not answer with an empty page — and the viewer's own line rides on
every page, because "am I on this list?" is the first question anyone opens it with. Asked
for by the head of the ОСББ as social pressure towards paying, in the joke register of a
podium (🥇🥈🥉4️⃣5️⃣👑, `TOP_SIZE` = 5) — that framing is deliberate, not decoration to be tidied away.

All the judgement is in `DebtBoardService`; `StartCommand::debtBlock()` and
`DebtBoardCommand` are only the Telegram halves. Three rules keep it defensible:

- **Verified residents only.** The viewer's `Account` is resolved by the caller and passed
  in; `menuBlock(null)` is `''` and `report(null)` explains `/phone`. Someone who opened
  the bot through 🔑 Оренда to browse flats is not part of the house and sees neither the
  board nor the button. (This is the opposite call to the rental noticeboard, and on
  purpose: an advertisement wants readers, a debt list does not.)
- **Never published without a date, and silent once stale.** `Account.debt_updated_at` is
  stamped inside `setDebt()` — not at the call sites, because there are four of them
  (`debt:import-file` and `/admin/debt/upload`, each with a main loop and a not-in-file
  reset loop) and a forgotten stamp is a silent lie on a public board. Every render carries
  «станом на …», and past `DebtBoardService::STALE_AFTER_DAYS` (30) the board hides itself
  rather than naming somebody over numbers nobody can vouch for. Debts only move when the
  accountant uploads a file; there is no live feed.
- **`place()` must print the building *and* the right kind of unit.** The type comes from
  the особовий рахунок (`isParking()` / `isStorage()`), never from the text: six of the
  eight non-flat accounts on prod carry a bare number in `apartment_number`, and the old
  "bare number ⇒ кв." rule published `237191` — a parking space owing 1 330 грн — as
  «буд. 19, кв. 191». No flat with that number exists in that building today, which is the
  only reason it had not yet accused anybody. Same fix applied to `PropertyRegistry::place()`
  and `ComplaintService::place()`, whose labels also reach the chat.
- **`place()` must always print the building.** The ЖК is five buildings on one street
  (Козацька 17, 19, 21, 23, 27) and apartment numbers repeat across them — when this
  shipped, "кв. 76" was one household owing 5 402 грн and another owing 651. Apartment
  alone accuses both of the larger debt. Regression test in `tests/Service/DebtBoardRulesTest`.

**The uploaded spreadsheets are kept.** `ImportArchive` copies every file that arrives at
`/admin/debt/upload` and `/admin/area/upload` into `var/import-archive/{debt,area}/`, named
`YYYY-MM-DD_HH-MM-SS-<admin login>.xlsx`, and both pages list the last
`ImportArchive::LIST_LIMIT` (24) with a download link (`/admin/import-archive/{kind}/{name}`,
behind `^/admin` like everything else). Until 04.09.2026 the file was read from PHP's temp
upload path and dropped with the request: `debt` is overwritten in place, `DebtSnapshot`
keeps only the totals, and `account_status_log` remembers a figure only for accounts that
crossed a block threshold that day — so the evidence behind numbers the bot *publishes to
the whole house* lived in one person's Telegram history. Deliberately dumb (files on disk,
no table, no retention job: an .xlsx of 172 rows is a few KB and one arrives a month), and
deliberately non-fatal — a failed archive is logged and the import carries on, because
losing an import that moved 143 accounts to save a copy of it would be the wrong trade.
`path()` validates the HTTP-supplied name against a strict pattern **and** re-checks that
the resolved path is still inside the archive directory; `ImportArchiveTest` pins that.

**The board counts every object, linked or not** — the queries filter on `debt >= 1` and
nothing else, so an arrears on a flat whose owner never opened the bot is in the total and
on the list. That was the intent all along; what made it untrue was that the bot held 175
of the ЖК's 966 objects, so 791 flats' arrears had nowhere to land. Since
`objects:import-registry` ran on 07.09.2026 the published figure is the house's, not the
bot's.

**A trend is only honest between two counts of the same thing.** When the number of flats
with a debt jumps by a quarter or more, `trendLine()` prints what actually changed —
«у боті побільшало обʼєктів (150 → 420)» — instead of «борг зріс на N», which is an
accusation about money nobody stopped paying. Covered by `DebtBoardRulesTest`.

**The upload result names the rows we could not match, with their debt.** Аліна's file
carries accounts the bot has never heard of, and their arrears are simply absent from the
board, the chat post and the house total — the published figure is "the debt of the accounts
in the bot", not "the debt of the house". `/admin/debt` now shows the count *and* the sum
behind it, so the gap is visible on the same screen instead of being inferred later.

**The announcement in the residents' chat** rides on the import, not on a cron: `DebtAnnouncer::afterImport()`
is the tail of both import paths (`debt:import-file` and `/admin/debt/upload`), so the figures
are fresh by construction and the post shows movement month to month. It leads with the total,
the flat count and the trend, then names the top **twenty** (`ANNOUNCE_SIZE`, widened from ten
on 04.09.2026 — the chat post is the only *push* half of this feature and against 149 flats
owing money a top ten is a list of the extremes, not a picture of the house; the heading
counts what it actually prints, so a short list never claims to be twenty). Guards: once per calendar
day (a corrected re-upload must not put a second list in front of the house), only when the chat
is configured, and never fatal — a failed post must not undo an import that already moved 143
accounts. The post is **pinned** in the group (`can_pin_messages` is granted), silently — the message
itself has just notified everyone — and last month's is unpinned right after, so the pinned list
does not grow by one every import; `DebtSnapshot.announced_message_id` is what remembers which.
A pin that fails is logged, never reported as a failed announcement.
`debt:announce [--dry-run] [--force] [--snapshot]` previews or re-sends it by hand.
`DebtSnapshot` is one row per import (total, debtor count, `announced_at`); it exists because the
debt column is overwritten in place, leaving the house no memory of its own arrears.

The chat post is *push* and forwardable, unlike the pull-only menu board — that difference was
argued and Иван chose the named top-ten anyway (02.09.2026), so keep the aggregate leading and
the date in the header.

Apartment + building and nothing else: no names, no phone numbers. `is_active` is not
consulted — this is about the debt, not about booking rights. The viewer's own line
(«📌 Ваша квартира у списку: … , N місце» / «✅ боргів не має») is the half that makes it
readable by the 86 residents who owe nothing.

## Complaints register («🔧 Заявки»)

What broke in the house and what is being done about it: a dead lift, a burst hose, a
parking gate that will not open. All of it already happened in the residents' chat, where
a report is a message that scrolls away — three people report the same lift, nobody knows
whether the head of the ОСББ saw it, and nobody ever learns when it was fixed.

**The register exists for the status, not for the list.** A resident who opens the bot and
reads «🔧 Ліфт не працює — в роботі» does not post the fourth message about it. That is why
the whole house sees every entry and why the open count rides on the menu button itself
(`🔧 Заявки (3)`), and why the report button sits *under* the list rather than above it.

- **Filing is open to everyone the ОСББ recognises.** `is_active` is not checked — a debt
  blocks *booking*, and a debtor is still paying for that lift. Unit type is not checked
  either: "ворота в паркінг не відчиняються" is by definition a parking owner's report.
  Only an unlinked visitor is out, the same call as the residents' chat.
- **Statuses are the head of the ОСББ's alone** (🆕 Нова → 🔧 В роботі → ⏸ Відкладено →
  ✅ Виконано, and back). "Виконано" is a statement about what the ОСББ did; a register anyone can close
  records nothing. Managers are Telegram ids in `.env.local`
  (`COMPLAINT_MANAGER_TELEGRAM_IDS`), same shape as `RESIDENT_CHAT_ID` — one or two people
  who change about never, so a column and an admin checkbox would be machinery for nothing.
  **An empty list means nobody can move a status, never everybody**; there is a test for it.
  She can also work from `/admin/complaints`, which is where the "що зробили" note and the
  official answers are typed — awkward on a phone keyboard. That page is **cards, not a
  table**: it is read on a phone as often as on a desktop, and a seven-column table with a
  form in the last column keeps the two controls that matter off-screen. `base.html.twig`
  had **no viewport meta tag** until 04.09.2026, so the whole panel — DataTables'
  responsive plugin, every `col-sm-*` — rendered at 980px and zoomed out on a phone; the
  same block also lifts small inputs to 16px under 576px, because anything smaller makes
  iOS zoom the page in on focus.
- **The entry stays the author's.** They can retype the text (`ComplaintEdit`) or delete it
  outright, at any status — a confirmation step first, and the photos go with it. Restricting
  deletion to 🆕 was considered and rejected: typos, duplicates and problems that fix
  themselves do not stop happening the moment Людмила taps «в роботі», and a resident who
  cannot withdraw their own entry simply files a second one saying "ignore the previous".
- **The list has a «📌 Мої заявки» / «📋 Усі заявки» toggle** (`cmp:my:<page>` vs
  `cmp:page:<n>`), shown only to somebody who has actually filed something — an empty
  "Мої" is a dead end. Finishing on the photo page pushes the complaint back into the
  author's chat (`notifyPhotosUpdated()`) with buttons to the card, the list and the main
  menu. **The «📷 Фото до заявки» prompt is also rewritten in place on every upload**
  (`confirmPhotoOnPrompt()`, using `Complaint.photo_prompt_message_id`) — the Web App gives
  the server no "closed" event and people dismiss it with the ✕ at least as often as with
  Готово, so anything that waits for that button simply never arrives and they return to
  the message that sent them there as if nothing had happened. A text message cannot be
  edited into a photo, so the prompt becomes a text confirmation and the picture lives on
  the card its buttons lead to.
- **Filing announces itself.** The residents' chat gets «🆕 Нова заявка №N · буд. 19, кв. 85»
  **with a notification** — "ліфт не працює" is the one thing a neighbour wants to know
  before they walk to the lift — and every configured manager gets a DM with a button
  straight to the card, because a register the head of the ОСББ has to remember to open is
  a register that fills up. **Group posts carry no inline buttons and cannot**: the global
  middleware drops every update arriving from a group, so a callback there never reaches a
  handler.
- **A status change reaches two audiences, from inside `changeStatus()`.** The author gets
  a DM showing the transition («🆕 Нова → 🔧 В роботі» — the new state alone does not say
  whether it moved a minute ago or has read that way for a week), and the residents' chat
  gets a silent post, so a repair the ОСББ actually did is not indistinguishable from no
  repair. Both live in the service, not the bot handler: while they were in the handler, a
  status moved from `/admin/complaints` told nobody at all. Silent on purpose —
  `disable_notification: true` — since progress can wait until the chat is next opened,
  unlike the arrival of the problem itself.
- **❌ Відхилено is for the entries that are not work**: the same lift reported a fourth
  time, something inside somebody's own flat, a test message. Asked for by Сергій
  (07.09.2026) — «ним прибирати дубляжі і відсікати всяку діч». Before it the only ways to
  clear one were «✅ Виконано», which puts a repair that never happened in front of the
  whole house, and leaving it open, which is what the badge counts. It carries a
  **mandatory reason** for the same purpose the hold's does and a sharper one — this is
  the ОСББ saying no to a resident in a register their neighbours read — enforced in
  `changeStatus()` and in both entry points, and **cleared on reopen** so «дубль заявки
  №11» cannot ride into «🔧 В роботі» as the work note. Deleting stays the author's alone:
  a manager who could delete could make an awkward report vanish without trace, while a
  rejection is on the record with a name and a reason on it. `Complaint::CLOSED_STATUSES`
  (done + rejected) is what the badge, the list sort and the cleanup read — `isDone()`
  still means «Виконано» alone, because that word is a claim about work performed.
  `ComplaintHold` serves both buttons (`cmp:hold:` / `cmp:reject:`) and keeps its
  now-inaccurate name on purpose: live conversations are serialised into `var/pools` by
  class name, so a rename fatals for whoever was mid-hold across the deploy.
- **⏸ Відкладено must say what it is waiting for.** The most common real state of a house
  problem — known, agreed, waiting on a part, a contractor or the money — had no word, so
  «в роботі» had to mean both "майстер їде зараз" and "чекаємо насос три тижні", and a
  resident reading it a second week running concludes nothing is happening. The reason is
  mandatory and enforced twice: the bot button opens `ComplaintHold` (a conversation that
  asks) instead of flipping the status, and `ComplaintService::changeStatus()` throws on a
  hold with an empty note no matter who calls it — `/admin/complaints` catches that and
  answers with a flash rather than a 500. The reason is stored in `resolution`, the one
  "note about where this stands now" field: **leaving a hold with no new note clears it**,
  or «✅ Виконано» would carry «чекаємо насос із Польщі» into the author's DM and the
  residents' chat, saying the opposite of what happened. A held complaint is still
  `isOpen()` — it counts in the menu badge and in the cleanup rules.
- **The author's contact is shown to the head of the ОСББ and to nobody else.** Every
  resident reads this register; the phone in that row is there because the person gave it
  to the ОСББ for нарахування. `authorContactLine()` / `authorChatUrl()` are gated on
  `isManager()` at each call site — the card, the "нова заявка" DM, the comment DM — and
  `/admin/complaints` shows the same as `tel:` and `t.me` links, since she already has that
  number in `/admin/users` and she is the one who has to ring back. The link is
  `t.me/<username>`, falling back to `t.me/+<phone>` (only ~48% of rows have a username —
  same shape the menu header uses for Людмила's own number).
- **The discussion is read by the house and written by two people.** `ComplaintComment` is
  an open thread under each card (`cmp:talk:<id>`, «💬 Написати» → `ComplaintReply`);
  `mayComment()` admits the complaint's account and the managers, nobody else. Opening it
  to all 141 flats was considered and rejected: the thread under the broken lift becomes
  the chat this register replaced, and the one answer that matters is buried in it — a
  neighbour with the same problem files their own entry. **Nothing is edited or deleted**
  (the complaint itself stays the author's to retype; what was said about it does not), and
  `author_label` is a *snapshot* so a row still reads «буд. 19, кв. 85» after the FK is
  nulled and «Людмила (голова ОСББ)» after she leaves the manager list —
  `ComplaintService::adminLabel()` maps admin logins to people, because «luda_boss» must
  not appear under an official answer the whole house reads. Notifications go to the two
  parties only (her comment DMs the author with a «💬 Відповісти» button, theirs DMs the
  managers); the chat still hears status changes and nothing else. The thread is a
  **separate message**, not more lines on the card: a card with photos is a caption, capped
  at 1024 characters, and a thread appended to it would render for two comments and then
  silently stop sending the card at all.
- **Both new conversations carry the `interceptConversationPhoto()` guard**, and a tap on
  any button while one is live is treated as «скасувати» — Nutgram routes every update from
  that user into the conversation, so an unhandled callback there is a spinning button.
  `ComplaintHandlerWiringTest::testEveryCallbackButtonIsRoutedByThePatternsInTheConfig`
  walks the `cmp:` literals in the sources against the regexes in `config/telegram.php`: an
  unrouted button errors nowhere, it just spins, which is indistinguishable from the bot
  being down.
- **Filing is one step.** The person doing it is standing in front of a broken lift, and
  every extra question is a reason to close the bot and write in the chat instead. Photos
  are offered *after* the complaint is saved, so giving up at that point still leaves the
  problem reported.
- **What the ОСББ did is its own set of photos.** `Complaint.result_photos`, asked for by
  Сергій («до статусу зробиш фотки?», 07.09.2026), offered — never demanded — right after
  «✅ Виконано» and from a button on `/admin/complaints`. One array would have been less
  code and the wrong data: the value of these pictures is «було / стало», and a single
  list puts the repaired lift and the broken one side by side with nothing saying which
  is which. The card leafs through both (the problem first) and the carousel counter
  turns 🖼 into 🔧 on the ОСББ's own, since on a photo card that counter is the only line
  that can say so. The author is told «ОСББ додало фото виконаної роботи», which is news,
  as opposed to the echo of their own upload.
  **The target rides on the token** (`photo_token_target`), because the token *is* the
  authorisation and nobody is logged in on that page: the server cannot ask who is
  uploading and must know from the link. That also keeps each side from deleting the
  other's evidence, and stops a manager's upload rewriting the author's «📷 Фото до
  заявки» prompt in somebody else's chat.
- **Photos go through the web, never the bot.** `ComplaintCreate` carries the mandatory
  `interceptConversationPhoto()` guard and has no photo step — it matters more here than
  anywhere else, because this conversation is *about* photographing something broken. A
  picture sent to the bot means pavilion evidence and nothing else; see the rental section
  for why that invariant cannot be relaxed. The page is a Telegram Web App
  (`/complaint/photo/{token}`, `WebAppInfo`) that downscales in the browser and closes back
  into the chat, and the card is the same one-message carousel as a rental listing.
- **`complaint:cleanup` (daily 04:15) keeps it a list of live problems**: finished entries
  are purged `DONE_RETENTION_DAYS` (30) after they were *closed* — measured from
  `status_changed_at`, so something reported in January and fixed in June survives until
  July — and untouched ones after `STALE_OPEN_DAYS` (180). I argued for auto-closing the
  stale ones instead of deleting them, since a six-month-old open entry is a record of
  nobody having done anything; Иван's call (02.09.2026) was that such a problem was not a
  real one and will be filed again if it still matters. Photos are deleted with the row.

**The three tokenised upload pages are near-twins by copy, and drift.** Complaints,
rental listings and service offers each have their own `photo_upload.html.twig` — a
deliberate call, since each is standalone, opened inside Telegram on a phone, with no
Encore and no shared layout — and on 08.09.2026 the rental one, the original the other two
were copied from, was still letting a resident tap «Готово» mid-upload and lose the
picture. On a phone an upload is seconds of nothing happening, which is exactly when
somebody presses it. All three now hide the button while a request is in flight, restore it
on **both** the success and the error path, and spin the status line in red so it reads as
"wait". `PhotoUploadPagesTest` walks all three and pins only the rules that cost somebody a
photo; the copy and the headings stay each page's own.

`ImageStore` holds the upload rules shared with the rental noticeboard — size cap, GD
re-encode (which is what strips EXIF/GPS), 1600px downscale, and the prefix check that
guards `unlink()` against a path that arrived over HTTP. It was extracted when this
shipped: two copies of that means the day one is fixed the other silently is not.

## Residents' Telegram group («ЖК City Park • Черкаси»)

A closed group whose door is the bot. The house already had the only verified list of
its own residents — `Account` ↔ `TelegramUser`, built by the accountant against the ОСББ
registry — and this feature does nothing but let that list decide who gets in. It exists
because the ЖК's Viber group dates back to construction: ~650 people for 141 flats,
nobody can say who half of them are, and Viber shows every member's phone number to every
other member. Cleaning that group from the inside is not possible; a verified one is.

**The gate is one update.** The group's only invite link is created with
`creates_join_request=true` (`bin/console resident-chat:link`), so Telegram holds every
newcomer at the door and asks the bot. `JoinRequestCommand` → `ResidentChatService` looks
the knocking `user_id` up and approves or declines. The link is therefore *not* a secret
and can be posted anywhere — what is checked is who knocks, not who holds the link. A
one-shot `member_limit=1` link would have been the weaker design: forwarded, it admits
whoever taps first.

- **`is_active` is NOT checked**, same call as the rental noticeboard: a debt or a missed
  pavilion photo blocks *booking*, and the chat is where the ОСББ announces things —
  including that the person owes money. Parking-only accounts are let in too
  (`isNonResidential()` bars booking the pavilion, not reading the house chat).
- **A member is not offered the door again.** `ResidentChatService::isMember()` asks
  `getChatMember` at render time, so somebody already inside sees «✅ Ви вже в чаті» and a
  «🚪 Відкрити чат» button instead of an invitation. `RESTRICTED` counts as a member only
  when `is_member` is true — a restricted user who left keeps that status. When Telegram
  cannot be asked the method returns null and the caller falls back to the invitation:
  showing the door to a member is a much smaller mistake than hiding it from someone who
  needs it. (Tapping the link while already a member is harmless in any case — Telegram
  resolves it to "open the chat" and no join request is created; `handleJoinRequest`
  catches `USER_ALREADY_PARTICIPANT` anyway.)
- A decline is not a ban: it explains the two-tap fix (`/phone` → share number) and the
  same person can request again. The refusal text goes out **before** `declineChatJoinRequest`
  — `user_chat_id` is the bot's only way to reach someone it has never spoken to, and that
  door closes when the request is processed.
- The menu button appears only when `RESIDENT_CHAT_ID` **and** `RESIDENT_CHAT_INVITE_LINK`
  are both set (`ResidentChatService::isConfigured()`); the group is made by hand in
  Telegram, not by a migration. It sits **second** in both the inline menu and the slash
  menu, under 🔑 Оренда — the announcement to residents tells them it is the second
  button, so the two orders have to stay in step.

**The group runs in Topics mode since 07.09.2026**, switched on by hand in Telegram
(«Edit group» → «Topics») — that toggle belongs to the owner, not to a migration. A
message with no `message_thread_id` lands in **General**, so an unset topic is not a
failure: it is exactly what the bot did before. `ResidentChatService::topic()` resolves
`RESIDENT_CHAT_TOPIC_COMPLAINTS` / `RESIDENT_CHAT_TOPIC_DEBT` and returns **null for
anything that is not a bare positive integer** — a wrong id makes Telegram reject the
send outright, and a «🆕 Нова заявка» that throws is a broken lift nobody hears about,
while one in the wrong tab is a nuisance. `resident-chat:topics` creates the two branches
and prints the ids to paste, because Telegram has no way to *list* topics: `createForumTopic`
returns the id once and never again (a topic made by hand has to have its id read out of a
message link, `t.me/c/<chat>/<topic>/<message>`). Covered by `tests/Service/ResidentChatTopicsTest`.

**`allowed_updates` must include `chat_join_request`** — Telegram's default list leaves it
out, and the failure is silent: people queue at the door forever while the bot never hears
them knock. `bin/console bot:webhook:update` re-registers the webhook with the four types
we handle, reading the URL back from Telegram so it cannot re-point itself.

**Group setup, in this order** (the id changes when Telegram converts a basic group into a
supergroup, so it is read *last*): permissions — «Add Members», «Pin Messages» and «Change
Group Info» off for ordinary members, private group → add the bot → promote it with
`can_invite_users` (Telegram Desktop labels this admin right **«Add members»**, mobile
labels it «Invite Users via Link») and `can_restrict_members` → `resident-chat:link` →
write both values into `.env.local`.

**Live since 02.09.2026.** Verified end-to-end on prod: approve 320 ms, decline 330 ms, the
decline DM does reach someone the bot has never spoken to, and a declined user may request
again immediately (no Telegram cooldown — so the refusal text's "надішліть заявку ще раз" is
honest). The chat id and invite link live in prod `.env.local` only; the link is never
published, the bot hands it out. Note that `getChat()` on a pre-migration basic-group id
keeps returning the stale `"group"` card long after Telegram has upgraded the chat — the
migration only surfaced as `migrate_to_chat_id` in a `createChatInviteLink` error, which is
how the real supergroup id was found.

**Moderation lives on the resident's card and in a command**, both calling `ResidentChatService::moderate()` so there is one path and one log line. `resident-chat:ban <phone|о/р|@username|id>`
removes somebody from the group; the person is found the way the accountant knows them,
and if the argument matches two people it prints both and stops rather than guessing.
The distinction that matters is `--kick` versus the default: a kick is ban+unban, so they
are removed and may ask to join again (the gate lets them back if they are still a
resident) — the right tool for "cool off" and for somebody who sold their flat; a plain
ban keeps them out until `--unban`, and Telegram will not even deliver a join request
from them. `--notify` (a checkbox in the panel) tells the person, because leaving somebody to
discover a shut door is worse than saying why. Every use is logged to
`resident-chat.log` with the reason and who did it. The panel's buttons are
«🚪 Видалити з чату» and «🚫 Заборонити вхід у чат» — deliberately not «Заблокувати»,
which on that same card already means blocking the альтанка.

**Open follow-up: the gate closes the entry, not the exit.** Someone who sells their flat
stays in the group until removed by hand. Bot API cannot list members, so this needs our own
roster: a row per approve, `chat_member` added to `allowed_updates` (it is deliberately not
there today), and a nightly cron re-running `mayJoin()` and removing those who no longer
qualify with `banChatMember` **followed immediately by** `unbanChatMember` — a bare ban locks
them out permanently, which is wrong, since an ex-owner may buy another flat here. Deferred on
purpose: this matters in a year or two, not at six members.

### Nothing from a group may reach a private-chat handler

The bot loses Telegram's privacy mode as soon as it is an administrator and receives every
message posted in the chat. The handlers cannot tell the difference on their own, so the
rule lives in **one global middleware in `config/telegram.php`** plus
`RequestSubscriber::privateChatSender()`, and is covered by `tests/Telegram/GroupUpdateGuardTest`
and `tests/EventSubscriber/PrivateChatGuardTest`.

Two concrete failures it prevents:

- `onPhoto` would file a picture posted in the group as pavilion evidence and close
  somebody's `PhotoUploadRequest` — most likely the obligation of whoever posted a photo
  of their cat.
- `initUser()` overwrites `TelegramUser.chat_id` from whatever chat an update arrived in,
  and `chat_id` is the address of **every** outgoing notice (debts, photo reminders and
  blocks, vote notices, the rental phone relay). A group update carries the *group's* id in
  that field. This is not hypothetical: on 02.09.2026, before a word had been written in
  the new group, the single service message "Ivan added the bot" re-pointed the owner's own
  `chat_id` at it (`telegram_user` #1, repaired with
  `update telegram_user set chat_id = telegram_id where chat_id like '-%'`).

Global middleware is attached in `Nutgram::preflight()`, which runs from `run()` — *not*
from `processUpdate()`. Tests that fire handlers directly must invoke `preflight()` first
or they silently exercise a bot with no middleware.

## Crons (prod `crontab -l`, **must run as `www-data`**)

```
45 * * * * sudo -u www-data php …/city-park/bin/console RemindCommand
10 * * * * sudo -u www-data php …/city-park/bin/console WarmWeatherCommand
0 9 15 * * sudo -u www-data php …/city-park/bin/console DebtNotifyCommand
*/20 * * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:check --env=prod
30 3 * * * sudo -u www-data php …/city-park/bin/console pavilion:photo:cleanup --env=prod
0 * * * * sudo -u www-data php …/city-park/bin/console block-vote:tally --env=prod
0 4 * * * sudo -u www-data php …/city-park/bin/console rental:expire --env=prod
15 4 * * * sudo -u www-data php …/city-park/bin/console complaint:cleanup --env=prod
30 4 * * * sudo -u www-data php …/city-park/bin/console service:expire --env=prod
0 5 * * 1 cd …/city-park && sudo -u www-data php bin/console telegram:avatars:sync --env=prod
0 2 * * * cd …/city-park && sudo -u www-data php bin/console db:backup --env=prod >> var/log/backup.log 2>&1
```

**Every line `cd`s into the project first** (or the `db:backup` one does, at minimum).
Cron starts a job in `/root`, mode `700`, and a `sudo -u www-data` command inherits that
as its working directory: anything that then spawns a subprocess dies with `proc_open():
posix_spawn() failed: Permission denied` before it runs. That is what silently killed
every nightly backup until 07.09.2026 — the command works by hand from any other
directory, which is why it read as fine.

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
sudo -u www-data php bin/console cache:clear --env=prod       # NOT `rm -rf var/cache/prod` — see below
php bin/console doctrine:migrations:migrate --no-interaction --env=prod   # if migration added
sudo -u www-data php bin/console bot:menu:update --env=prod          # idempotent; safe every deploy
mkdir -p public/uploads/pavilion-photos
chown -R www-data:www-data var/cache var/log var/pools public/uploads
systemctl restart city-park-messenger.service                        # long-running worker; see above
# NOTE: php-fpm is deliberately NOT restarted — see below
```

**Do not restart php-fpm on deploy.** `opcache.validate_timestamps` is **On** with
`revalidate_freq=2` on this server, so PHP re-reads a changed file within two seconds by
itself — a restart picks up nothing a two-second wait would not, and it drops every
in-flight connection: two real Telegram updates answered **502** on 02.09.2026 for exactly
this. (An older note in per-machine memory said to prefer `restart` over `reload` because
OpCache can survive a reload. That was about a reload not picking up new code; with
validate_timestamps On, neither is needed. Should that setting ever be turned off for
performance, the restart has to come back — and with it the 502s, so drain traffic first.)
The **messenger worker is different**: it is a long-running process holding code loaded at
start, and it must be restarted.

**Conversation state lives in `var/pools/`, not in `var/cache/`.** Nutgram keeps it in the
app cache pool — which step of «⏸ Відкласти» a manager is on, half a complaint somebody is
typing — and Symfony's default puts that pool under `%kernel.cache_dir%`. So every
`cache:clear` threw live conversations away. The failure is invisible from both ends: the
prompt arrives, the answer goes nowhere, nothing is logged because nothing threw, and the
person reports «натискаю — нічого не міняється». It cost an afternoon on 07.09.2026, with
nine deploys in it, before `photo-check.log` showed `step: askReason` three times in a row
for a manager who had already been asked for a reason. `framework.cache.directory` now
points at `var/pools`; `ConversationCacheSurvivesDeployTest` pins it, and the directory
needs the same `chown www-data` as `var/cache` and `var/log`.

**Never `rm -rf var/cache/prod` on a live server.** php-fpm keeps serving `/hook` while the
cache is missing, and every Telegram update that lands in that window answers 500. On
02.09.2026 four deploys in one afternoon each cost a real resident's update a 500 (Telegram
retried a second later, so nothing was lost, but the errors are real and they land in the log
we grep). `cache:clear` warms into a temporary directory and swaps it in with two renames, so
the gap is microseconds instead of the seconds a warmup takes. Run it as `www-data`, or the
new cache is root-owned and conversation state breaks (incident 2026-05-03).

Feature-branch workflow preferred for normal work; direct master only when explicitly approved.

## Memory pointers

User-level auto-memory at `~/.claude/projects/-home-ivan-hosts-city-park/memory/` mirrors most of this (project_photo_obligation, project_booking_rules, reference_prod_cron, reference_admin_panel, reference_deploy, reference_prod_paths). When using this repo from a fresh checkout on another machine, this CLAUDE.md is the portable copy; the per-machine memory files supplement it with cross-session preferences.
