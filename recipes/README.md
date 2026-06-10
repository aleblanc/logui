# LogUI — Symfony Flex recipe (source)

A recipe lets `composer require aleblanc/logui` auto-configure the bundle: register it in
`config/bundles.php`, drop `config/routes/log_ui.yaml` and an optional `config/packages/log_ui.yaml`,
and print a reminder to add the Monolog handler.

```
index.json                              ← endpoint index (repo root)
recipes/aleblanc/logui/0.1/
├── manifest.json                       ← bundle registration + copy-from-recipe + post-install message
└── config/
    ├── routes/log_ui.yaml              ← the /_logui route
    └── packages/log_ui.yaml            ← optional config (commented; defaults work)
```

> **Flex never reads this from the installed package.** Recipes are resolved from a recipe
> **endpoint** (queried by package name). Below is how to serve this folder as a *custom* endpoint.

---

## A. Use it as a custom endpoint (your own apps)

Each app that should auto-configure LogUI declares the endpoint in **its own** `composer.json`
(`flex://defaults` keeps the official recipes working too):

```json
{
    "extra": {
        "symfony": {
            "allow-contrib": true,
            "endpoint": [
                "https://api.github.com/repos/aleblanc/logui/contents/index.json?ref=main",
                "flex://defaults"
            ]
        }
    }
}
```

Then:

```bash
composer require aleblanc/logui      # picks up the recipe from your endpoint
composer recipes                     # list resolved recipes
composer recipes:install aleblanc/logui --force -v   # (re)apply if needed
```

`index.json` (repo root) advertises the recipe and tells Flex where to fetch it:

```json
{
    "recipes": { "aleblanc/logui": ["0.1"] },
    "branch": "main",
    "is_contrib": true,
    "_links": {
        "repository": "github.com/aleblanc/logui",
        "origin_template": "{package}:{version}@github.com/aleblanc/logui:main",
        "recipe_template": "https://api.github.com/repos/aleblanc/logui/contents/recipes/{package}/{version}/manifest.json?ref=main"
    }
}
```

`{package_dotted}` expands to `aleblanc.logui` and `{version}` to `0.1`, i.e. the **compiled**
recipe file [`recipes/aleblanc.logui.0.1.json`](aleblanc.logui.0.1.json).

### Format: compiled, not the source tree
Flex consumes the *compiled* form — one JSON per recipe with the manifest and **file contents
inlined** (`manifests.<package>.{manifest, files, ref}`). That's `recipes/aleblanc.logui.0.1.json`,
generated from the source folder below. Regenerate it whenever you change the source:

```bash
python3 - <<'PY'
import json, hashlib, pathlib
base = pathlib.Path("recipes/aleblanc/logui/0.1")
manifest = json.loads((base/"manifest.json").read_text())
files = {str(f.relative_to(base)): {"contents": f.read_text().split("\n"), "executable": False}
         for f in sorted(base.rglob("*")) if f.is_file() and f.name != "manifest.json"}
payload = {"manifest": manifest, "files": files}
ref = hashlib.sha1(json.dumps(payload, sort_keys=True).encode()).hexdigest()
pathlib.Path("recipes/aleblanc.logui.0.1.json").write_text(
    json.dumps({"manifests": {"aleblanc/logui": {**payload, "ref": ref}}}, indent=2) + "\n")
print("compiled ->", ref)
PY
```

### ⚠️ Requires a real version tag
Verified working with Flex 2.11 — **but the package must resolve to a real semver version** that
matches the recipe's `0.1` (e.g. tag **`v0.1.0`**). With `dev-main`, Flex's version match skips the
recipe. So: tag and push, then in the app `composer require aleblanc/logui:^0.1`.

### Alternative: env var instead of composer.json
```bash
SYMFONY_ENDPOINT=https://api.github.com/repos/aleblanc/logui/contents/index.json?ref=main composer require aleblanc/logui
```

---

## B. Public, zero-config for everyone (recommended long-term)

Open a PR adding `aleblanc/logui/0.1/` to
[`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib) (possible once the package is
on Packagist). Then **any** app gets auto-configuration on `composer require`, no endpoint setup.
This repo's `recipes/aleblanc/logui/0.1/` is exactly the layout that PR expects.

---

## C. Without any recipe

Three manual steps (≈30 s) — see the project README's "Install (Symfony)".

## Limitation
A recipe can't patch an existing `config/packages/monolog.yaml`, so the `logui` Monolog handler is
added manually (the recipe's post-install message reminds you). To remove that last manual step, the
bundle could auto-attach its handler via a compiler pass.
