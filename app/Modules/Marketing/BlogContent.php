<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

/**
 * DashPASS marketing blog content (owner request, 2026-11-13).
 *
 * Six SEO-optimised, long-form articles written for estate managers, residents'
 * committees and security teams in Kenya and the wider East African region.
 * Every string is plain text: the renderer is responsible for escaping it, so
 * no HTML or markdown belongs here.
 *
 * Shape (read by the marketing controller/renderer): newest-first list of posts,
 * each with slug, title, seo_title (<=60 chars), description (<=155 chars),
 * keywords, published_at/updated_at (Y-m-d), author, category, reading_minutes,
 * excerpt (<=200 chars), sections (heading + paragraphs + optional bullets),
 * faq (exactly three question/answer pairs), related_links (exactly three
 * internal links) and a cta (label + internal href).
 */
final class BlogContent
{
    /**
     * @return array<int, array<string, mixed>> Newest-first list of blog posts.
     */
    public static function posts(): array
    {
        return [
            self::digitalVisitorPasses(),
            self::trueCostOfQueues(),
            self::automatedAccessRules(),
            self::frictionlessPayments(),
            self::qrPlatesAndBarriers(),
            self::logsAndBlacklisting(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function digitalVisitorPasses(): array
    {
        return [
            'slug' => 'digital-visitor-passes-vs-paper-registers',
            'title' => 'Digital Visitor Passes vs Paper Registers: What Estates Should Know',
            'seo_title' => 'Digital Visitor Passes vs Paper Registers for Estates',
            'description' => 'Paper registers slow gate teams and hide risk. Compare them with digital visitor passes, and see what Kenyan and East African estates should require.',
            'keywords' => [
                'digital visitor pass',
                'visitor management system',
                'gated community security',
                'estate access control',
                'paper visitor register',
            ],
            'published_at' => '2026-11-13',
            'updated_at' => '2026-11-13',
            'author' => 'DashPASS Team',
            'category' => 'Access Control',
            'reading_minutes' => 5,
            'excerpt' => 'Paper registers look reassuring until the gate gets busy or an incident needs evidence. Here is what a digital visitor pass adds, and how to move over without disruption.',
            'sections' => [
                [
                    'heading' => 'The gate is a record-keeping desk as well as a checkpoint',
                    'paragraphs' => [
                        'At many estates across Kenya and East Africa, the visitor register is the only continuous record of who came onto the property, who hosted them and when they left. That makes it more than paperwork: it is the security team\'s memory, the committee\'s evidence and, increasingly, a data-protection question. A paper book can do the job, but it struggles when traffic rises, shifts change or a dispute needs evidence. A digital visitor pass does not replace the book with a screen; it changes what the gate team can see, prove and act on in the moment.',
                    ],
                ],
                [
                    'heading' => 'Where paper registers break down',
                    'paragraphs' => [
                        'Paper registers fail in predictable ways. Entries are often hurried and hard to read, especially during a morning rush or a rainy night shift. Guards who take over at the end of a shift inherit handwriting they did not write and abbreviations they do not recognise. If a book is lost, damaged or taken home for safekeeping, the estate loses weeks of history in one afternoon.',
                        'Then there is the verification gap. A register records what someone says, not what has been checked. It cannot easily show whether a visitor\'s host approved the visit, whether the pass was meant for today, or whether the same person has already entered. Paper also does not scale across multiple gates, because books rarely travel between them quickly enough. The result is a record that looks complete but is hard to trust when it matters most.',
                    ],
                    'bullets' => [
                        'Handwriting and abbreviation errors repeat on every shift.',
                        'No shared view across two or more gates.',
                        'Hard to prove host approval or pass validity.',
                        'A lost or damaged book erases weeks of history.',
                    ],
                ],
                [
                    'heading' => 'What a digital visitor pass actually does',
                    'paragraphs' => [
                        'A digital visitor pass is a time-bound record created by the resident and checked at the gate. The resident pre-registers the visitor in an app or a simple web page, the visitor receives a code or a QR pass, and the guard verifies it against the estate\'s rules before entry. Because the pass is single-use and tied to a host, a household and a validity window, the gate team can see at a glance whether the visit is expected.',
                        'The real difference is shared state. Every gate sees the same information, check-ins and check-outs are timestamped automatically, and the record is available to the admin team without waiting for a book to be collected. If a visitor arrives twice, forgets the code or tries an expired pass, the system flags it instead of relying on memory. For residents, there are no gate calls and no repeated name spellings.',
                    ],
                    'bullets' => [
                        'Passes carry host, household, time window and status.',
                        'Single-use by default, with revocation when plans change.',
                        'One shared record across every gate.',
                        'Automatic timestamps for entry and exit.',
                    ],
                ],
                [
                    'heading' => 'Privacy, accountability and the audit trail',
                    'paragraphs' => [
                        'Visitor registers hold personal data, and that brings responsibility. A digital system should limit what is collected, restrict who can see it and make it possible to answer a simple question: who accessed this record, and when? Look for role-based access, clear retention rules and the ability to export or delete data in line with your estate\'s data-protection obligations. The purpose is not to collect more data but to keep the right data for the right amount of time, and to be able to explain how it is handled.',
                        'Accountability runs both ways. Residents should be able to see the passes they created, guards should have a clear record of the decisions they made, and the committee should be able to review incidents without relying on recollection. That is fairer to guards too, who otherwise carry responsibility for a process they cannot fully document. Clear records also make shift handovers easier, because the next team can see what was decided rather than guessing.',
                    ],
                ],
                [
                    'heading' => 'Choosing a sensible transition path',
                    'paragraphs' => [
                        'You do not have to switch everything at once. Start with one gate and one visitor category, such as expected guests or delivery riders. Keep the paper book as a fallback for a few weeks, then compare how often it is actually needed. Train guards on the exceptions, not just the happy path: expired passes, a host who cannot be reached, a visitor who arrives with a different name. When the team is comfortable, extend to the remaining gates.',
                        'Choose a system that can verify passes without a live internet connection at the gate, and that tells you clearly when its data is stale. Ask how it handles shared devices, shift handovers and multiple estates. The best solution is the one your guards will use consistently at two in the morning, not the one with the longest feature list.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'Do we still need paper registers at all?',
                    'a' => 'Most estates keep a simple fallback for connectivity failures or mixed systems, but it should be the exception rather than the rule. The goal is one authoritative record that both the security team and the committee can trust, with the paper book used only to capture something the digital system cannot.',
                ],
                [
                    'q' => 'Are digital visitor passes compliant with data-protection rules?',
                    'a' => 'The pass is a tool, not a compliance guarantee. Your estate still needs a lawful reason to collect visitor data, clear retention periods and restricted access. A system that supports role-based access and deletion makes those obligations easier to meet, but the policy itself should be confirmed by your committee.',
                ],
                [
                    'q' => 'Will guards need a lot of training?',
                    'a' => 'Focus training on exceptions and shift handovers. Most guards pick up verification quickly because it is simpler than deciphering handwriting. Allow a short supervised period at one gate, with a supervisor on call, before rolling the process out across the estate.',
                ],
            ],
            'related_links' => [
                ['label' => 'Why gate queues cost more than they look', 'href' => '/blog/true-cost-of-queues-at-the-gate'],
                ['label' => 'Automated access rules explained', 'href' => '/blog/automated-access-rules-explained'],
                ['label' => 'Talk to the DashPASS team', 'href' => '/help'],
            ],
            'cta' => ['label' => 'Book a walkthrough', 'href' => '/help'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function trueCostOfQueues(): array
    {
        return [
            'slug' => 'true-cost-of-queues-at-the-gate',
            'title' => 'The True Cost of Queues at the Gate — and How to Fix It',
            'seo_title' => 'The True Cost of Gate Queues for Gated Estates',
            'description' => 'Queues at the gate are a symptom, not the problem. See the hidden costs and the practical fixes that make estate check-in faster and more reliable.',
            'keywords' => [
                'gate queue management',
                'estate security queue',
                'faster check-in',
                'gated estate operations',
                'access automation',
            ],
            'published_at' => '2026-11-12',
            'updated_at' => '2026-11-12',
            'author' => 'DashPASS Team',
            'category' => 'Operations',
            'reading_minutes' => 5,
            'excerpt' => 'A queue at the gate is easy to see but expensive in ways that do not show up on an invoice. Here is how to diagnose the real cause and redesign check-in without weakening security.',
            'sections' => [
                [
                    'heading' => 'Queues are a symptom, not the root cause',
                    'paragraphs' => [
                        'A queue at the gate is easy to see, which makes it a useful signal but a poor diagnosis. Vehicles stacking at the entrance usually reflect a decision made much earlier: a register with no expected-visitor list, a guard who must phone a resident for every arrival, a payment check performed at the barrier, or three separate steps that could be one. Treating the queue as the problem leads to blunt fixes such as extra guards or longer opening hours, which add cost without removing the cause. Treating it as a symptom points you toward the process that actually needs redesign.',
                    ],
                ],
                [
                    'heading' => 'The costs that do not appear on the invoice',
                    'paragraphs' => [
                        'The obvious cost is guard time, but the larger costs are quieter. Residents lose minutes every day waiting at their own gate, and visitors form their first impression of the estate while idling behind another car. Delivery riders abandon attempts and return later, adding calls and repeat visits. Guards under pressure skip checks or wave familiar vehicles through, which weakens the very security the queue represents.',
                        'There is also a governance cost. When queuing becomes normal, exceptions pile up: a supervisor approves an unofficial fast lane, someone keeps a private list of regular drivers, and the audit trail becomes patchy. Each workaround feels reasonable on its own, but together they make the gate harder to manage and harder to explain during an incident review. A weekly look at exceptions quickly shows where the process needs tightening.',
                    ],
                    'bullets' => [
                        'Resident time lost daily at the entrance.',
                        'Repeated delivery attempts and follow-up calls.',
                        'Informal fast lanes that bypass the record.',
                        'Patchy audit trails after handover.',
                    ],
                ],
                [
                    'heading' => 'Designing a faster, verifiable check-in',
                    'paragraphs' => [
                        'A faster gate is not a gate that checks less; it is a gate that knows more before the vehicle arrives. Residents pre-register expected visitors, so the guard sees the name, host and time window in advance. Single-use passes and QR codes remove the need to search for a booking or call the host. Household members and staff can be recognised from a maintained list, while every visitor still receives a time-bound pass.',
                        'The second gain is separation of concerns. Payment and booking checks belong before the barrier, not in the live traffic lane. If parking fees or facility charges apply, they should be confirmed when the booking is made, not negotiated at the gate. When verification and payment are settled upstream, the guard\'s job narrows to confirming identity and opening the barrier, which is exactly what a guard can do quickly and consistently.',
                    ],
                    'bullets' => [
                        'Ask residents to pre-register expected visitors.',
                        'Move payment checks to the booking step.',
                        'Give the guard one clear verification screen.',
                        'Keep an exception path for genuine cases.',
                    ],
                ],
                [
                    'heading' => 'What to measure instead of average throughput',
                    'paragraphs' => [
                        'Start with a short list of measures that change behaviour. Time from arrival to decision, the number of courtesy calls to residents, and the share of arrivals that required no manual lookup are more useful than a single average throughput figure. Add exception counts, such as expired passes or failed verifications, because they show where the process is unclear. Review the numbers weekly with the supervisor and monthly with the committee, and stop tracking anything nobody has used to make a decision.',
                    ],
                ],
                [
                    'heading' => 'A practical 30-day improvement plan',
                    'paragraphs' => [
                        'Week one: list every reason a vehicle stops for more than a few seconds, and sort the causes into pre-arrival, at-gate and post-gate. Week two: ask residents to pre-register expected visitors and set up a shared expected list. Week three: move payment checks and facility approvals to the booking step. Week four: run a shift-handover drill and confirm that the record of each decision is easy to find. Keep each change small enough that guards can absorb it.',
                        'Above all, do not judge success by whether the queue is shorter on the first day. Judge whether the gate makes better decisions with less guesswork, and whether the estate can explain those decisions afterwards. A short walk-through of the actual arrival flow with the security team is a better test than any brochure, because it shows where the process still depends on memory rather than evidence.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'How fast should a gate check-in be?',
                    'a' => 'Rather than promise a fixed number, aim for a predictable flow. Routine residents and pre-registered visitors should pass with a quick confirmation, while exceptions get the time they need. Predictability matters more than a single best-case figure, and it is easier to plan guards around.',
                ],
                [
                    'q' => 'Should we add another lane instead of changing the process?',
                    'a' => 'Extra capacity can help at peak times, but it multiplies a slow process as well as a fast one. Fix the pre-arrival steps first, then add physical capacity only where measured demand still exceeds it. A second lane also means more staff and more coordination.',
                ],
                [
                    'q' => 'Will residents accept the change?',
                    'a' => 'Residents usually welcome anything that removes gate calls and waiting, provided the first experience is clear. Send a short message explaining how to pre-register, keep a help channel open during the rollout, and ask the committee to model the new habit at the start.',
                ],
            ],
            'related_links' => [
                ['label' => 'Automated access rules explained', 'href' => '/blog/automated-access-rules-explained'],
                ['label' => 'Choosing gate hardware that works', 'href' => '/blog/qr-plates-and-boom-barriers'],
                ['label' => 'Talk to the DashPASS team', 'href' => '/help'],
            ],
            'cta' => ['label' => 'See the resident pass flow', 'href' => '/login'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function automatedAccessRules(): array
    {
        return [
            'slug' => 'automated-access-rules-explained',
            'title' => 'Automated Access Rules Explained: Single-Use Passes, Recurring Schedules and Contractor Windows',
            'seo_title' => 'Automated Access Rules Explained for Estates',
            'description' => 'Single-use passes, recurring schedules and contractor windows in plain English, with the guardrails Kenyan estates need for safe, auditable access.',
            'keywords' => [
                'automated access rules',
                'single-use visitor pass',
                'recurring access schedule',
                'contractor access management',
                'estate gate rules',
            ],
            'published_at' => '2026-11-10',
            'updated_at' => '2026-11-10',
            'author' => 'DashPASS Team',
            'category' => 'Access Control',
            'reading_minutes' => 5,
            'excerpt' => 'Access rules only work when everyone can predict them. Learn how single-use passes, recurring schedules and contractor windows turn estate policy into clear, auditable gate decisions.',
            'sections' => [
                [
                    'heading' => 'What automated access rules really mean',
                    'paragraphs' => [
                        'An access rule is a decision made before anyone arrives at the gate. Instead of relying on a guard to interpret a policy in the moment, the estate agrees the conditions under which a person may enter, the system checks those conditions, and the guard sees the result. Automation does not remove judgement; it moves routine judgement to a place where it is consistent, documented and easy to review, and it leaves the guard free for the exceptions that genuinely need a human.',
                        'The best rules are boring. They are simple enough for a resident to understand in one sentence, specific enough for a guard to verify in seconds, and visible enough that the committee can audit them later. They are also documented, so a new guard or committee member can understand them without relying on folklore. The three building blocks most estates need are single-use passes, recurring schedules and contractor windows.',
                    ],
                ],
                [
                    'heading' => 'Single-use passes for guests and deliveries',
                    'paragraphs' => [
                        'A single-use pass is the default for one-off arrivals: a guest, a delivery rider, a technician visiting for an hour. The resident creates the pass for a named visitor, with a validity window, and the pass stops working once it has been used or once the window closes. This gives the resident control and the guard certainty, without either party needing a phone call at the barrier.',
                        'Single-use protects the estate in quiet ways too. A screenshot or forwarded code has less value when it can only be used once, and a pass that was never used leaves a clear record that the visit did not happen. If plans change, the resident can revoke the pass, and the gate sees the revocation immediately. It also means the resident can change plans without a conversation at the barrier.',
                    ],
                    'bullets' => [
                        'Ideal for named guests, deliveries and short service visits.',
                        'Expires automatically, with or without use.',
                        'Revocable when plans change or a code is shared.',
                        'Leaves a clear record when a visit does not happen.',
                    ],
                ],
                [
                    'heading' => 'Recurring schedules for staff, contractors and caregivers',
                    'paragraphs' => [
                        'Some people arrive often enough that a fresh pass every day is friction. Domestic staff, gardeners, security contractors, caregivers and tutors are better served by a recurring schedule: who may enter, on which days, during which hours, and for how long the arrangement lasts. The resident or administrator approves the schedule once, and the gate verifies it on arrival. The alternative, a daily pass for every shift, creates avoidable admin and mistakes at the gate.',
                        'Recurring schedules need a review rhythm. Access that seemed reasonable in January can be inappropriate by June, so require an approval period and an owner for each schedule. When a schedule lapses, it should stop working quietly rather than requiring a scramble at the gate, and the resident should be reminded before it expires. A short reminder a week before expiry keeps the list accurate without disrupting anyone.',
                    ],
                ],
                [
                    'heading' => 'Contractor windows and after-hours exceptions',
                    'paragraphs' => [
                        'Contractors bring tools, vehicles and sometimes a crew, so their access deserves more structure than a normal guest. A contractor window limits entry to agreed hours, ties the visit to a specific project or unit, and can require a supervisor approval in advance. This keeps noisy or disruptive work inside civilised hours and gives the committee a record of who was on site if something is damaged.',
                        'After-hours access is the exception that tests every rule. Rather than leaving it entirely to the guard, define a short approval flow: who can authorise it, what information is required, and how it is recorded. A recorded reason turns an awkward judgement into a documented decision, which protects the guard and gives the committee something to review. It should also be reviewed in the next monthly security meeting.',
                    ],
                ],
                [
                    'heading' => 'Guardrails, overrides and the value of recorded reasons',
                    'paragraphs' => [
                        'Automation should fail safely. If the system cannot verify a pass, the guard needs a clear override route with a recorded reason, not a workaround invented on the spot. The same applies to residents: an emergency approval should be quick and logged, and it should be visible to the committee afterwards. These guardrails keep a human in control without reopening the door to informal decisions.',
                        'Finally, explain the rules to everyone who lives with them. A one-page summary for residents, a short briefing for guards and a periodic review by the committee will do more for adoption than any feature list. When residents understand why a rule exists, they are far more likely to help enforce it rather than ask for exceptions, and the gate team spends less time explaining the same decision.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'Can someone override an access rule?',
                    'a' => 'Yes, but the override should be deliberate and recorded. Define who may approve an exception, what information they must provide, and how the decision is logged. The guard keeps the ability to act in an emergency, while the committee keeps a record it can review later.',
                ],
                [
                    'q' => 'How do we handle recurring visitors who lose their pass?',
                    'a' => 'Treat the recurring schedule as the source of truth, not the physical code. Allow a resident to confirm the schedule or issue a short replacement pass, and make sure the guard can see the underlying approval. That way a lost or forgotten code does not become an exception to the rule.',
                ],
                [
                    'q' => 'Do automated rules replace estate policy?',
                    'a' => 'No. The rules are how policy is applied consistently at the gate. The committee still decides what the estate permits, how long data is kept and how exceptions are approved. Automation makes that policy visible and testable, which is where most of the value lies.',
                ],
            ],
            'related_links' => [
                ['label' => 'Digital passes vs paper registers', 'href' => '/blog/digital-visitor-passes-vs-paper-registers'],
                ['label' => 'A security playbook for estate managers', 'href' => '/blog/real-time-logs-and-blacklisting-playbook'],
                ['label' => 'Sign in to the resident app', 'href' => '/login'],
            ],
            'cta' => ['label' => 'Talk to the DashPASS team', 'href' => '/help'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function frictionlessPayments(): array
    {
        return [
            'slug' => 'frictionless-payment-collection-for-estates',
            'title' => 'Frictionless Payment Collection for Estates: Parking, Facilities and Utility Bills',
            'seo_title' => 'Frictionless Payment Collection for Estates',
            'description' => 'Collect parking fees, facility charges and utility bills without cash queues or reconciliation spreadsheets. A practical guide for East African estates.',
            'keywords' => [
                'estate payment collection',
                'parking fee payment',
                'utility bills estates',
                'automated billing',
                'cashless estate',
            ],
            'published_at' => '2026-11-08',
            'updated_at' => '2026-11-09',
            'author' => 'DashPASS Team',
            'category' => 'Payments',
            'reading_minutes' => 5,
            'excerpt' => 'Cash at the gate and manual reconciliation cost estate teams hours every week. Here is how to make parking, facilities and utility payments easier for residents and cleaner for the committee.',
            'sections' => [
                [
                    'heading' => 'Why estate payments are harder than they look',
                    'paragraphs' => [
                        'An estate collects money in many small moments: a visitor parking fee at the gate, a booking for the function room, a utility contribution at the end of the month, a replacement access card. Each moment seems simple, but together they create a web of cash handling, handwritten receipts, WhatsApp confirmations and spreadsheets that only one or two people fully understand. When those people are unavailable, the estate\'s financial picture becomes uncertain.',
                        'Residents feel the friction as well. They are asked to pay at inconvenient times, through different channels, with unclear confirmation. The result is late payments, repeated reminders and an administrative burden that falls on volunteers. Payment collection is not only a finance problem; it is a resident-experience problem. Clear, consistent records also help the committee show residents where the money went, which builds confidence for the next collection cycle.',
                    ],
                ],
                [
                    'heading' => 'Parking and visitor fees without cash handling',
                    'paragraphs' => [
                        'Cash at the barrier is the classic bottleneck. A guard collects notes, writes a receipt and later hands the money to a supervisor, who reconciles it against a book. Every step adds a chance for error, dispute or loss, and the queue behind the barrier grows. Moving the payment before arrival removes the negotiation from the traffic lane and gives the visitor a clear, confirmed price.',
                        'A visitor pass can carry its payment status, so the gate sees that the fee is settled without handling money. Residents can pay for a guest, or the visitor can pay in advance by mobile money. The guard only has to confirm the pass is valid, which is faster, safer and easier to audit. The same approach works for deliveries that carry a small handling fee, so nobody has to find change at the barrier.',
                    ],
                    'bullets' => [
                        'Collect fees before arrival, not at the barrier.',
                        'Attach payment status to the visitor pass.',
                        'Reduce cash handling and handwritten receipts.',
                        'Give residents a single, clear confirmation.',
                    ],
                ],
                [
                    'heading' => 'Facilities and bookings that settle themselves',
                    'paragraphs' => [
                        'Facility bookings are a natural fit for automated collection. When a resident books the function room, the tennis court or a barbecue area, the cost and the cancellation rules should be visible before they confirm. Payment can be completed in the same flow, and the booking can be released automatically if payment is not received within the agreed window. Residents see the available slots before paying, which removes the most common source of frustration.',
                        'This benefits the committee as much as the resident. Capacity is managed by the rules rather than by reminders, and the record of who booked what, when, and whether they paid is available in one place. If a booking is cancelled, the refund policy should be written down and applied consistently instead of decided case by case. Reports can show utilisation and outstanding charges side by side, which makes budgeting conversations easier.',
                    ],
                ],
                [
                    'heading' => 'Utility bills that reconcile themselves',
                    'paragraphs' => [
                        'Utility billing is where manual processes cause the most monthly pain. Whether the estate collects a flat service charge, a metered amount or a mix of both, households need a clear statement, a simple way to pay and a receipt they can find later. The estate needs the same transaction to appear in its reports without re-keying. A clear statement with the basis of the charge reduces queries before they reach the office.',
                        'A good billing flow generates the charge from an agreed basis, shows the resident exactly what they are paying for, records payment against the right household and updates the balance automatically. Reconciliation then becomes a review rather than a reconstruction. Residents who can see their history are less likely to dispute a balance, and the committee can answer questions with evidence rather than recollection.',
                    ],
                ],
                [
                    'heading' => 'Reconciliation, receipts and resident trust',
                    'paragraphs' => [
                        'The quiet goal of all this automation is trust. Residents trust a process that is predictable, consistent and easy to check. Committees trust a ledger that reconciles itself and does not depend on one person\'s memory. Guards trust a flow that removes them from price negotiations. None of this requires a complicated system; it requires a defined process and a shared record.',
                        'Start with the payment type that causes the most friction, usually parking or the monthly service charge. Prove the flow with one household group, check that reconciliation is genuinely simpler, then extend. Mobile money is already part of daily life in Kenya, so the technology is familiar; the work is in designing the rules clearly and communicating them well. A short feedback round after the first month will tell you whether the new flow is genuinely easier.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'Can residents pay with mobile money?',
                    'a' => 'Yes. A well-designed payment flow supports the channels residents already use, including mobile money and cards, and records the result against the household. The important part is that the estate gets one reconciliation report rather than a mix of messages and screenshots.',
                ],
                [
                    'q' => 'Do residents need a smartphone to pay?',
                    'a' => 'Not necessarily. Booking and payment can be arranged by a household member, by an administrator or at the office for residents who prefer not to use an app. The goal is to remove cash handling and manual reconciliation, not to exclude anyone.',
                ],
                [
                    'q' => 'How should refunds and disputes work?',
                    'a' => 'Write the policy down before the first dispute: what can be refunded, who approves it, and how long it takes. A recorded payment history makes most disputes quick to resolve because both sides can see the same facts. Consistency matters more than a generous policy.',
                ],
            ],
            'related_links' => [
                ['label' => 'Faster check-in without weaker controls', 'href' => '/blog/true-cost-of-queues-at-the-gate'],
                ['label' => 'Choosing gate hardware that works', 'href' => '/blog/qr-plates-and-boom-barriers'],
                ['label' => 'Talk to the DashPASS team', 'href' => '/help'],
            ],
            'cta' => ['label' => 'Explore how payments fit', 'href' => '/help'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function qrPlatesAndBarriers(): array
    {
        return [
            'slug' => 'qr-plates-and-boom-barriers',
            'title' => 'QR Codes, Number Plates and Boom Barriers: Choosing Access Hardware That Works',
            'seo_title' => 'QR, Number Plates and Boom Barriers: A Hardware Guide',
            'description' => 'Match gate hardware to real traffic: QR access, number-plate recognition, boom barriers and turnstiles, with practical questions to ask before you buy.',
            'keywords' => [
                'QR access control',
                'number plate recognition',
                'boom barrier integration',
                'turnstile access',
                'estate gate hardware',
            ],
            'published_at' => '2026-11-06',
            'updated_at' => '2026-11-06',
            'author' => 'DashPASS Team',
            'category' => 'Hardware',
            'reading_minutes' => 5,
            'excerpt' => 'Hardware cannot fix a vague process. Learn how to match QR codes, plate recognition, barriers and turnstiles to the traffic an East African estate actually handles.',
            'sections' => [
                [
                    'heading' => 'Choose hardware for the traffic you actually have',
                    'paragraphs' => [
                        'Access hardware is usually chosen in one of two ways: the cheapest option that opens a barrier, or the most advanced system at the security expo. Both approaches tend to disappoint. The right choice depends on the traffic mix at each gate: how many vehicles pass at peak, how many pedestrians, how often visitors arrive unannounced, and how much space exists between the road and the barrier.',
                        'A useful exercise is to stand at the gate for an hour and count types of arrival rather than total cars. Residents in familiar vehicles, pre-registered visitors, delivery riders, contractors with equipment and pedestrians all need different handling. Hardware should support the checks your process requires; it should not become the process itself. Repeat the count on a weekend, because the mix of visitors is often different from weekdays.',
                    ],
                ],
                [
                    'heading' => 'QR access: the pragmatic default',
                    'paragraphs' => [
                        'QR codes are an excellent starting point for most estates. They need no special hardware at the barrier, work with a guard\'s phone or a fixed scanner, and can be printed, shared or displayed in an app. A QR pass can carry a single-use credential, so a screenshot has limited value and the gate can verify the visit against the expected list.',
                        'The main caution is operational rather than technical: scanners and phone screens perform differently in bright sun, at night or in rain. Test the actual scanning position and give guards a typed-code fallback. When QR access is paired with a named pass and a validity window, it delivers most of the benefit of much more expensive hardware at a fraction of the cost.',
                    ],
                    'bullets' => [
                        'Low cost per gate, with or without a fixed scanner.',
                        'Works with printed passes and resident apps.',
                        'Single-use credentials limit screenshot sharing.',
                        'Always provide a typed-code fallback.',
                    ],
                ],
                [
                    'heading' => 'Number-plate recognition: convenience with caveats',
                    'paragraphs' => [
                        'Number-plate recognition can make resident and staff entry feel effortless: the vehicle approaches, the plate is read, and the barrier opens. For estates with a stable fleet of resident vehicles, it can reduce stop-start friction at busy gates. It is less effective as a standalone security control, because plates can be obscured, swapped or misread, and visitor vehicles are often unknown in advance.',
                        'If you adopt plates, treat recognition as a convenience layer on top of a verified record. Link each plate to an authorised vehicle and household, keep a manual fallback for failed reads, and review mismatches rather than assuming the camera is right. A simple rule that an unread plate means a manual check keeps the gate honest while the technology improves.',
                    ],
                ],
                [
                    'heading' => 'Boom barriers and turnstiles: safety, power and maintenance',
                    'paragraphs' => [
                        'A barrier is a safety device as much as an access device. Installations should include vehicle-detection loops so the arm does not close on a car, clear signage, and a warning light for pedestrians. Where power is unreliable, the barrier needs a documented manual release and the guard team needs to practise it before an outage, not during one. Test the release with the guard who will actually use it during a shift change.',
                        'Turnstiles suit pedestrian-heavy entrances such as clubhouse areas and staff gates, but they change the flow and need space. Maintenance is part of the budget: moving parts, sensors and controllers wear out, and a barrier that fails open is a security problem. Ask suppliers for a maintenance schedule and a realistic response time, and keep spare keys and manual overrides in a controlled place.',
                    ],
                ],
                [
                    'heading' => 'An integration checklist before you buy',
                    'paragraphs' => [
                        'Before committing, ask simple questions. Can the hardware work with the access system you already use, or does it require a separate platform? How does it behave when the network is down? Who installs it, and who supports it at night? What happens at handover if a supplier disappears? Write the answers down and compare them, rather than comparing brochures.',
                        'A good test is to walk through three scenarios with the supplier: a resident vehicle at peak, a pre-registered guest with a QR pass, and an unannounced visitor at night. If the hardware and the process handle all three cleanly, you are close. Ask the supplier to demonstrate the fallback for each scenario, not just the perfect case. If any scenario depends on someone remembering an unofficial workaround, keep looking.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'Can we mix QR access and number-plate recognition?',
                    'a' => 'Yes, and many estates do. Use plates for known resident and staff vehicles where it adds convenience, and keep QR or typed passes for visitors and exceptions. The access record should show which method was used for each entry, so reviews remain possible.',
                ],
                [
                    'q' => 'What happens during a power cut?',
                    'a' => 'Plan for it before it happens. A barrier should have a documented manual release, and guards should know how to verify passes offline and record what happens during the outage. Battery backup helps, but the procedure matters more than the battery.',
                ],
                [
                    'q' => 'Do we need to replace our existing barrier?',
                    'a' => 'Not always. Many barriers can be integrated with a new access system if their controllers allow it. Check compatibility, age and spare-part availability first; replacing working hardware unnecessarily adds cost without improving the experience.',
                ],
            ],
            'related_links' => [
                ['label' => 'Automated access rules explained', 'href' => '/blog/automated-access-rules-explained'],
                ['label' => 'Simpler payment collection for estates', 'href' => '/blog/frictionless-payment-collection-for-estates'],
                ['label' => 'About DashPASS', 'href' => '/about'],
            ],
            'cta' => ['label' => 'Plan your gate hardware', 'href' => '/help'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function logsAndBlacklisting(): array
    {
        return [
            'slug' => 'real-time-logs-and-blacklisting-playbook',
            'title' => 'Real-Time Logs and Blacklisting: A Security Playbook for Estate Managers',
            'seo_title' => 'Real-Time Logs and Blacklisting: Security Playbook',
            'description' => 'Turn visitor logs into evidence and blacklisting into a fair, effective process. A practical security playbook for Kenyan and East African estate teams.',
            'keywords' => [
                'visitor log audit',
                'estate blacklist',
                'security incident reporting',
                'audit trail access',
                'estate security software',
            ],
            'published_at' => '2026-11-02',
            'updated_at' => '2026-11-03',
            'author' => 'DashPASS Team',
            'category' => 'Security',
            'reading_minutes' => 5,
            'excerpt' => 'A visitor log is only valuable if it can answer difficult questions later. Learn how real-time records, fair blacklisting and incident follow-up work together.',
            'sections' => [
                [
                    'heading' => 'An audit trail beats a notebook',
                    'paragraphs' => [
                        'Most estate security decisions are made quickly and quietly: a guard allows an early delivery, a resident approves a late guest, a supervisor waves through a contractor whose paperwork is incomplete. In a notebook those decisions blur into handwriting, and in a dispute nobody can say exactly what happened. A real-time audit trail records the decision at the moment it is made, together with who made it and why.',
                        'The point is not surveillance of guards. It is a shared memory that protects everyone: the guard who followed the process, the resident whose visitor was logged, and the committee that has to explain the estate\'s response after an incident. Good records make security reviews calm instead of tribal. It also makes training easier, because new guards can learn from real examples rather than generic rules.',
                    ],
                ],
                [
                    'heading' => 'What a useful visitor log contains',
                    'paragraphs' => [
                        'A useful log answers four questions without guesswork: who entered, who hosted them, when they arrived and when they left. Add the pass used, the gate used and the guard who verified it, and you have enough to reconstruct almost any visit. Timestamps should be automatic, not estimated later from memory. The record should be searchable by name, household, date and gate so a review does not depend on memory.',
                        'Keep the record proportional. The log needs the visitor\'s name, the household and the time window, but it does not need to become a dossier. Restrict access by role, define retention periods, and be clear about who may export or delete data. A log that collects too much becomes a liability; a log that collects the right minimum becomes an asset.',
                    ],
                    'bullets' => [
                        'Who entered, hosted by whom, and when.',
                        'Which pass and gate were used.',
                        'Which guard verified the entry.',
                        'Access restricted by role with defined retention.',
                    ],
                ],
                [
                    'heading' => 'Blacklisting that is fair and effective',
                    'paragraphs' => [
                        'A blacklist is a serious tool and should be treated like one. It works best when it records a specific person, a specific reason and a review date, and when adding or removing an entry requires more than one person\'s approval. A vague list of names with no reasons becomes a source of complaints and inconsistent gate decisions. The reason should be specific enough to defend if the decision is questioned.',
                        'Fairness is part of effectiveness. The person affected should have a route to ask for a review, even if the estate does not share the full internal record. Entries should expire unless renewed, and the committee should review the list periodically to remove outdated entries. A blacklist that is accurate and current is respected; one that is arbitrary is quietly ignored.',
                    ],
                ],
                [
                    'heading' => 'Incident reporting and the follow-up loop',
                    'paragraphs' => [
                        'An incident report is only useful if it closes the loop. Record what happened, where, when and who was involved, then assign an owner and a next step. A report that ends with "noted" teaches the team that reporting has no consequence. A report that produces a small process change teaches the opposite. Set a realistic due date and check it at the next meeting, even if the answer is simply that more information is needed.',
                        'Instant alerts matter here. A high-priority event, such as a denied entry escalating or an after-hours exception, should reach the right supervisor quickly rather than waiting for a shift review. The alert does not replace the report; it starts the response. Together they create a rhythm: detect, respond, record, review, improve. The record should show who received the alert and what they did next, so the response can be reviewed objectively.',
                    ],
                ],
                [
                    'heading' => 'A monthly security review routine',
                    'paragraphs' => [
                        'Once a month, sit down with the security supervisor and walk through the record. Which gates were busiest, which exceptions recurred, which passes were revoked and why, and which blacklist entries still make sense? Look for patterns rather than individual incidents. Repeated exceptions usually point to a rule that is unclear or a process that is too slow. Bring the previous review notes so you can see whether last month\'s changes actually stuck.',
                        'End the review with two or three changes and a date to check them. Then record the review itself, so the next person can see what was decided. This routine takes less than an hour, and over a year it turns a pile of logs into a genuinely safer, better-understood estate. Small, consistent improvements beat a single ambitious overhaul that nobody has time to maintain.',
                    ],
                ],
            ],
            'faq' => [
                [
                    'q' => 'How long should we keep visitor logs?',
                    'a' => 'That depends on your estate\'s obligations and its own retention policy, so agree the period with the committee and any relevant guidance, then apply it consistently. Shorter retention reduces risk; longer retention helps investigations. The important thing is a documented rule rather than an indefinite pile of records.',
                ],
                [
                    'q' => 'Who should see the blacklist?',
                    'a' => 'Only people who need it to make access decisions, typically the security supervisor and the committee member responsible for security. Keep the list behind role-based access and record changes. Wider access increases the chance of leaks and unfair treatment.',
                ],
                [
                    'q' => 'Can logs be used in a dispute?',
                    'a' => 'They can provide a factual timeline, which is often more useful than conflicting recollections. Keep the record complete and unedited, restrict access, and follow your estate\'s policy on sharing data. Treat logs as evidence to be preserved, not as a tool for public shaming.',
                ],
            ],
            'related_links' => [
                ['label' => 'Digital passes vs paper registers', 'href' => '/blog/digital-visitor-passes-vs-paper-registers'],
                ['label' => 'Why gate queues cost more than they look', 'href' => '/blog/true-cost-of-queues-at-the-gate'],
                ['label' => 'Talk to the DashPASS team', 'href' => '/help'],
            ],
            'cta' => ['label' => 'Book a security review demo', 'href' => '/help'],
        ];
    }
}
