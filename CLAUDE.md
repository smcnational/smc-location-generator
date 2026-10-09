# Project Estimator (WordPress plugin)

## Workflow
- Edit files directly in this repo. Never use or create zips in ~/Downloads.
- After finishing the requested changes, release them:
  1. Run `php -l` on every changed PHP file. Fix any errors before continuing.
  2. Add a short entry to the changelog in readme.txt (if it has one) for the new version.
  3. Run: `ship --bump X.Y.Z -m "Short summary of the change" --yes`
     - Use the next patch version (1.4.1 → 1.4.2) unless I ask for a minor or major bump.
     - `ship` updates the Version header, the *_VERSION constant, and the readme Stable tag itself. Don't edit those by hand.
- If I say "don't ship" or "just test", make the changes but skip step 3.

## Rules
- Keep the summary in `-m` short and plain (it becomes the commit message).
- Ask before deleting files or renaming database tables, option names, or hooks.
- If `ship` fails, show me the error instead of retrying with different flags.
