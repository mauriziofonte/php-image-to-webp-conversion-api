# Contributing

Thank you for helping improve the Image Conversion API. This guide describes how to set up a development environment, which checks a change must pass, and how to write commits and pull requests.

For a security vulnerability, do not open an issue or a pull request: follow [SECURITY.md](SECURITY.md).

## Development setup

You need PHP 7.2.5 or later (the development tools need PHP 7.4 or later, and Psalm PHP 8.1 or later), Composer 2, Git, and Docker for the integration tests. The tests that convert real images need the `gd` extension to draw their inputs and a backend that writes WEBP, such as `webp` (cwebp) or libvips; without them they are skipped.

```bash
git clone https://github.com/mauriziofonte/php-image-to-webp-conversion-api.git
cd php-image-to-webp-conversion-api
composer install
for tool in tools/*/; do composer install --working-dir="$tool"; done
```

The conversion library, mfonte/secure-image-converter, is installed from its Git repository, declared in `composer.json` as a VCS repository. No lock file is committed: Composer resolves the dependencies for the PHP version that runs it. The security corpus tests read the library's test fixtures, which its distribution archive leaves out; install it from source to run them:

```bash
composer reinstall mfonte/secure-image-converter --prefer-install=source
```

The development tools live in `tools/<name>/`, each with its own `composer.json`, so that they do not constrain the dependencies of the service on PHP 7.2.

## Checks

These are the commands that CI runs. Run them before opening a pull request.

| Command | What it does |
| --- | --- |
| `composer validate --strict` | Validates `composer.json` (and, in CI, each `tools/*/composer.json`). |
| `composer test` | PHPUnit: the `unit`, `functional` and `integration` suites. |
| `composer cs:check` | php-cs-fixer in dry-run mode: PSR-12 plus the project rules in `.php-cs-fixer.dist.php`. `composer cs:fix` applies the fixes. |
| `composer analyse` | PHPStan at level max, as PHP 7.2.5 (`phpstan.neon.dist`) and as PHP 8.5 (`phpstan-php85.neon.dist`). |
| `composer taint` | Psalm taint analysis of `src/`. |
| `composer audit` | Composer's security advisories for the resolved dependencies. |
| `composer ci` | `cs:check`, `analyse` and `test`, in this order. |

CI also lints every PHP file with each PHP version from 7.2 to 8.5, runs the test suite on each of them with the lowest and the highest dependency versions, and runs the integration harness.

### Integration tests

The `integration` suite is skipped unless the Docker harness starts the web servers:

```bash
tests/Integration/docker/run.sh --php 8.5 --mode fpm     # Apache + PHP-FPM and nginx + PHP-FPM, official images
tests/Integration/docker/run.sh --php 8.5 --mode image   # docker/Dockerfile behind nginx, with real conversions
```

`--mode all` runs both, and `--keep` leaves the containers in place. The harness mounts `vendor/` from the checkout, so install it, with the development dependencies, for a version that runs on the `--php` version. The header of `run.sh` lists its environment variables.

### Compatibility rules

- The code must run on PHP 7.2.5 to 8.5: no syntax newer than PHP 7.2, and no deprecation on PHP 8.5. The test bootstrap turns notices, warnings and deprecations into failures.
- PHPUnit 8.5 to 11.5 must all run the suite: tests extend the Yoast PHPUnit polyfills test case and declare both annotations and attributes.
- Error bodies never echo request data, and the log never contains an API key.
- A change to the HTTP contract (routes, parameters, headers, error codes and statuses, the `/healthz` schema, metric names, log fields, configuration keys) needs a matching change to the README, `.env.example` where it applies, and `CHANGELOG.md`.

## Commits

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```text
<type>(<scope>): <summary in the imperative, lower case>

<body: why the change is needed, wrapped at 72 characters>
```

- Types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`.
- Scopes name the area: for example `http`, `auth`, `config`, `limits`, `v1`, `health`, `metrics`, `log`, `console`, `docker`, `apache`, `deps`.
- Keep the subject at 72 characters or fewer and the body lines at 72 or fewer.
- One logical change per commit; every commit leaves the test suite green.
- Mark a breaking change with `!` after the type or scope, and explain it in a `BREAKING CHANGE:` footer.

## Pull requests

- Branch from the default branch and keep the pull request focused on one change.
- Add or update tests for every behavior change.
- Update the documentation and the `Unreleased` section of `CHANGELOG.md`.
- Make sure every check above passes.

## License

By contributing, you agree that your contributions are licensed under the [BSD 3-Clause License](LICENSE) of the project.
