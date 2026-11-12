<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * T97: the resident dashboard's "Your estate" details card moved from Home to
 * /app/estates. Home keeps the hero + a Details link; /app/estates shows the
 * current estate card (role, type/timezone, households with primary chip, gates
 * with status chips, plan) plus honest empty states.
 *
 * See docs/pwa-shell-contract.md (resident home/account) and the T96 payload
 * (EstateController::webEstateSwitcher dashboard {estate, households, gates}).
 */
final class ResidentEstateDetailsTest extends VisitorModuleTestCase
{
    private const PLATFORM_ADMIN = '+254700000020';

    public function testEstatesPageShowsCurrentEstateDetailsForResident(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/app/estates');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);

        // The details card lives here now (not on Home).
        $heading = $this->xpath('//*[@id="details-title"]', $dom);
        $this->assertCount(1, $heading, 'The estates page must render the "Current estate" details card.');
        $headingNode = $heading->item(0);
        $this->assertNotNull($headingNode);
        $this->assertSame('Current estate', $this->text($headingNode));

        $h1s = $this->xpath('//h1', $dom);
        $this->assertCount(1, $h1s, 'The estates page must render exactly one h1.');
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Your estates', $this->text($h1));

        $text = $this->pageText($response);
        $this->assertTextContains('Greenview Residences', $response);
        $this->assertTextContains('Your role', $response);
        $this->assertTextContains('Resident', $response, 'The card must show the caller role in the current estate.');
        $this->assertTextContains('Mixed', $response, 'The card must show the humanised estate type.');
        $this->assertTextContains('Africa/Nairobi', $response, 'The card must show the estate timezone.');
        $this->assertTextContains('Your household', $response);
        $this->assertTextContains('A-001', $response);
        $this->assertTextContains('Block A', $response);
        $this->assertTextContains('Primary', $response, 'The primary household must carry the Primary chip.');
        $this->assertTextContains('Main Gate', $response);
        $this->assertTextContains('Plan', $response);
        $this->assertTextContains('Growth', $response, 'The card must show the estate subscription plan.');

        // Deep links must stay same-origin relative paths.
        foreach ($this->xpath('//a[@href]', $dom) as $link) {
            $href = (string) $link->getAttribute('href');
            $this->assertFalse(
                str_starts_with($href, 'http://') || str_starts_with($href, 'https://'),
                'The estates page must not use absolute links: ' . $href
            );
        }

        $this->assertStringNotContainsString('No current estate', $text);
        $this->assertStringNotContainsString('No household linked yet', $text);
    }

    public function testEstatesPageShowsNoHouseholdStateForAdminWithoutHousehold(): void
    {
        // Grace is an estate admin with no household membership.
        $this->ensureLoggedInAs(self::GRACE);

        $response = $this->get('/app/estates');
        $this->assertStatus(200, $response);

        $this->assertTextContains('Current estate', $response);
        $this->assertTextContains('Your role', $response);
        $this->assertTextContains('No household linked yet', $response, 'An admin without a household sees the honest no-household state.');
        $this->assertTextContains('Main Gate', $response, 'Gate details are still shown.');
    }

    public function testEstatesPageShowsNoCurrentEstateStateForPlatformAdmin(): void
    {
        // Daniel is a platform super admin with no estate membership.
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        $response = $this->get('/app/estates');
        $this->assertStatus(200, $response);

        $this->assertTextContains('Your estates', $response);
        $this->assertTextContains('No current estate', $response, 'A user without an active estate sees the honest empty state.');
        $this->assertStringNotContainsString('Greenview Residences', $this->pageText($response));
    }

    public function testAccountNoLongerShowsTheEstatesCard(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/app/account');
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);
        $this->assertCount(0, $this->xpath('//*[@id="memberships-title"]', $dom), 'Account must not render the removed Your estates card.');
        $this->assertCount(0, $this->xpath('//main//a[@href="/app/estates"]', $dom), 'Account main content must not keep the removed card Manage link.');
        $this->assertBodyNotContains('Your estates', $response);
        $this->assertBodyNotContains('Memberships are managed by your estate administrator.', $response);
        $this->assertBodyNotContains('Riverside Court', $response);

        // The rest of Account stays intact.
        $this->assertTextContains('Household services', $response);
        $this->assertTextContains('About MtaaPass', $response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'Account keeps exactly one h1.');
    }

    public function testEstatesCarriesFullMembershipInfoAndManageAction(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/app/estates');
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'Estates keeps exactly one h1.');
        $this->assertBodyContains('Memberships are managed by your estate administrator.', $response);
        $this->assertTextContains('Greenview Residences', $response);
        $this->assertTextContains('Role', $response);
        $this->assertTextContains('Active estate', $response);
        $this->assertTextContains('Open dashboard', $response);
        $this->assertTrue($this->xpath('//a[@href="/app"][contains(normalize-space(.),"Open dashboard")]', $dom)->length >= 1, 'The active estate keeps its open/manage action.');
        $this->assertTrue($this->xpath('//*[@id="membership-title"]', $dom)->length === 1, 'The Your estates membership section remains on estates.');

        $this->ensureLoggedInAs(self::GRACE);
        $grace = $this->get('/app/estates');
        $this->assertStatus(200, $grace);
        $this->assertTextContains('Estate Admin', $grace, 'The estate row must expose the role label.');
        $this->assertBodyContains('Memberships are managed by your estate administrator.', $grace);
    }

    public function testHomeAndEstatesPageShareTheSameEstateIdentity(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $home = $this->get('/app');
        $estates = $this->get('/app/estates');
        $this->assertStatus(200, $home);
        $this->assertStatus(200, $estates);

        $this->assertTextContains('Greenview Residences', $home);
        $this->assertTextContains('Greenview Residences', $estates);

        // Home keeps the hero + Details link; the full detail card is only on the estates page.
        $this->assertCount(1, $this->xpath('//a[contains(normalize-space(.),"Details")]', $this->dom($home)));
        $this->assertCount(1, $this->xpath('//*[@id="details-title"]', $this->dom($estates)));
    }
}
