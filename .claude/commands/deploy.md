Deploy the city-park project to production. This is the city-park-specific deploy flow (the global /deploy is for tbuddet-bot-v7 and uses a different path/host config).

Accepts an optional argument:
- `be` (backend only — no encore build)
- `fe` (frontend only — encore build + cache; skip migrations and commands)
- `all` (default — full deploy)

## Steps

1. **Pre-flight** — run locally:
   - `git status` → confirm working tree clean (or commit first, ask user).
   - `git log --oneline origin/master..HEAD` → confirm there are local commits to deploy.

2. **Push to master**
   - `git push origin master`
   - Note: master has branch protection. If push fails with HTTP 500 + "remote rejected", push to a `feature/*` branch and merge via GitHub UI:
     `git push origin master:feature/<short-desc>` then open PR at https://github.com/shubaivan/city-park/pull/new/feature/<short-desc>.

3. **Pull on prod** (SSH key: `~/.ssh/buddet_rsa`, host: `139.28.37.27`):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && git pull origin master'
   ```

4. **Build assets** (skip if argument is `be`):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && NODE_OPTIONS=--openssl-legacy-provider npx encore production'
   ```
   The `NODE_OPTIONS` flag is needed because prod runs a newer Node vs the old webpack/terser combo.

5. **Composer install** (only when `composer.lock` changed):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && composer install --no-dev --optimize-autoloader --no-interaction'
   ```

6. **Clear + warmup cache** (always):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && rm -rf var/cache/prod && php bin/console cache:warmup --env=prod'
   ```

7. **Run migrations** (only when new migration files added):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && php bin/console doctrine:migrations:migrate --no-interaction --env=prod'
   ```

8. **Update bot menu** (only when `BotMenuUpdateCommand::MENU` changed — idempotent so safe every deploy):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'cd /var/www/html/city-park && sudo -u www-data php bin/console bot:menu:update --env=prod'
   ```

9. **Permissions + FPM restart** (always after cache rebuild):
   ```
   ssh -i ~/.ssh/buddet_rsa root@139.28.37.27 'mkdir -p /var/www/html/city-park/public/uploads/pavilion-photos && chown -R www-data:www-data /var/www/html/city-park/var/cache /var/www/html/city-park/var/log /var/www/html/city-park/public/uploads && systemctl restart php8.3-fpm'
   ```
   Use `restart` (not `reload`) — OpCache shared memory survives reloads and can cause stale code execution after a deploy.

10. **Verify**:
    - `ls -ld /var/www/html/city-park/var/cache/prod/pools/app/` → must show `www-data` owner. If root-owned, redo step 9 — that's the cause of the 2026-05-03 conversation-state incident.
    - Smoke test the admin page that was changed (curl `https://yourdomain/admin/...` or visually).

## Important constraints
- **Path is `/var/www/html/city-park/`** (NOT `tbuddet-bot-v7/`).
- **Never run console commands as root** — always `sudo -u www-data` for bot:menu:update; cache:warmup and migrations are allowed as root because they don't write conversation state, but after them the chown step is mandatory.
- **Never hand-edit code on prod** — always go through git + pull (incident with manual edits caused a 7GB log file). The only exception is when GitHub is unreachable: scp the specific files to a temp location, copy them in, then sync git afterwards.
- **Master branch protection**: pushes go through PR via feature branch when protection is on.
