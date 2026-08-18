import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, runStack } from "./testing/harness.js";

/*
 * The production stack again, this time with a Route53 zone.
 *
 * Its own file for the usual reason — the Pulumi mock runtime is process-global and
 * index.js is module-cached, so a second configuration cannot share a process with
 * the first. It earns the third fork because without `hostedZoneId` the entire
 * Route53 branch of dns.ts never runs, which made the "marketing keeps www"
 * assertion in stack.production.test.ts pass against zero records.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("production", {
    "qompose-platform:hostedZoneId": "Z0123456789ABCDEFGHIJ",
  });
});

test("the app hostname and the tenant wildcard both point at the edge", () => {
  for (const component of ["app-record", "tenants-record"]) {
    const record = stack.named(component);

    assert.ok(record, `expected a ${component}`);
    assert.equal(record.inputs.type, "A");

    const aliases = record.inputs.aliases as Array<Record<string, unknown>>;

    assert.equal(aliases.length, 1, "records must be aliases, not CNAMEs");
    // CloudFront has no health to evaluate; an alias to it must say so, and an
    // alias that claims otherwise is rejected at apply time.
    assert.equal(aliases[0].evaluateTargetHealth, false);
  }

  assert.equal(stack.named("app-record")?.inputs.name, "app.qompose.nl");
  assert.equal(stack.named("tenants-record")?.inputs.name, "*.qompose.nl");

  /*
   * The origin must not keep a name of its own. Production restricts the ALB to
   * CloudFront's ranges and the shared header, so a leftover alias would resolve
   * to something that refuses it — and if that restriction were ever relaxed, the
   * record would quietly become a way around the edge.
   */
  const albAlias = stack.ofType("aws:route53/record:Record").find((record) =>
    ((record.inputs.aliases ?? []) as Array<Record<string, any>>)
      .some((alias) => String(alias.name).includes("elb.amazonaws.com"))
  );

  assert.equal(albAlias, undefined, "no DNS record may point straight at the load balancer");
});

/*
 * The edge certificate lives in us-east-1 and is a different certificate, but it
 * covers the same two names — so ACM asks for the same validation records, and
 * writing them twice is a Route53 conflict rather than a second confirmation.
 */
test("both certificates validate against one set of records", () => {
  const validations = stack.ofType("aws:acm/certificateValidation:CertificateValidation");

  assert.equal(validations.length, 2, "regional and edge certificates both validate");

  const records = stack.ofType("aws:route53/record:Record")
    .filter((record) => record.name.includes("certificate-validation"));

  assert.equal(records.length, 2, "one record per certificate name, not per certificate");
});

/*
 * The claim this stack rests on: a wildcard only answers a name with no explicit
 * record of its own, so `www` and the apex keep winning at the marketing host. That
 * is only true while this stack never writes those names — which is what this
 * asserts, now that records actually exist to check.
 */
test("nothing is written for www, the apex, or any reserved system host", () => {
  const records = stack.ofType("aws:route53/record:Record")
    .map((record) => String(record.inputs.name));

  assert.ok(records.length > 0, "expected the Route53 branch to have run");

  for (const reserved of ["www.qompose.nl", "qompose.nl", "mail.qompose.nl", "api.qompose.nl"]) {
    assert.equal(records.includes(reserved), false, `stack must not claim ${reserved}`);
  }
});

/*
 * Created at the top level rather than inside an `apply`, so they show up in
 * `pulumi preview` — which matters most for the resources that gate TLS.
 */
test("certificate validation records are declared, not hidden in an apply", () => {
  assert.ok(stack.named("certificate-validation-0"));
  assert.ok(stack.named("certificate-validation-1"));
  assert.ok(stack.hasType("aws:acm/certificateValidation:CertificateValidation"));

  // Both names can resolve to the same validation record when the app hostname is
  // the apex the wildcard covers; identical contents, so overwrite is a no-op.
  assert.equal(stack.named("certificate-validation-0")?.inputs.allowOverwrite, true);

  // The validation resource must wait on the records, or ACM is asked to confirm a
  // name that has not been published yet and the deploy blocks until it times out.
  const validation = stack.named("certificate-validation");

  assert.ok(validation);
  assert.equal((validation.inputs.validationRecordFqdns as unknown[]).length, 2);
});

test("SES DKIM is published so mail from the domain aligns", () => {
  const dkim = stack.ofType("aws:route53/record:Record")
    .filter((record) => record.name.includes("mail-dkim"));

  assert.equal(dkim.length, 3, "SES issues exactly three DKIM tokens");

  for (const record of dkim) {
    assert.equal(record.inputs.type, "CNAME");
  }
});
