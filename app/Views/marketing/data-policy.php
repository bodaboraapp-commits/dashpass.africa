<?php

declare(strict_types=1);

/**
 * Public Data Policy / Data Protection & Processing Policy (guest layout).
 *
 * Describes the platform's data governance in practice: controller/processor
 * roles, data inventory, lawful bases, retention schedule, security controls,
 * sub-processors, transfers, request handling, breach response and governance.
 *
 * Variables: $legalController, $legalEntity, $legalAddress, $privacyEmail,
 * $dpoEmail, $supportEmail, $policyVersion, $policyEffective, $responseDays,
 * $supervisoryAuthority, $supervisoryUrl, $governingLaw.
 */

$legalActive = 'data-policy';
$legalController = isset($legalController) ? (string) $legalController : 'DashPASS';
$legalEntity = isset($legalEntity) ? (string) $legalEntity : $legalController;
$legalAddress = isset($legalAddress) ? (string) $legalAddress : 'Nairobi, Kenya';
$privacyEmail = isset($privacyEmail) ? (string) $privacyEmail : 'privacy@dashpass.africa';
$dpoEmail = isset($dpoEmail) ? (string) $dpoEmail : $privacyEmail;
$supportEmail = isset($supportEmail) ? (string) $supportEmail : $privacyEmail;
$policyVersion = isset($policyVersion) ? (string) $policyVersion : '1.0';
$policyEffective = isset($policyEffective) ? (string) $policyEffective : '13 November 2026';
$responseDays = isset($responseDays) ? max(1, (int) $responseDays) : 30;
$supervisoryAuthority = isset($supervisoryAuthority)
    ? (string) $supervisoryAuthority
    : 'Office of the Data Protection Commissioner (ODPC), Kenya';
