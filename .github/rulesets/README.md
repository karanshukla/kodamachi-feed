# Branch rulesets

The same protection navyfragen-feed runs, as API payloads.

GitHub gates rulesets (and classic branch protection) behind Pro for private
repositories, so these are not applied yet. Apply both once the repo is public
or the account is on Pro:

```bash
for f in .github/rulesets/*.json; do
  gh api repos/karanshukla/kodamachi-feed/rulesets -X POST --input "$f"
done
```

`protect-main.json` requires three status checks by name: `Lint and test`,
`Boot and serve`, and `Docker build`. Those are the `name:` fields of the jobs
in `../workflows/ci.yml`, not the job ids, so renaming a job there means
updating this file too.

There is no CodeQL entry, unlike navyfragen-feed: CodeQL has no PHP analyzer.
`composer audit` in the `Lint and test` job is the security gate instead.
