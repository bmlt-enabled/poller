# Contributing to Poller

## How to Contribute

To contribute to Poller:

1. Fork the repository
2. Make your changes
3. Send a pull request to the main branch

Take a look at the [issues](https://github.com/bmlt-enabled/poller/issues) for bugs that you might be able to help fix.

Once your pull request is merged, it will be released in the next version.

## Local Development Setup

To get things going in your local environment:

1. Run the following command to start the development environment:
   ```bash
   docker compose up
   ```

2. Set up your WordPress installation and remember your admin password.

3. Once it's running, log in to the admin panel and activate the "Poller" plugin.

4. You can now make edits to the plugin and changes will take effect instantly.

The container mounts the parent directory as `wp-content/plugins`, so sibling plugin
repos are available too. WordPress runs on `localhost:8080` (HTTP) and `localhost:7443`
(HTTPS). Xdebug is pre-configured on port 9003.

## Code Standards

Please make note of the `.editorconfig` file and adhere to it, as this will minimize
formatting errors. If you are using PHPStorm, you will need to install the EditorConfig
plugin.

### PHP Code Style

This project uses PHP CodeSniffer (phpcs) with the WordPress-Core standard for code
style enforcement. The coding standards are configured in `.phpcs.xml`.

To check your code for style violations:
```bash
make lint
```

To automatically fix code style issues:
```bash
make fmt
```

Make sure to run these commands before submitting your pull request to ensure your
code adheres to the project's coding standards.

## Testing

This project uses [PHPUnit](https://phpunit.de/) with the
[WordPress test suite](https://github.com/wp-phpunit/wp-phpunit) for integration
testing. Tests run inside Docker containers so you don't need PHP or MySQL installed
locally.

### Running Tests Locally

```bash
make test
```

This spins up a MariaDB container, installs WordPress core, and runs the full test
suite. Containers are cleaned up automatically afterward.

To remove test images and volumes:
```bash
make test-clean
```

You can also preview the public pages without WordPress at all:

```bash
php -S 127.0.0.1:8899 -t . bin/preview.php
```

### Writing Tests

Test files live in the `tests/` directory. Add new test files with a `test-` prefix
(e.g., `tests/test-feature.php`). Tests extend `WP_UnitTestCase`, which gives you a
full WordPress environment with database access.

```php
use BmltEnabled\Poller\Codes;

class Test_My_Feature extends WP_UnitTestCase {

	public function test_something() {
		$this->assertTrue( Codes::is_valid( Codes::generate() ) );
	}
}
```

### CI

Lint and tests run automatically on every pull request and push to `main`. Both must
pass before a build or release is created.

## Building

Create an installable zip from the current commit:

```bash
make build
```

This writes `build/poller.zip` with a top-level `poller/` folder, ready to upload from
**Plugins → Add New → Upload Plugin**. Development files (tests, Docker, CI, and tooling)
are kept out of the zip via `.gitattributes`.

## Release Tagging

Pushing a version tag such as `0.1.0` runs lint and tests, builds `poller-<tag>.zip`, and
publishes a GitHub release. The tag should match a heading in the `readme.txt` changelog.

- If a release is tagged with `beta`, it is published as a GitHub pre-release.
- Otherwise it is published as a normal GitHub release.