$supervisoryUrl = isset($supervisoryUrl) ? (string) $supervisoryUrl : 'https://www.odpc.go.ke';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="legal-page stack stack--loose">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <?= \App\Core\View::capture('marketing/_legal-nav', ['legalActive' => $legalActive]) ?>

  <header class="legal-header stack stack--tight">
    <p class="landing-hero__eyebrow"><?= $icon('shield', 'ico ico--sm') ?> LEGAL · DATA</p>
    <h1>Data Policy</h1>
    <p class="legal-meta">
      Data Protection &amp; Processing Policy · Version <?= e($policyVersion) ?> ·
      Effective <?= e($policyEffective) ?>
    </p>
    <p class="page-lede">
      This Data Policy explains, in practical terms, the data <?= e($legalController) ?> processes,
      who is responsible for it, how long it is kept, how it is secured, and how we handle requests
      from the people whose data we hold. It is the operational companion to our
      <a href="<?= e('/privacy') ?>">Privacy Policy</a> and
      <a href="<?= e('/terms') ?>">Terms &amp; Conditions</a>.
    </p>
  </header>

  <nav class="legal-toc" aria-label="On this page">
    <p class="legal-toc__title">On this page</p>
    <ol class="legal-toc__list">
      <li><a href="#purpose">Purpose and scope</a></li>
      <li><a href="#roles">Who is responsible</a></li>
      <li><a href="#inventory">Data inventory</a></li>
      <li><a href="#lawful-basis">Lawful basis and consent</a></li>
      <li><a href="#minimisation">Purpose limitation and minimisation</a></li>
      <li><a href="#accuracy">Accuracy and corrections</a></li>
      <li><a href="#retention">Retention schedule</a></li>
      <li><a href="#security">Security measures</a></li>
      <li><a href="#sub-processors">Sub-processors and recipients</a></li>
      <li><a href="#transfers">International transfers</a></li>
      <li><a href="#rights">Handling data-subject requests</a></li>
      <li><a href="#deletion">Deletion and account closure</a></li>
      <li><a href="#breach">Data breaches</a></li>
      <li><a href="#children">Children</a></li>
      <li><a href="#governance">Governance and review</a></li>
      <li><a href="#contact">Contact</a></li>
    </ol>
  </nav>

  <article class="legal-body stack">
    <section class="legal-section" id="purpose" aria-labelledby="purpose-title">
      <h2 id="purpose-title">1. Purpose and scope</h2>
      <p>
        This policy applies to all personal data processed through the <?= e($legalController) ?>
        platform: the public website, the resident and guard applications, the estate admin console,
        the platform console, support channels and the legal/privacy pages. It sets out how we meet
        the Kenya Data Protection Act, 2019 and how we support estates that are themselves data
        controllers.
      </p>
    </section>

    <section class="legal-section" id="roles" aria-labelledby="roles-title">
      <h2 id="roles-title">2. Who is responsible</h2>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Data controller and processor roles</caption>
          <thead>
            <tr><th scope="col">Activity</th><th scope="col">Controller</th><th scope="col">Processor</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Platform account, sign-in, website and app usage</td>
              <td><?= e($legalEntity) ?></td>
              <td>Service providers acting for us</td>
            </tr>
            <tr>
              <td>Households, residents, visitor invitations, gate access logs and estate incidents</td>
              <td>The estate</td>
              <td><?= e($legalEntity) ?>, on the estate's documented instructions</td>
            </tr>
            <tr>
              <td>Estate billing and payment reconciliation</td>
              <td>The estate</td>
              <td><?= e($legalEntity) ?> and the payment providers</td>
            </tr>
            <tr>
              <td>Platform operator and subscription data</td>
              <td><?= e($legalEntity) ?></td>
              <td>Service providers acting for us</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        Where we act as a processor we only process estate data to provide the platform, follow the
        estate's lawful instructions, and meet our legal obligations. If a request needs estate
        input (for example, a correction to a household record), we refer it to the estate and
        support the response.
      </p>
    </section>

    <section class="legal-section" id="inventory" aria-labelledby="inventory-title">
      <h2 id="inventory-title">3. Data inventory</h2>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Categories of personal data processed</caption>
          <thead>
            <tr><th scope="col">Category</th><th scope="col">Examples</th><th scope="col">Source</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Identity and account</td>
              <td>Name, phone, optional email, account status, last login</td>
              <td>Estate administrator; you</td>
            </tr>
            <tr>
              <td>Estate relationship</td>
              <td>Estate, building, household, role, membership status</td>
              <td>Estate administrator</td>
            </tr>
            <tr>
              <td>Visitors and access</td>
              <td>Visitor name and phone, vehicle plate, pass references, gate, check-in/out time, hosting household</td>
              <td>Resident invitations; guard check-in; walk-in requests</td>
            </tr>
            <tr>
              <td>Billing and payments</td>
              <td>Invoices, amounts, payment references and status</td>
              <td>Estate office; payment providers</td>
            </tr>
            <tr>
              <td>Notices, staff and incidents</td>
              <td>Notice content, household staff records, incident descriptions and evidence</td>
              <td>Estate administrators; residents; guards</td>
            </tr>
            <tr>
              <td>Communications</td>
              <td>Help messages, privacy requests, reference codes</td>
              <td>You</td>
            </tr>
            <tr>
              <td>Technical and security</td>
              <td>IP address, user agent, request metadata, security logs, device cache</td>
              <td>Automatically generated</td>
            </tr>
            <tr>
              <td>Preferences</td>
              <td>Optional-communication consent, notice version</td>
              <td>You</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        We do not intentionally process special-category data, biometric templates, precise
        location or advertising identifiers. Access PINs are kept only as keyed hashes and QR
        credentials are encrypted at rest; plaintext pass secrets are not retained.
      </p>
    </section>

    <section class="legal-section" id="lawful-basis" aria-labelledby="lawful-basis-title">
      <h2 id="lawful-basis-title">4. Lawful basis and consent</h2>
      <p>
        Each processing activity has a lawful basis under the Kenya Data Protection Act, 2019:
        performance of a contract, compliance with a legal obligation, protection of vital
        interests, performance of a public task, legitimate interests, or consent. The
        <a href="<?= e('/privacy') ?>">Privacy Policy</a> maps each purpose to its basis.
      </p>
      <p>
        We rely on <strong>consent</strong> only for optional communications (product updates,
        surveys and estate offers). Consent is recorded with the notice version the person was
        shown, and can be withdrawn at any time on the
        <a href="<?= e('/privacy/choices') ?>">privacy choices page</a>. Withdrawing consent does
        not affect processing that already happened, and it does not stop essential service
        messages (for example, security or billing notices you need).
      </p>
    </section>

    <section class="legal-section" id="minimisation" aria-labelledby="minimisation-title">
      <h2 id="minimisation-title">5. Purpose limitation and minimisation</h2>
      <ul class="legal-list">
        <li>We collect only what a feature needs, and ask for less where a field is optional.</li>
        <li>We use data for the purpose it was collected for, or a compatible purpose explained to you.</li>
        <li>We use aggregated, non-identifying information for reliability and security reporting.</li>
        <li>Reports available to estates show their own data only; cross-estate access is blocked and audited.</li>
      </ul>
    </section>

    <section class="legal-section" id="accuracy" aria-labelledby="accuracy-title">
      <h2 id="accuracy-title">6. Accuracy and corrections</h2>
      <p>
        Estate administrators are responsible for the accuracy of household, resident and billing
        records they create. You can ask us or your estate office to correct inaccurate or
        incomplete data, and we will correct platform-level records ourselves. A correction request
        can be submitted on the <a href="<?= e('/privacy/choices') ?>">privacy choices page</a>;
        where the record belongs to an estate, we work with the estate to update it.
      </p>
    </section>

    <section class="legal-section" id="retention" aria-labelledby="retention-title">
      <h2 id="retention-title">7. Retention schedule</h2>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Retention schedule</caption>
          <thead>
            <tr><th scope="col">Record</th><th scope="col">Retention period</th><th scope="col">Basis</th></tr>
          </thead>
          <tbody>
            <tr><td>Account and profile</td><td>Active + up to 30 days</td><td>Service delivery; deletion rights</td></tr>
            <tr><td>Estate and household membership</td><td>Active + up to 24 months</td><td>Dispute resolution; estate records</td></tr>
            <tr><td>Visitor, pass and gate logs</td><td>12 months</td><td>Security; incident response</td></tr>
            <tr><td>Security incidents</td><td>24 months after resolution</td><td>Safety; legal claims</td></tr>
            <tr><td>Invoices, payments and accounting records</td><td>At least 5 years after the reporting period</td><td>Tax and accounting law</td></tr>
            <tr><td>Privacy requests and consent records</td><td>24 months after resolution / withdrawal</td><td>Accountability</td></tr>
            <tr><td>Support and contact messages</td><td>24 months</td><td>Support continuity</td></tr>
            <tr><td>Audit and security logs</td><td>Up to 24 months</td><td>Security; accountability</td></tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        At the end of a period we delete the data, irreversibly anonymise it, or place it beyond
        operational use under a documented legal hold. Backup copies expire on the backup cycle.
      </p>
    </section>

    <section class="legal-section" id="security" aria-labelledby="security-title">
      <h2 id="security-title">8. Security measures</h2>
      <ul class="legal-list">
        <li><strong>Authentication:</strong> short-lived, single-use OTP sign-in, attempt limits and rate limiting.</li>
        <li><strong>Authorisation:</strong> role-based permissions and estate-level tenant isolation; permission checks are enforced server-side on every request.</li>
        <li><strong>Secrets:</strong> visitor PINs stored as keyed hashes; QR/pass credentials encrypted; no plaintext credentials in logs or audit records.</li>
        <li><strong>Transport:</strong> HTTPS with strict browser security headers and a content-security policy.</li>
        <li><strong>Accountability:</strong> an append-only audit trail for access, payment and administrative actions, including actor, time, IP and correlation id.</li>
        <li><strong>Availability:</strong> backups, restore procedures, monitoring and controlled maintenance.</li>
        <li><strong>People:</strong> least-privilege staff access and confidentiality obligations.</li>
      </ul>
    </section>

    <section class="legal-section" id="sub-processors" aria-labelledby="sub-processors-title">
      <h2 id="sub-processors-title">9. Sub-processors and recipients</h2>
      <p>
        We use a small set of vetted providers. They may process personal data only to provide
        their service to us, under contract and confidentiality obligations.
      </p>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Service providers</caption>
          <thead>
            <tr><th scope="col">Provider type</th><th scope="col">Purpose</th><th scope="col">Data involved</th></tr>
          </thead>
          <tbody>
            <tr><td>Hosting and infrastructure</td><td>Run the website, applications and database</td><td>All platform data</td></tr>
            <tr><td>Phone verification (Google Firebase)</td><td>Send and verify one-time sign-in codes</td><td>Phone number, verification metadata</td></tr>
            <tr><td>Email delivery</td><td>Security alerts, notices and support replies</td><td>Email address, message content</td></tr>
            <tr><td>SMS delivery</td><td>One-time codes and access notifications</td><td>Phone number, short message</td></tr>
            <tr><td>Payment providers (M-Pesa, card)</td><td>Collect and confirm payments</td><td>Payment details and references</td></tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        We may also disclose data to the estate (and its authorised staff), professional advisers,
        auditors, authorities and regulators where the law requires it, and to a successor in a
        corporate transaction. We never sell personal data.
      </p>
    </section>

    <section class="legal-section" id="transfers" aria-labelledby="transfers-title">
      <h2 id="transfers-title">10. International transfers</h2>
      <p>
        Data is primarily operated from Kenya. Some providers may process it in other countries.
        Where that is the case we rely on the transfer safeguards in section 48 of the Kenya Data
        Protection Act, 2019 — appropriate contractual protections, necessity for the contract, or
        your consent where required — and we keep the data transferred to the minimum needed.
      </p>
    </section>

    <section class="legal-section" id="rights" aria-labelledby="rights-title">
      <h2 id="rights-title">11. Handling data-subject requests</h2>
      <p>
        Anyone can exercise their rights without an account, using the
        <a href="<?= e('/privacy/choices') ?>">privacy choices page</a> or by emailing
        <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a>. Our process is:
      </p>
      <ol class="legal-list legal-list--numbered">
        <li><strong>Receive and record.</strong> Each request receives an opaque reference (for example <span class="mono">DP-20261113-1A2B3C</span>) and is recorded with the notice version shown. A notification goes to our privacy inbox.</li>
        <li><strong>Verify.</strong> We confirm the requester's identity using the contact details we hold, so data is never released to the wrong person.</li>
        <li><strong>Assess.</strong> We check the request against the law and any retention obligation, and involve the estate where the record belongs to it.</li>
        <li><strong>Action and respond.</strong> We complete the request within <?= e((string) $responseDays) ?> days and explain the outcome. If we refuse, we give the reason and tell you how to complain.</li>
        <li><strong>Keep a record.</strong> The request, decision and date are retained for accountability for 24 months.</li>
      </ol>
      <p class="muted">
        There is no charge for a request unless the law permits one for manifestly unfounded or
        excessive requests, and we do not treat people differently for exercising their rights.
      </p>
    </section>

    <section class="legal-section" id="deletion" aria-labelledby="deletion-title">
      <h2 id="deletion-title">12. Deletion and account closure</h2>
      <p>
        You can request deletion on the <a href="<?= e('/data-deletion') ?>">delete my data page</a>.
        We delete or irreversibly anonymise the personal data we no longer need, and tell your
        estate where a record belongs to it. Some records may be kept for a limited period where
        the law requires it, for example:
      </p>
      <ul class="legal-list">
        <li>invoices, payments and tax records kept for the statutory accounting period;</li>
        <li>security and incident records needed for an open investigation, legal claim or safety obligation;</li>
        <li>a minimal record of the deletion request itself, for accountability.</li>
      </ul>
      <p class="muted">
        Retained records are restricted to that purpose and are not used for anything else. If you
        are a visitor whose details were recorded by a household, contact the estate office as well
        as using the deletion page, so the household record can be corrected or removed.
      </p>
    </section>

    <section class="legal-section" id="breach" aria-labelledby="breach-title">
      <h2 id="breach-title">13. Data breaches</h2>
      <p>
        If we become aware of a personal data breach, we contain it, assess the risk, and notify the
        <?= e($supervisoryAuthority) ?> without undue delay and, where required, within 72 hours of
        becoming aware. Where a breach is likely to result in a high risk to affected people, we
        also notify them without undue delay, describing the breach, the likely consequences and
        the steps we have taken and recommend. We keep an internal breach register.
      </p>
    </section>

    <section class="legal-section" id="children" aria-labelledby="children-title">
      <h2 id="children-title">14. Children</h2>
      <p>
        The platform is for adults. We do not knowingly create accounts for children. A child may
        be recorded as a visitor by a household; the responsible adult must have authority to give
        us those details and can ask for them to be removed through the
        <a href="<?= e('/privacy/choices') ?>">privacy choices page</a>.
      </p>
    </section>

    <section class="legal-section" id="governance" aria-labelledby="governance-title">
      <h2 id="governance-title">15. Governance and review</h2>
      <ul class="legal-list">
        <li>A named data protection lead (DPO contact above) owns this policy and our request-handling process.</li>
        <li>We review this policy at least annually, and whenever the platform, our providers or the law change materially.</li>
        <li>Staff and contractors with access to personal data are bound by confidentiality and receive security and data-protection awareness guidance.</li>
        <li>We maintain records of processing, a retention schedule and a breach register, and we cooperate with the ODPC.</li>
      </ul>
    </section>

    <section class="legal-section" id="contact" aria-labelledby="contact-title">
      <h2 id="contact-title">16. Contact</h2>
      <ul class="legal-list">
        <li>Privacy and data requests: <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a></li>
        <li>Data Protection Officer: <a href="mailto:<?= e($dpoEmail) ?>"><?= e($dpoEmail) ?></a></li>
        <li>Support: <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li>
        <li>Address: <?= e($legalEntity) ?>, <?= e($legalAddress) ?></li>
        <li>
          Supervisory authority: <?= e($supervisoryAuthority) ?>,
          <span class="mono"><?= e($supervisoryUrl) ?></span>
        </li>
      </ul>
    </section>
  </article>

  <aside class="card legal-cta" aria-labelledby="data-cta-title">
    <h2 class="card__title" id="data-cta-title">Exercise your rights</h2>
    <p class="muted">
      Opt in or out of optional communications, request a copy of your data, correct it, or ask us
      to delete it. Each request is recorded and given a reference code.
    </p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e('/privacy/choices') ?>">Your privacy choices</a>
      <a class="btn btn--outline btn--lg" href="<?= e('/data-deletion') ?>">Delete my data</a>
    </div>
  </aside>
</div>
