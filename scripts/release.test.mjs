import assert from 'node:assert/strict';
import test from 'node:test';
import { releasePlan } from './release.mjs';

const repository = 'IsaacHatilima/laracanon';
const sha = 'a'.repeat(40);
function input(overrides = {}) {
  return {
    version: '0.1.3', sha, mainSha: sha, repository,
    run: { event: 'push', head_branch: 'main', conclusion: 'success', head_sha: sha, head_repository: { full_name: repository } },
    tagExists: false, releaseExists: false, versions: ['0.1.0', '0.1.1', '0.1.2'],
    ...overrides,
  };
}

test('a new version is released only after its exact main commit passes Tests', () => {
  assert.deepEqual(releasePlan(input()), { action: 'tag-and-release', tag: 'v0.1.3' });
});

test('failed, unrelated, PR, and foreign-repository runs cannot publish', () => {
  for (const change of [
    { conclusion: 'failure' }, { event: 'pull_request' }, { head_branch: 'feature' },
    { head_sha: 'b'.repeat(40) }, { head_repository: { full_name: 'someone/fork' } },
  ]) {
    assert.equal(releasePlan(input({ run: { ...input().run, ...change } })).action, 'skip');
  }
});

test('a delayed run cannot publish ahead of a newer main commit', () => {
  assert.equal(releasePlan(input({ mainSha: 'b'.repeat(40) })).action, 'skip');
});

test('retrying a completed release makes no changes to its immutable tag', () => {
  assert.equal(releasePlan(input({ tagExists: true, releaseExists: true })).action, 'skip');
});

test('a retry after the tag push can finish creating the GitHub release', () => {
  assert.equal(releasePlan(input({ tagExists: true, releaseExists: false })).action, 'release');
});

test('invalid or older release versions cannot create tags', () => {
  for (const version of ['0.1', '0.1.3-beta', '01.1.3', '0.1.2', '0.0.9']) {
    assert.throws(() => releasePlan(input({ version })), /Invalid release VERSION|must be newer/);
  }
});
