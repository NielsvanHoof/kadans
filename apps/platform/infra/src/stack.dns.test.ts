import assert from "node:assert/strict";
import test, { before } from "node:test";
import type { CapturedStack} from "./testing/harness.js";
import { runStack } from "./testing/harness.js";

/*
 * Production again, this time with a Route53 zone — so SES DKIM records are
 * written rather than only exported.
 *
 * Its own file for the usual reason: the Pulumi mock runtime is process-global
 * and index.js is module-cached.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("production", {
    "qompose-platform:hostedZoneId": "Z0123456789ABCDEFGHIJ",
  });
});

test("with a hosted zone, SES DKIM records are written in Route53", () => {
  const dkimRecords = stack.ofType("aws:route53/record:Record")
    .filter((record) => String(record.name).includes("mail-dkim"));

  assert.equal(dkimRecords.length, 3);

  for (const record of dkimRecords) {
    assert.equal(record.inputs.type, "CNAME");
  }
});

test("nothing is written for www, the apex, or compute hostnames", () => {
  const names = stack.ofType("aws:route53/record:Record")
    .map((record) => String(record.inputs.name));

  for (const reserved of ["www.qompose.nl", "qompose.nl", "app.qompose.nl"]) {
    assert.equal(
      names.includes(reserved),
      false,
      `must not write ${reserved}`,
    );
  }
});

test("no ALB or CloudFront alias records exist", () => {
  const aliases = stack.ofType("aws:route53/record:Record")
    .filter((record) => Array.isArray(record.inputs.aliases) && (record.inputs.aliases as unknown[]).length > 0);

  assert.equal(aliases.length, 0);
});
