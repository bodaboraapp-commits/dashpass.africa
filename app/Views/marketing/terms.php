<?php

declare(strict_types=1);

/**
 * Public Terms & Conditions (guest layout).
 *
 * The contract that governs use of the DashPASS platform by residents, visitors,
 * estate administrators, guards and platform operators. Entity name, contact
 * address and governing law come from config/privacy.php.
 *
 * Variables: $legalController, $legalEntity, $legalAddress, $privacyEmail,
 * $supportEmail, $policyVersion, $policyEffective, $governingLaw.
 */

$legalActive = 'terms';
$legalController = isset($legalController) ? (string) $legalController : 'DashPASS';
$legalEntity = isset($legalEntity) ? (string) $legalEntity : $legalController;
$legalAddress = isset($legalAddress) ? (string) $legalAddress : 'Nairobi, Kenya';
$privacyEmail = isset($privacyEmail) ? (string) $privacyEmail : 'privacy@dashpass.africa';
$supportEmail = isset($supportEmail) ? (string) $supportEmail : $privacyEmail;
$policyVersion = isset($policyVersion) ? (string) $policyVersion : '1.0';
$policyEffective = isset($policyEffective) ? (string) $policyEffective : '13 November 2026';
$governingLaw = isset($governingLaw) ? (string) $governingLaw : 'the Republic of Kenya';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$toc = [
    ['id' => 'agreement', 'label' => 'Agreement to these terms'],
    ['id' => 'definitions', 'label' => 'Definitions'],
    ['id' => 'eligibility', 'label' => 'Eligibility and accounts'],
    ['id' => 'acceptable-use', 'label' => 'Acceptable use'],
    ['id' => 'estate-rules', 'label' => 'Estate rules and access control'],
    ['id' => 'payments', 'label' => 'Payments and billing'],
    ['id' => 'visitor-passes', 'label' => 'Visitor passes and invitations'],
    ['id' => 'privacy', 'label' => 'Data and privacy'],
    ['id' => 'intellectual-property', 'label' => 'Intellectual property'],
    ['id' => 'availability', 'label' => 'Availability, changes and support'],
    ['id' => 'disclaimers', 'label' => 'Disclaimers'],
    ['id' => 'liability', 'label' => 'Limitation of liability'],
    ['id' => 'termination', 'label' => 'Suspension and termination'],
    ['id' => 'law', 'label' => 'Governing law and disputes'],
    ['id' => 'changes', 'label' => 'Changes to these terms'],
    ['id' => 'contact', 'label' => 'Contact'],
];
?>
<div class="doc-page">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="doc-hero">
    <p class="landing-hero__eyebrow"><?= $icon('receipt', 'ico ico--sm') ?> LEGAL · TERMS</p>
    <h1>Terms &amp; Conditions</h1>
    <p class="doc-meta">
      Version <?= e($policyVersion) ?> · Effective <?= e($policyEffective) ?>
    </p>
    <p class="page-lede">
      These Terms &amp; Conditions govern your use of the <?= e($legalController) ?> Pass &amp; Pay
      platform. By using the platform you agree to them. Please read them together with our
      <a href="<?= e('/privacy') ?>">Privacy Policy</a> and
      <a href="<?= e('/data-policy') ?>">Data Policy</a>.
    </p>
  </header>

  <div class="doc-layout">
    <?= \App\Core\View::capture('marketing/_doc-sidebar', [
        'legalActive' => $legalActive,
        'toc' => $toc,
        'privacyEmail' => $privacyEmail,
        'supportEmail' => $supportEmail,
        'sideTitle' => 'Questions?',
        'sideText' => 'Contact support about accounts, billing or these terms.',
        'sideHref' => '/help',
        'sideLabel' => 'Open the Help centre',
    ]) ?>

    <article class="doc-body doc-panel">
    <section class="doc-section" id="agreement" aria-labelledby="agreement-title">
      <h2 id="agreement-title">1. Agreement to these terms</h2>
      <p>
        These Terms &amp; Conditions form a binding agreement between you and
        <strong><?= e($legalEntity) ?></strong> ("<?= e($legalController) ?>", "we", "us") for the
        use of the platform. By signing in, using any feature, or continuing to use the platform,
        you accept these terms. If you do not agree, do not use the platform.
      </p>
      <p class="muted">
        If you use the platform on behalf of an estate, employer or other organisation, you
        confirm that you are authorised to accept these terms for that organisation.
      </p>
    </section>

    <section class="doc-section" id="definitions" aria-labelledby="definitions-title">
      <h2 id="definitions-title">2. Definitions</h2>
      <ul class="doc-list">
        <li><strong>Platform</strong>: the <?= e($legalController) ?> website, applications, console and related services.</li>
        <li><strong>Estate</strong>: the residential estate or property whose office uses the platform.</li>
        <li><strong>Resident</strong>: a person given access to an estate household on the platform.</li>
        <li><strong>Visitor</strong>: a person invited or admitted to an estate through the platform.</li>
        <li><strong>Guard / security user</strong>: a person who verifies passes and records access at a gate.</li>
        <li><strong>Administrator</strong>: an estate or platform user with management permissions.</li>
        <li><strong>Pass</strong>: a digital invitation credential, including a QR code and/or PIN, issued through the platform.</li>
      </ul>
    </section>

    <section class="doc-section" id="eligibility" aria-labelledby="eligibility-title">
      <h2 id="eligibility-title">3. Eligibility and accounts</h2>
      <ul class="doc-list">
        <li>You must be at least 18 years old and legally able to enter into this agreement.</li>
        <li>
          Resident, guard and administrator accounts are normally created by an estate
          administrator. Accounts are personal: do not share your phone, sign-in code or device
          with anyone else.
        </li>
        <li>
          You must give accurate information and keep it up to date, and tell your estate office
          promptly if your number changes or your account may have been compromised.
        </li>
        <li>
          We may suspend or close an account that breaches these terms, that your estate removes,
          or that creates a security risk.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="acceptable-use" aria-labelledby="acceptable-use-title">
      <h2 id="acceptable-use-title">4. Acceptable use</h2>
      <p>You must not:</p>
      <ul class="doc-list">
        <li>use the platform for any unlawful, fraudulent or harmful purpose;</li>
        <li>impersonate another person or use a pass or account that is not yours;</li>
        <li>share, publish or sell visitor passes, PINs or QR codes, or use them to admit people who were not invited;</li>
        <li>attempt to access another estate, household or account without permission;</li>
        <li>probe, scan, bypass or interfere with security or access controls, or introduce malicious code;</li>
        <li>scrape, copy or reverse-engineer the platform, or use it to build a competing service;</li>
        <li>submit false incidents, abuse estate staff or visitors, or use the platform to harass anyone;</li>
        <li>send spam or use contact and privacy forms to attack or overload our systems.</li>
      </ul>
      <p class="muted">
        We may investigate suspected misuse, suspend access, and report unlawful conduct to the
        estate and to the relevant authorities.
      </p>
    </section>

    <section class="doc-section" id="estate-rules" aria-labelledby="estate-rules-title">
      <h2 id="estate-rules-title">5. Estate rules and access control</h2>
      <ul class="doc-list">
        <li>
          Your estate's rules, by-laws and security procedures apply alongside these terms. The
          platform helps the estate enforce them; it does not replace them.
        </li>
        <li>
          Estate administrators and guards decide whether to admit a visitor and may refuse or
          revoke access where their rules, safety or the law require it.
        </li>
        <li>
          A pass is a single-use credential for the visit window shown on it. It may be revoked,
          extended or rotated by the resident or estate. An expired or revoked pass is not valid
          entry.
        </li>
        <li>
          Parking, house rules and property access remain subject to the estate's own agreements
          with residents and visitors.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="payments" aria-labelledby="payments-title">
      <h2 id="payments-title">6. Payments and billing</h2>
      <ul class="doc-list">
        <li>
          Bills, charges and payment terms are issued by your estate. <?= e($legalController) ?>
          provides the software and payment links that let the estate collect and reconcile them.
        </li>
        <li>
          Payments are processed by third-party payment providers. Their terms and fees may apply,
          and a payment is not complete until the provider confirms it and the estate's ledger is
          updated.
        </li>
        <li>
          Always pay through the link shown on your bill inside the platform. We are not
          responsible for money sent to a third party or to details not shown in the platform.
        </li>
        <li>
          Refunds, adjustments and billing disputes are handled by the estate office that issued
          the bill, under its own rules and applicable law.
        </li>
        <li>
          Payment references and reconciliation records are kept as required by tax and accounting
          law; see the <a href="<?= e('/data-policy') ?>">Data Policy</a>.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="visitor-passes" aria-labelledby="visitor-passes-title">
      <h2 id="visitor-passes-title">7. Visitor passes and invitations</h2>
      <ul class="doc-list">
        <li>
          When you invite a visitor you confirm you have their permission to give us their details
          for that visit, and that the details are accurate.
        </li>
        <li>
          You are responsible for passing the invitation only to the intended visitor and for
          revoking it if plans change.
        </li>
        <li>
          Visitors must follow estate rules and present a valid pass and identification if asked.
        </li>
        <li>
          Access records (time, gate, hosting household) are kept for security and audit purposes
          as described in our <a href="<?= e('/privacy') ?>">Privacy Policy</a>.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="privacy" aria-labelledby="privacy-title">
      <h2 id="privacy-title">8. Data and privacy</h2>
      <p>
        Our <a href="<?= e('/privacy') ?>">Privacy Policy</a> and
        <a href="<?= e('/data-policy') ?>">Data Policy</a> explain what personal data we process,
        why, for how long and how you can exercise your rights. They form part of these terms.
        You can manage optional communications and request access, correction or deletion on the
        <a href="<?= e('/privacy/choices') ?>">privacy choices page</a>.
      </p>
    </section>

    <section class="doc-section" id="intellectual-property" aria-labelledby="intellectual-property-title">
      <h2 id="intellectual-property-title">9. Intellectual property</h2>
      <p>
        The platform, including its software, design, text, graphics and trade marks, belongs to
        <?= e($legalEntity) ?> or its licensors. We grant you a limited, non-exclusive,
        non-transferable licence to use the platform for its intended purpose while your account
        is active. You must not copy, modify, distribute or create derivative works from the
        platform except as allowed by law.
      </p>
      <p class="muted">
        Content you submit (for example a notice, incident report or message) remains yours; you
        grant us the licence needed to store, display and process it to run the service.
      </p>
    </section>

    <section class="doc-section" id="availability" aria-labelledby="availability-title">
      <h2 id="availability-title">10. Availability, changes and support</h2>
      <ul class="doc-list">
        <li>We work to keep the platform available but cannot promise uninterrupted or error-free service.</li>
        <li>
          We may change, improve or retire features, and may schedule maintenance. Where a change
          materially affects your use we will give reasonable notice.
        </li>
        <li>
          Support is available through <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>
          and the <a href="<?= e('/help') ?>">Help centre</a>. Your estate office handles
          estate-specific issues such as household links, bills and gate access.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="disclaimers" aria-labelledby="disclaimers-title">
      <h2 id="disclaimers-title">11. Disclaimers</h2>
      <p>
        To the extent allowed by law, the platform is provided "as is" and "as available". We do
        not warrant that it will meet every requirement, that it will always be available, or
        that data will never be lost. Estate safety remains a shared responsibility: the estate's
        security team and residents must follow estate procedures alongside the platform. Nothing
        in these terms excludes liability that cannot lawfully be excluded, including for fraud or
        death or personal injury caused by negligence.
      </p>
    </section>

    <section class="doc-section" id="liability" aria-labelledby="liability-title">
      <h2 id="liability-title">12. Limitation of liability</h2>
      <p>
        To the extent allowed by law, <?= e($legalEntity) ?> is not liable for indirect,
        incidental, special or consequential loss, loss of profit, loss of goodwill, or loss of
        data arising from your use of, or inability to use, the platform. Our total liability
        arising out of or in connection with the platform is limited to the amounts the estate paid
        to us for the service in the 12 months before the event giving rise to the claim. Nothing
        in these terms limits your rights as a consumer under applicable law.
      </p>
    </section>

    <section class="doc-section" id="termination" aria-labelledby="termination-title">
      <h2 id="termination-title">13. Suspension and termination</h2>
      <ul class="doc-list">
        <li>
          We may suspend or terminate your access immediately where you breach these terms, create
          a security risk, or where your estate removes your membership or account.
        </li>
        <li>
          You can ask your estate office to close your account, or exercise your deletion rights
          through the <a href="<?= e('/data-policy') ?>">Data Policy</a> process.
        </li>
        <li>
          On termination, your right to use the platform ends. Provisions that by their nature
          should survive (for example intellectual property, disclaimers, liability and governing
          law) continue to apply.
        </li>
      </ul>
    </section>

    <section class="doc-section" id="law" aria-labelledby="law-title">
      <h2 id="law-title">14. Governing law and disputes</h2>
      <p>
        These terms are governed by the laws of <?= e($governingLaw) ?>. If a dispute arises, we
        ask you to contact us first at <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>
        so we can try to resolve it amicably. If that fails, the courts of <?= e($governingLaw) ?>
        have jurisdiction, without prejudice to any right you have under consumer protection law.
      </p>
    </section>

    <section class="doc-section" id="changes" aria-labelledby="changes-title">
      <h2 id="changes-title">15. Changes to these terms</h2>
      <p>
        We may update these terms from time to time. The version and effective date are shown at
        the top of this page. Material changes will be notified through the app or by email where
        appropriate. If you keep using the platform after the effective date you accept the
        updated terms.
      </p>
    </section>

    <section class="doc-section" id="contact" aria-labelledby="contact-title">
      <h2 id="contact-title">16. Contact</h2>
      <ul class="doc-list">
        <li>Support: <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li>
        <li>Privacy: <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a></li>
        <li>Address: <?= e($legalEntity) ?>, <?= e($legalAddress) ?></li>
      </ul>
    </section>
    </article>
  </div>

  <aside class="card doc-cta" aria-labelledby="terms-cta-title">
    <h2 class="card__title" id="terms-cta-title">Your data, your choices</h2>
    <p class="muted">
      You can opt in or out of optional communications and request access, correction or deletion
      of your personal data at any time.
    </p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e('/privacy/choices') ?>">Your privacy choices</a>
      <a class="btn btn--outline btn--lg" href="<?= e('/data-policy') ?>">Read the Data Policy</a>
    </div>
  </aside>
</div>
