<?php

declare(strict_types=1);

/**
 * Public Privacy Policy (guest layout).
 *
 * Written for the Kenya Data Protection Act, 2019 and the ODPC guidance, and
 * aligned with the data the DashPASS platform actually processes. Effective
 * date / version / contacts come from config/privacy.php so the published
 * notice can be updated without editing this view.
 *
 * Variables (all defensive): $legalController, $legalEntity, $legalAddress,
 * $privacyEmail, $dpoEmail, $supportEmail, $policyVersion, $policyEffective,
 * $responseDays, $supervisoryAuthority, $supervisoryUrl, $governingLaw.
 */

$legalActive = 'privacy';
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
    <p class="landing-hero__eyebrow"><?= $icon('lock', 'ico ico--sm') ?> LEGAL · PRIVACY</p>
    <h1>Privacy Policy</h1>
    <p class="legal-meta">
      Version <?= e($policyVersion) ?> · Effective <?= e($policyEffective) ?>
    </p>
    <p class="page-lede">
      This Privacy Policy explains how <?= e($legalController) ?> collects, uses, shares and
      protects personal data when you use the <?= e($legalController) ?> Pass &amp; Pay platform,
      and the rights you have under the Kenya Data Protection Act, 2019.
    </p>
  </header>

  <nav class="legal-toc" aria-label="On this page">
    <p class="legal-toc__title">On this page</p>
    <ol class="legal-toc__list">
      <li><a href="#who-we-are">Who we are</a></li>
      <li><a href="#scope">Scope</a></li>
      <li><a href="#what-we-collect">Personal data we collect</a></li>
      <li><a href="#what-we-do-not-collect">What we do not collect</a></li>
      <li><a href="#how-we-use">How and why we use data</a></li>
      <li><a href="#cookies">Cookies and local storage</a></li>
      <li><a href="#sharing">Who we share data with</a></li>
      <li><a href="#transfers">Where data is processed</a></li>
      <li><a href="#retention">How long we keep data</a></li>
      <li><a href="#security">How we protect data</a></li>
      <li><a href="#rights">Your rights</a></li>
      <li><a href="#children">Children</a></li>
      <li><a href="#automated">Automated decisions</a></li>
      <li><a href="#changes">Changes to this policy</a></li>
      <li><a href="#contact">Contact and complaints</a></li>
    </ol>
  </nav>

  <article class="legal-body stack">
    <section class="legal-section" id="who-we-are" aria-labelledby="who-we-are-title">
      <h2 id="who-we-are-title">1. Who we are</h2>
      <p>
        <?= e($legalController) ?> is the Pass &amp; Pay management system for residential estates.
        It lets residents invite visitors and hold single-use access passes, let security teams
        verify those passes at the gate, and lets estate offices issue bills and reconcile
        payments in one place.
      </p>
      <p>
        The legal entity responsible for the platform is <strong><?= e($legalEntity) ?></strong>,
        <?= e($legalAddress) ?>. Our data protection contact is
        <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a>; our Data Protection
        Officer / data protection lead can be reached at
        <a href="mailto:<?= e($dpoEmail) ?>"><?= e($dpoEmail) ?></a>.
      </p>
      <p class="muted">
        Two roles apply. For your platform account, sign-in and use of the website and apps,
        <?= e($legalController) ?> is the data controller. For estate records such as households,
        visitor invitations and gate logs, the estate (through its administrators) is usually
        the controller and <?= e($legalController) ?> acts as a processor on the estate's
        instructions. Where a request involves estate records, we work with the relevant estate
        to answer it.
      </p>
    </section>

    <section class="legal-section" id="scope" aria-labelledby="scope-title">
      <h2 id="scope-title">2. Scope</h2>
      <p>
        This policy covers the <?= e($legalController) ?> public website, the resident and staff
        applications, the guard (gate) application, the estate admin console and the platform
        console, together with any support, contact or privacy request you send us. It does not
        cover other websites or services that we do not operate, even where they are linked.
      </p>
    </section>

    <section class="legal-section" id="what-we-collect" aria-labelledby="what-we-collect-title">
      <h2 id="what-we-collect-title">3. Personal data we collect</h2>
      <p>We only collect what is needed to run access control, payments and the associated records.</p>
      <ul class="legal-list">
        <li>
          <strong>Account and identity:</strong> full name, mobile phone number, optional email
          address, account status, estate memberships and roles, sign-in timestamps and last
          login. Sign-in uses a one-time code; we do not store account passwords for residents.
        </li>
        <li>
          <strong>Estate and household records:</strong> building and household/unit, member
          links, and status supplied by your estate administrator.
        </li>
        <li>
          <strong>Visitors and access:</strong> visitor name, phone number, vehicle registration,
          visit window, invitation status, pass references, check-in and check-out times, the gate
          used, the hosting household, walk-in requests and security incident reports. Access
          PINs are stored only as keyed hashes and QR credentials are encrypted at rest.
        </li>
        <li>
          <strong>Payments and billing:</strong> invoices, amounts, payment status, payment
          references and reconciliation records. Card details are handled by the payment
          provider; we do not store full card numbers or CVV values.
        </li>
        <li>
          <strong>Notices, staff and incidents:</strong> content you submit or receive in the app,
          including household staff records and incident descriptions.
        </li>
        <li>
          <strong>Communications:</strong> messages you send through the contact form, privacy
          request details, and the reference code we issue.
        </li>
        <li>
          <strong>Technical and security data:</strong> IP address, device/browser user agent,
          request timestamps and security logs, and any offline data your device caches while
          signed in.
        </li>
        <li>
          <strong>Preferences:</strong> your opt in/opt out choices for optional communications
          and the notice version you were shown when you made them.
        </li>
      </ul>
    </section>

    <section class="legal-section" id="what-we-do-not-collect" aria-labelledby="what-we-do-not-collect-title">
      <h2 id="what-we-do-not-collect-title">4. What we do not collect</h2>
      <p>
        We do not ask for special-category data (such as health, religion, ethnicity, political
        opinion or trade-union membership), biometric identifiers, precise continuous location,
        your device address book, the content of your SMS messages, or advertising identifiers.
        Please do not send us special-category data through the platform.
      </p>
    </section>

    <section class="legal-section" id="how-we-use" aria-labelledby="how-we-use-title">
      <h2 id="how-we-use-title">5. How and why we use data</h2>
      <p>
        Under the Kenya Data Protection Act, 2019 we must have a lawful basis for each use. The
        table below sets out our purposes and bases.
      </p>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Purposes of processing and lawful bases</caption>
          <thead>
            <tr><th scope="col">Purpose</th><th scope="col">Data used</th><th scope="col">Lawful basis</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Provide the platform, passes, gate verification and estate services</td>
              <td>Account, estate/household and visitor data</td>
              <td>Performance of a contract (s.30(1)(a)); legitimate interests</td>
            </tr>
            <tr>
              <td>Verify your identity and protect your account</td>
              <td>Phone number, sign-in and technical data</td>
              <td>Contract; legitimate interests in security</td>
            </tr>
            <tr>
              <td>Keep safety, access and incident records</td>
              <td>Visitor, pass, gate and incident data</td>
              <td>Legitimate interests in safety and security; legal obligation where applicable</td>
            </tr>
            <tr>
              <td>Issue bills, reconcile payments and keep accounting records</td>
              <td>Billing, payment-reference and contact data</td>
              <td>Contract; legal obligation (tax and accounting law)</td>
            </tr>
            <tr>
              <td>Send service messages and estate notices you need</td>
              <td>Contact data and notice content</td>
              <td>Contract; legitimate interests</td>
            </tr>
            <tr>
              <td>Send optional product updates, surveys or estate offers</td>
              <td>Contact data and your opt-in record</td>
              <td>Consent (s.30(1)(c)), which you can withdraw at any time</td>
            </tr>
            <tr>
              <td>Answer support, contact and privacy requests</td>
              <td>Contact details and your message</td>
              <td>Legal obligation; legitimate interests</td>
            </tr>
            <tr>
              <td>Keep the service reliable and secure (aggregated logs, abuse prevention)</td>
              <td>Technical and security data</td>
              <td>Legitimate interests; legal obligation</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        Where we rely on legitimate interests, we consider whether the processing is necessary,
        whether your rights override those interests, and we limit the data used. Where we rely
        on consent, you can withdraw it at any time on the
        <a href="<?= e('/privacy/choices') ?>">privacy choices page</a> without affecting
        processing that already happened.
      </p>
    </section>

    <section class="legal-section" id="cookies" aria-labelledby="cookies-title">
      <h2 id="cookies-title">6. Cookies and local storage</h2>
      <p>
        We use only what the service needs to work safely. The website sets a strictly necessary
        session cookie so you stay signed in, and stores a security token to protect forms. The
        app stores your theme preference and, if you install it, a service-worker cache so it can
        load offline. Optional assets such as visitor passes are stored on your device only when
        you choose to save them.
      </p>
      <p>
        We do not use advertising cookies, cross-site tracking pixels or third-party analytics
        that profile you. Phone verification is performed through Google Firebase Phone Auth,
        which may set its own technical storage for the duration of the verification challenge.
        You can block or delete cookies in your browser, but the service may then stop working.
      </p>
    </section>

    <section class="legal-section" id="sharing" aria-labelledby="sharing-title">
      <h2 id="sharing-title">7. Who we share data with</h2>
      <ul class="legal-list">
        <li>
          <strong>Your estate and its security team:</strong> estate administrators, guards and
          authorised staff only see the records their role and estate allow, and every access is
          logged.
        </li>
        <li>
          <strong>Your household:</strong> members of the same household may see household-level
          records such as visitors you invited, as part of the service.
        </li>
        <li>
          <strong>Service providers</strong> who host and run the platform: hosting, email
          delivery, SMS delivery, phone verification (Google Firebase), and payment processing
          (such as M-Pesa and card providers). They act under contract and may use your data only
          to provide their service to us.
        </li>
        <li>
          <strong>Professional advisers, auditors and insurers</strong> where necessary and
          confidential, and <strong>authorities or regulators</strong> where the law requires it,
          including the ODPC.
        </li>
        <li>
          <strong>In a corporate transaction</strong> such as a merger or sale, data may transfer
          as part of the business, subject to this policy and applicable law.
        </li>
      </ul>
      <p>
        <strong>We do not sell your personal data and we do not share it for third-party
        advertising.</strong>
      </p>
    </section>

    <section class="legal-section" id="transfers" aria-labelledby="transfers-title">
      <h2 id="transfers-title">8. Where data is processed</h2>
      <p>
        We operate primarily in Kenya and store records on infrastructure we control or that our
        hosting provider controls. Some providers (for example the phone-verification and email
        services) may process data on servers outside Kenya. When that happens we use the transfer
        safeguards required by the Kenya Data Protection Act, 2019 (section 48), such as
        contractual protections and minimising the data transferred, or rely on an applicable
        exception.
      </p>
    </section>

    <section class="legal-section" id="retention" aria-labelledby="retention-title">
      <h2 id="retention-title">9. How long we keep data</h2>
      <p>We keep personal data only as long as needed for the purpose it was collected for, or as the law requires.</p>
      <div class="legal-table-wrap">
        <table class="legal-table">
          <caption class="visually-hidden">Data retention periods</caption>
          <thead>
            <tr><th scope="col">Record</th><th scope="col">Retention</th></tr>
          </thead>
          <tbody>
            <tr><td>Account and profile</td><td>While your account is active, then up to 30 days after closure (longer only for a legal hold)</td></tr>
            <tr><td>Estate and household membership</td><td>While the membership is active, then up to 24 months</td></tr>
            <tr><td>Visitor, pass and gate access logs</td><td>12 months, unless needed for an incident, dispute or legal obligation</td></tr>
            <tr><td>Security incidents and reports</td><td>24 months after the matter is resolved</td></tr>
            <tr><td>Invoices, payments and accounting records</td><td>At least 5 years after the end of the relevant reporting period, as required by tax law</td></tr>
            <tr><td>Privacy requests and consent records</td><td>24 months after resolution / withdrawal</td></tr>
            <tr><td>Support and contact messages</td><td>24 months</td></tr>
          </tbody>
        </table>
      </div>
      <p class="muted">
        When a retention period ends we delete or irreversibly anonymise the data, or keep it in an
        aggregated form that no longer identifies anyone. Details of the current schedule are in
        our <a href="<?= e('/data-policy') ?>">Data Policy</a>.
      </p>
    </section>

    <section class="legal-section" id="security" aria-labelledby="security-title">
      <h2 id="security-title">10. How we protect data</h2>
      <p>
        Security is built into the platform, not added afterwards. Measures include:
      </p>
      <ul class="legal-list">
        <li>short-lived, single-use sign-in codes and lockouts after repeated failed attempts;</li>
        <li>role-based access control, with every estate's data isolated from every other estate;</li>
        <li>access PINs stored only as keyed hashes and QR pass credentials encrypted at rest;</li>
        <li>encrypted transport (HTTPS) and a strict browser content-security policy;</li>
        <li>an append-only audit trail for access, payment and administrative changes;</li>
        <li>rate limiting, least-privilege access for our staff, and backups with controlled restore access;</li>
        <li>confidentiality obligations for anyone who can access personal data.</li>
      </ul>
      <p class="muted">
        No system is perfectly secure. If a breach affects your personal data, we will notify the
        ODPC and the people affected as required by law.
      </p>
    </section>

    <section class="legal-section" id="rights" aria-labelledby="rights-title">
      <h2 id="rights-title">11. Your rights</h2>
      <p>Under the Kenya Data Protection Act, 2019 you have the right to:</p>
      <ul class="legal-list">
        <li>be informed about how your data is used (this policy and the Data Policy);</li>
        <li><strong>access</strong> a copy of the personal data we hold about you;</li>
        <li><strong>correct</strong> inaccurate or incomplete data;</li>
        <li><strong>delete</strong> data that is no longer needed or is being processed unlawfully;</li>
        <li><strong>restrict</strong> or <strong>object</strong> to certain processing;</li>
        <li><strong>portability</strong> of data you provided, where applicable;</li>
        <li><strong>withdraw consent</strong> for optional communications at any time;</li>
        <li>not be subject to a decision based solely on automated processing that has legal or similarly significant effects.</li>
      </ul>
      <p>
        The quickest route is the <a href="<?= e('/privacy/choices') ?>">privacy choices page</a>,
        where you can opt in or out of optional communications and request access, correction or
        deletion. You can also email <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a>
        or write to <?= e($legalAddress) ?>. We may need to verify your identity before we act, to
        avoid disclosing data to the wrong person.
      </p>
      <p>
        We respond within <strong><?= e((string) $responseDays) ?> days</strong>, as required by the
        Act. Some rights are limited where the law requires us to keep records (for example tax
        and accounting records) or where another person's rights are affected; if we cannot fulfil
        a request we will explain why.
      </p>
    </section>

    <section class="legal-section" id="children" aria-labelledby="children-title">
      <h2 id="children-title">12. Children</h2>
      <p>
        The platform is intended for adults. Accounts are created for residents aged 18 or over.
        A minor may appear as a visitor in a household's records; the responsible adult is
        responsible for giving us that information lawfully and should contact us if it needs to
        be removed.
      </p>
    </section>

    <section class="legal-section" id="automated" aria-labelledby="automated-title">
      <h2 id="automated-title">13. Automated decisions</h2>
      <p>
        We do not make decisions with legal or similarly significant effects about you solely by
        automated means. Access control rules (for example, an expired pass or a flagged plate)
        support estate and security staff, who remain responsible for decisions at the gate. You
        can ask for a human review of any access decision through your estate office or our
        contact channels.
      </p>
    </section>

    <section class="legal-section" id="changes" aria-labelledby="changes-title">
      <h2 id="changes-title">14. Changes to this policy</h2>
      <p>
        We may update this policy when the service or the law changes. The current version and
        effective date are always shown at the top of this page, and we will notify you of
        material changes through the app or by email where appropriate. The previous version
        remains available on request.
      </p>
    </section>

    <section class="legal-section" id="contact" aria-labelledby="contact-title">
      <h2 id="contact-title">15. Contact and complaints</h2>
      <p>For any privacy question or request:</p>
      <ul class="legal-list">
        <li>Privacy inbox: <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a></li>
        <li>Data Protection Officer: <a href="mailto:<?= e($dpoEmail) ?>"><?= e($dpoEmail) ?></a></li>
        <li>Support: <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li>
        <li>Address: <?= e($legalEntity) ?>, <?= e($legalAddress) ?></li>
      </ul>
      <p>
        If you are not satisfied with our response you can complain to the
        <?= e($supervisoryAuthority) ?>, <span class="mono"><?= e($supervisoryUrl) ?></span>.
      </p>
    </section>
  </article>

  <aside class="card legal-cta" aria-labelledby="privacy-cta-title">
    <h2 class="card__title" id="privacy-cta-title">Control your privacy now</h2>
    <p class="muted">
      Opt in or out of optional communications, ask for a copy of your data, correct it, or
      request deletion. You will receive a reference code to track your request.
    </p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e('/privacy/choices') ?>">Your privacy choices</a>
      <a class="btn btn--outline btn--lg" href="<?= e('/data-deletion') ?>">Delete my data</a>
    </div>
    <p class="muted">
      Read the <a href="<?= e('/terms') ?>">Terms &amp; Conditions</a> and the
      <a href="<?= e('/data-policy') ?>">Data Policy</a>.
    </p>
  </aside>
</div>
