# Bootstrap — GitHub OIDC and deploy roles

Provisions the identity GitHub Actions uses to deploy everything else:

- an IAM **OIDC identity provider** for `token.actions.githubusercontent.com`
- one **deploy role per GitHub Environment**, each pinned to this repository

## Why this is a separate Pulumi project

The main stack (`../`) is deployed *by* the role this project creates, so this one
cannot be deployed by CI — that would be circular. But the more important reason is
security: **a deploy role that can edit its own trust policy is a privilege
escalation path.** If CI could rewrite it, a compromised workflow could grant any
GitHub repository access to the AWS account.

So the deploy role's policy contains an explicit `Deny` on modifying itself or the
OIDC provider (`src/deployPolicy.ts`), and changes here require running locally with
admin credentials. That is the intended friction — it should be rare.

## Run it (once, locally)

Use an IAM Identity Center session, not root:

```bash
aws sso login --profile qompose-admin
export AWS_PROFILE=qompose-admin

cd apps/platform/infra/bootstrap
npm ci
pulumi stack init bootstrap
pulumi config set githubRepository <owner>/<repo>
pulumi config set aws:region eu-west-1
npm run preview     # review, then:
npm run up
```

### If the account already has a GitHub OIDC provider

AWS allows only one provider per issuer URL, so a hand-wired account will fail with
`EntityAlreadyExists`. Point at the existing one instead of creating a second:

```bash
aws iam list-open-id-connect-providers
pulumi config set existingOidcProviderArn arn:aws:iam::<account>:oidc-provider/token.actions.githubusercontent.com
```

### More environments

```bash
pulumi config set --path environments[0] production
pulumi config set --path environments[1] staging
```

Each gets its own role, so a staging deploy cannot touch production.

## Wire up GitHub

```bash
pulumi stack output deployRoleArns
```

Then, per environment (Settings → Environments → *name*):

| Kind | Name | Value |
|---|---|---|
| Variable | `AWS_DEPLOY_ROLE_ARN` | the ARN for that environment |
| Variable | `AWS_REGION` | optional; defaults to `eu-west-1` |
| Variable | `ALARM_EMAIL_ADDRESS` | optional DLQ alarm recipient |
| Secret | `PULUMI_ACCESS_TOKEN` | may live at repository level — one Pulumi account covers all stacks |

Same variable *name* in every environment, different value — `deploy.yml` needs no
changes to target either one.

Worth enabling on the `production` environment: **required reviewers**. `pulumi up`
against a stack that later owns a database can destroy it, and an approval click is
cheap insurance.

## The `sub` claim trap

Most tutorials pin the trust policy to `repo:owner/repo:ref:refs/heads/main`. That is
**wrong here.** When a workflow job declares `environment:` — as `deploy.yml` does —
GitHub replaces the ref portion of the subject with the environment:

```
repo:<owner>/<repo>:environment:production
```

Pinning the ref form fails with `Not authorized to perform
sts:AssumeRoleWithWebIdentity` and no hint as to why. `src/trust.ts` builds the
correct form, and `src/trust.test.ts` asserts it.

The other half matters more: **the `sub` condition must never be omitted or
wildcarded.** A trust policy that only checks `aud` can be assumed by *any*
repository on GitHub. `assertPinnedRepository` throws during `pulumi preview` rather
than letting that ship, and `src/stack.test.ts` asserts the condition uses
`StringEquals` rather than `StringLike`.

To debug a failed assume, compare what the role accepts against what the token sends:

```bash
pulumi stack output acceptedSubjectClaims
```

## Outputs

| Output | Purpose |
|---|---|
| `githubOidcProviderArn` | The identity provider (created or reused) |
| `deployRoleArns` | environment → role ARN, for `AWS_DEPLOY_ROLE_ARN` |
| `acceptedSubjectClaims` | environment → exact `sub` the trust policy accepts |

## Tests

```bash
npm test          # builds, then node --test over bin/*.test.js
```

Covers the subject-claim form, wildcard/malformed repository rejection, and — via
Pulumi resource mocks — that the generated trust policy pins `sub` with
`StringEquals` and that the deploy policy denies self-modification.
