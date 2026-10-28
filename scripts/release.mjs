import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

function parts(version) {
  if (!/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(version)) throw new Error(`Invalid release VERSION: ${version}`);
  const result = version.split('.').map(Number);
  if (result.some(part => !Number.isSafeInteger(part))) throw new Error(`Invalid release VERSION: ${version}`);
  return result;
}

export function releasePlan({ version, sha, mainSha, run, repository, tagExists, releaseExists, versions = [] }) {
  if (run?.event !== 'push' || run.head_branch !== 'main' || run.conclusion !== 'success' || run.head_sha !== sha || run.head_repository?.full_name !== repository) {
    return { action: 'skip', reason: 'Release requires successful Tests for this repository and exact main commit.' };
  }
  if (sha !== mainSha) return { action: 'skip', reason: 'A newer main commit exists; its Tests run will publish.' };
  const current = parts(version);
  const tag = `v${version}`;
  if (tagExists) return { action: releaseExists ? 'skip' : 'release', tag, reason: 'Version tag already exists and will not be moved.' };
  for (const previous of versions) {
    const old = parts(previous);
    const difference = current.map((part, index) => part - old[index]).find(part => part !== 0) ?? 0;
    if (difference <= 0) throw new Error(`Release ${version} must be newer than existing version ${previous}`);
  }
  return { action: 'tag-and-release', tag };
}

function git(...args) {
  return execFileSync('git', args, { encoding: 'utf8' }).trim();
}

export function publish() {
  if (process.env.GITHUB_ACTIONS !== 'true' || process.env.GITHUB_EVENT_NAME !== 'workflow_run') {
    throw new Error('This publisher runs only after the GitHub Tests workflow.');
  }
  const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
  const repository = process.env.GITHUB_REPOSITORY;
  if (repository !== 'IsaacHatilima/laracanon') throw new Error('Unexpected release repository');
  const version = readFileSync('VERSION', 'utf8').trim();
  parts(version);
  const tag = `v${version}`;
  const tags = git('tag', '--list').split('\n');
  const tagExists = tags.includes(tag);
  const releaseExists = tagExists && spawnSync('gh', ['release', 'view', tag, '--repo', repository], { stdio: 'ignore' }).status === 0;
  const plan = releasePlan({
    version,
    sha: git('rev-parse', 'HEAD'),
    mainSha: git('ls-remote', 'origin', 'refs/heads/main').split(/\s+/)[0],
    run: event.workflow_run,
    repository,
    tagExists,
    releaseExists,
    versions: tags.filter(tag => /^v(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/.test(tag)).map(tag => tag.slice(1)),
  });
  if (plan.action === 'skip') {
    console.log(plan.reason);
    return;
  }
  if (plan.action === 'tag-and-release') {
    git('tag', plan.tag);
    git('push', 'origin', `refs/tags/${plan.tag}`);
  }
  execFileSync('gh', ['release', 'create', plan.tag, '--repo', repository, '--verify-tag', '--title', plan.tag, '--generate-notes'], { stdio: 'inherit' });
  console.log(`Released ${plan.tag}; the configured Packagist hook indexes the new tag.`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) publish();
