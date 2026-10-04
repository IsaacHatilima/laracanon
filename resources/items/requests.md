---
name: requests
description: 'FormRequest authorization and validation with an authored workflow for typed input boundaries.'
paths:
  - 'app/Http/Requests/**'
---

## Rules

- Every HTTP endpoint accepting user input, including GET filters or pagination, uses a FormRequest in `app/Http/Requests/{Domain}/`. Use operation-specific names; share a `Save{Noun}Request` only when create and update have the same authorization and validation contract, and use `List{Nouns}Request` for list filters.
- FormRequests authorize and validate. Explicit Data `fromRequest()` factories build and normalize input after those checks. Do not clean domain values, construct Data, query directly, write, or build responses in a FormRequest.
- `authorize()` delegates to the appropriate policy through Gate, using the model class for creation or the route-bound model for an existing entity. The route owns parent-scoped model binding.
- Declare permitted payload and query fields with explicit rule arrays. Reuse validation traits when rules are genuinely shared; a one-off request does not need a trait. Additional cross-field checks belong in `after()` callbacks, with database lookups delegated to injected actions. Standard framework `exists`/`unique` rules remain allowed.
- Validation messages and attribute labels are translated. Laravel's validation and authorization failures flow through the application's web or centralized API exception handling, rather than custom response logic in each request.

Use the `laracanon-requests` skill for the validation workflow. The Data skill contains the shared `CreateUserRequest` example.

## Skill

Use this **Laracanon-authored workflow** to implement or refactor the HTTP input boundary. It adds no package dependencies and keeps input normalization in the application's Data classes.

1. Inspect the endpoint, caller, policy, route bindings, schema, and existing input Data. List permitted payload fields and user-supplied query parameters, including filters and pagination. Choose an operation-specific FormRequest name; share a request only when the contracts are identical. Use the existing `CreateUserRequest` → `CreateUserData` flow from `laracanon-data` when reviewing that example rather than introducing a separate flow.
2. Implement `authorize()` using the application's policy and `Gate::allows()`. Pass the model class for a create ability or the appropriate model from `$this->route()` for an existing entity. Obtain the authenticated actor through the framework boundary as needed. Keep custom database lookups in an injected action, and configure parent-scoped binding on the route instead of querying membership here.
3. Define explicit `rules()` arrays for known fields, types, limits, allowed values, and nested array keys. Choose `required`, `nullable`, and `sometimes` according to the contract so omission and explicit null have the intended meaning. Cover both payload and query input. Keep one-off rules local; extract `app/Concerns/*ValidationRules` traits only for an actual shared rule set. Framework `exists`/`unique` rules are permitted; for updates, derive any ignored model from trusted route binding rather than a submitted ID.
4. Add `after()` only for checks that need it. Return callbacks accepting `Illuminate\Validation\Validator`; Laravel invokes them after the initial rules, including when those rules failed. Guard the prerequisite fields with the validator's error bag before reading typed values or invoking an injected lookup action, then add translated messages to the relevant field. Resolve the action through the `after()` method's typed parameters and invoke it inside the returned callback, not while constructing the callback list. Do not call `validated()` or construct Data from inside an after callback. Checks that depend on Data's normalized values belong in the consuming action, as the shared CreateUser email check does. Consult Laravel's [FormRequest validation guidance](https://laravel.com/docs/13.x/validation#form-request-validation) for the installed version.
5. Translate any custom `messages()` and `attributes()` values with `__()`. Keep Laravel's native authorization/validation exceptions and existing web error bags. For APIs, let the centralized `laracanon-api-responses` workflow produce the shared error envelope, including failures without a JSON Accept header; do not override request failure methods solely to format an envelope.
6. Let the controller invoke the explicit Data `fromRequest()` factory after authorization and validation. The factory reads only known validated fields, uses typed getters, and performs domain normalization; request hooks must not duplicate trimming, case conversion, or persistence conversions. Use `laracanon-data` and `laracanon-controllers` for those adjacent boundaries.
7. Verify authorized and forbidden callers, required/type/range failures, query input, nested keys, omitted versus null values, translated field errors, and ignored or rejected extra fields according to the existing contract. Where an after callback exists, check that invalid prerequisites do not trigger its lookup and that its business failure names the expected field. Verify web redirect/error-bag behavior and the API 403/422 envelope where applicable, then run the application's relevant existing checks.
