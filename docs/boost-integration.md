# Laravel Boost integration

The adapter was verified on 2 October 2026 against [Laravel's official Boost documentation](https://laravel.com/docs/13.x/boost) and the official [Laravel Boost v2.10.1 source](https://github.com/laravel/boost/tree/v2.10.1), commit `3e7bab5`. The package does not hard-code that release for applications: Composer resolves a compatible stable release without forcing existing Boost, Laravel, or PHP constraints to change. The adapter checks for the required rule APIs before changing Boost outputs and reports an unsupported installed release clearly.

## Verified mechanisms

- [ThirdPartyPackage](https://github.com/laravel/boost/blob/v2.10.1/src/Install/ThirdPartyPackage.php) discovers `resources/boost/guidelines` and `resources/boost/skills` from direct Composer and JavaScript dependencies. Transitive packages cannot inject guidance through this mechanism.
- [GuidelineComposer](https://github.com/laravel/boost/blob/v2.10.1/src/Install/GuidelineComposer.php) resolves built-in guidance, dependency guidance, and project `.ai/guidelines` overrides. Third-party inclusion is filtered by selected package names in `GuidelineConfig`.
- [SkillComposer](https://github.com/laravel/boost/blob/v2.10.1/src/Install/SkillComposer.php) discovers package workflows and `.ai/skills/{name}/SKILL.md` project workflows. A project skill with the same name overrides the dependency skill. Laracanon reserves package skill names before creating item skills and requires the item's explicit override flag for that collision.
- [RuleRepository](https://github.com/laravel/boost/blob/v2.10.1/src/Rules/RuleRepository.php) generates `.ai/rules/index.md` from top-level `.ai/rules/*.md` and `.ai/rules/boost/*.md`. It does **not** recurse into arbitrary rule subdirectories. Laracanon therefore stores item rules at `.ai/rules/laracanon-{item}.md`, with `paths` frontmatter.
- [RuleComposer](https://github.com/laravel/boost/blob/v2.10.1/src/Install/RuleComposer.php) extracts explicitly scoped package guidelines when the application's `boost.rules.scoped_guidelines` option is enabled. Laracanon preserves that preference. When it is disabled, package guidance stays inline.
- [GuidelineWriter](https://github.com/laravel/boost/blob/v2.10.1/src/Install/GuidelineWriter.php) replaces only its `<laravel-boost-guidelines>` marker block in agent instructions. The surrounding project text is preserved.
- [SkillWriter](https://github.com/laravel/boost/blob/v2.10.1/src/Install/SkillWriter.php) renders package Blade skill files and Markdown. Laracanon uses its rendering implementation in staging, then applies each output through ownership checks rather than calling its destructive directory synchronization.

`boost:update` alone is insufficient for this installer. Its package discovery prompts are skipped in noninteractive runs, and its ordinary rule path does not guarantee rebuilding an index after manually adding a rule. Laracanon explicitly opts selected item dependencies into the existing package selection and calls native `RuleRepository::writeIndex()` after item rules are installed.

## Fresh application process

The parent Artisan process already loaded the target application's provider list and package registry. A Composer installation can add providers after that boot. Laracanon starts `resources/boost-refresh.php` as a separate PHP process, loads the target's Composer autoloader (including a custom `vendor-dir`), bootstraps its console kernel, and refreshes Roster before composing resources. External dependency installation remains a separate, mockable operation.

With no `boost.json`, configuration defaults to Codex, guidelines, package skills, and the local Boost MCP server. It does not opt into Cloud or Nightwatch integrations. Existing agent selections, feature preferences, unrelated JSON fields, project rules, skills, and Composer scripts are preserved. Newly selected item dependencies with native Boost resources are added to the saved package opt-in list. Other previously excluded package choices are preserved.

Native file-based MCP writers add a missing server without discarding unrelated server settings. Existing `laravel-boost` server definitions are preserved. Shell-based agent registration and Sail server configuration are reported as requiring the agent's normal `boost:install` flow; Laracanon does not execute external agent commands silently.

## Ownership and conflicts

Derived outputs have a separate hash ledger at `.ai/laracanon/boost-state.json`. Only unchanged managed files are replaced. Guideline ownership covers the Boost marker alone, so editing surrounding `AGENTS.md` text does not block refresh. Package skill files, scoped rule files, and the generated index are hash-checked; unrelated files within skill directories are retained.

When an authored workflow is retired, its unchanged managed agent copies are retired too. Edited retired workflows remain available and produce conflicts. Malformed or unrenderable skill sources block retirement so a temporary parse failure cannot delete previously working skills. Ownership ledger paths and hashes are validated before Boost outputs change.

An existing native rule index can be verified against the native index renderer before the new Laracanon rules are included. That baseline allows ordinary existing indexes to gain new rule rows safely. An edited or unverifiable index is preserved and reported as a conflict; that installation's new rules may remain undiscoverable until the conflict is resolved.

An existing Boost guideline block can be adopted when it matches the desired output or an exact fresh native rendering of the saved package selection and its previous test, skill, and MCP features. This permits standard Boost guidance to gain newly selected package resources without treating it as a user edit. An explicit `boost.enforce_tests` preference is honored; otherwise the existing native test-enforcement section is preserved. Several agents sharing one guideline destination are refreshed once.

Composer scripts can run native `boost:update` outside Laracanon's hash ledger. If that command changes a managed block, the same exact native verification allows its hash to be reconciled safely. A user-edited block or an older generated block that cannot be reproduced remains unchanged and produces a conflict. Text outside the marker remains project-owned and is preserved. Dependency skill files with an unverifiable differing baseline are also preserved. Review the conflict, keep project preferences in project rules or outside the generated block, and remove or relocate only the output you want Laracanon to regenerate before retrying.

Scoped rule conflicts preserve the edited rule and keep current package guidance inline. Rendering and skill-frontmatter failures are included in the report while successfully rendered resources still complete. A dry run starts no refresh process and writes no configuration, index, rules, skill outputs, or ledger.

## Validation boundary

Integration tests exercise the real installed Boost composers, rule repository, guideline writer, and skill renderer against temporary Laravel/Testbench applications. They cover native index extension, selected package discovery, custom skill publication, MCP preservation, ownership, user edits, idempotency, and partial rendering failures. The tests mock external Composer execution elsewhere in the suite. Other supported PHP/Laravel combinations are configured in the GitHub Actions matrix; local validation does not establish their results. The package has not been published to Packagist.
