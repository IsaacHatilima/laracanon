---
name: plans
description: 'Concise implementation contracts and an authored workflow for sequencing and validating changes.'
paths:
  - 'docs/superpowers/plans/**'
---

## Rules

- Plans in `docs/superpowers/plans/` define what changes, its contracts, and how completion is verified. Implement against the live codebase; do not paste full classes, tests, factories, components, or other implementation boilerplate into a plan.
- Each task states its goal, affected files, input/output interfaces, acceptance behaviors, dependencies and order, material risks, and validation commands. Distinguish verified current behavior from proposed behavior and unresolved decisions.
- Include the smallest code snippet needed to make a decision or contract unambiguous. Short snippets may show action signatures, request/response shapes, schema constraints, or a tricky query that needs precise review. Plans do not replace implementation or runnable tests.
- Keep plans concise and link design decisions under `docs/superpowers/specs/` and conventions under `.ai/rules/` rather than restating them. Limit the scope to the requested outcome and related work necessary to achieve it.

Use the `laracanon-plans` skill to create or revise an implementation plan.

## Skill

Use this **Laracanon-authored workflow** when a change needs an implementation plan. It adds no packages and produces a reviewable set of contracts and validation steps, not a second copy of the implementation.

1. Inspect the live paths, entry points, existing contracts, repository instructions, and relevant specs/rules. Record the concrete requested outcome and what current evidence establishes. Resolve routine implementation choices from that context; label decisions that still need input rather than treating assumptions as agreed requirements.
2. Describe the observable change and its boundaries. Identify request fields, authorization, Data factories, action signatures and returns, Resource fields, routes, API envelope or web response, and page props only where they change. For schema work, specify columns, constraints, indexes, and delete behavior. Link the relevant design or item workflow instead of copying its instructions.
3. Divide the work into ordered tasks with a goal and affected files for each. State dependencies, the interfaces a later task consumes, and any migration or deployment ordering needed by this change. Include the smallest code snippet needed to make a decision or contract unambiguous, such as an action signature, request/response shape, schema constraint, or tricky query. Keep snippets focused on the reviewed contract; write full classes, components, and tests during implementation.
4. Write acceptance checks as observable behaviors: permitted and forbidden callers, expected writes or reads, error/status/output shape, and repeat or failure behavior where relevant. Identify material risks such as compatibility, data migration, concurrent writes, or external delivery, and pair each with the check or recovery decision needed for the proposed change.
5. List the focused tests and existing validation commands that will establish those behaviors. State fixtures, mocks, environments, or external evidence required, and what those checks cannot prove. Do not claim proposed commands have already passed. Use `laracanon-tests` for test implementation details.
6. Review the plan for a coherent end-to-end path, missing dependencies, unresolved decisions, and duplicate or unrelated work. Remove full implementations and repeated conventions, retaining small snippets that clarify decisions or contracts. Keep the result short enough to review task by task. During implementation, update material contract changes and record actual validation results with any remaining gaps.
