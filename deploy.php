<?php

namespace Deployer;

require 'recipe/laravel.php';

// Project name.
set('application', 'huts');

// Project repository — cloned on the server via the `github-huts` ssh alias
// (see the deploy user's ~/.ssh/config on imp).
set('repository', 'git@github-huts:AydinHassan/alpine-hut-finder.git');

set('git_tty', false);

// Shared between deploys. `.env` holds the DB + app secrets; `database/` keeps
// nothing for MySQL but is shared harmlessly. The API response cache and logs
// live under the shared `storage/` dir (provided by recipe/laravel.php).
add('shared_files', ['.env']);
add('shared_dirs', []);
add('writable_dirs', []);
set('allow_anonymous_stats', false);

// Host — imp (same box as duopay / grid-planner), reached over Tailscale.
host('personal')
    ->setHostname('100.70.13.88')
    ->setRemoteUser('deploy')
    ->set('deploy_path', '/var/www/huts');

// Front end is a Vue/shadcn SPA built by Vite. Assets are built in CI (see
// .github/workflows/deploy.yml) and uploaded into each release — the server
// itself needs neither node nor npm.
task('assets:upload', function () {
    upload('public/build/', '{{release_path}}/public/build/');
});
after('deploy:update_code', 'assets:upload');

// Run migrations before the new release goes live.
before('deploy:symlink', 'artisan:migrate');

// Note: hut data is NOT synced on deploy. It lives in the (release-independent)
// database and is kept fresh by the hourly scheduler (routes/console.php +
// the deploy-user crontab). The initial population is a one-off after the first
// deploy — syncing on every deploy would needlessly re-hit the upstream APIs.

// Auto-unlock on failure.
after('deploy:failed', 'deploy:unlock');
