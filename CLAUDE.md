# My NJILGA — project instructions for Claude

## ALWAYS bump the plugin version when you change the repo

This plugin auto-updates installed WordPress sites via
[yahnis-elsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
pointing at GitHub Releases of `s-fx-com/MyNJILGA`. The update checker
compares the `Version:` header in `njilga-membership-report.php` against
the latest GitHub release tag — if the header isn't bumped, sites won't
see the update.

**Therefore: any commit that changes plugin code or assets must also
bump the `Version:` header.** Treat this as part of the change, not a
follow-up.

### Mechanics

1. Edit `njilga-membership-report.php` line ~6 and increment the
   `Version:` header using [semver](https://semver.org/):
   - **patch** (`2.0.0` → `2.0.1`) — bug fixes, copy tweaks, CSS-only
     changes, doc/comment edits inside PHP files
   - **minor** (`2.0.1` → `2.1.0`) — new features, new pages, new
     reports, new admin behavior that doesn't break existing flows
   - **major** (`2.1.0` → `3.0.0`) — removes a page, renames a tag
     slug the plugin looks up, changes a `define()` or hook name a
     downstream site might depend on, or otherwise breaks an existing
     install's expectations
2. Include the version bump in the **same commit** as the code change
   so the git history shows a 1:1 mapping between version numbers and
   feature deltas. Don't ship a "bump version" commit on its own.
3. After the commit is pushed, cut a matching GitHub Release whose tag
   is `v<version>` (e.g. `v2.1.0` for `Version: 2.1.0`). The update
   checker uses the highest-numbered release tag, not the latest
   commit on `main`.

### Doc-only / non-shipping changes

If a commit truly only edits files that don't ship to the plugin user
— **README.md, CLAUDE.md, .gitignore, .github/workflows/** — the
version header may stay the same. Everything else (PHP, JS, CSS, image
assets, composer.json/lock, vendor/) is shipped and requires a bump.

When in doubt, bump it. There's no penalty for an extra patch version
and the update flow only works if versions move forward.

### Quick check before committing

```bash
# Show the current version header and the last release tag for comparison
grep -n "Version:" njilga-membership-report.php
git tag --sort=-v:refname | head -3
```

If you're about to commit a code change and the `Version:` header
matches the latest tag, bump it before staging.
