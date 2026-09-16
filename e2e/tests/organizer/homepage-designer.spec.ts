import { test, expect } from '../../fixtures';
import { uniqueName } from '../../utils/unique';

const ACCENT = '#e11d48';
const BACKGROUND = '#fff1f2';

test.describe('organizer homepage designer', () => {
  test('theme colours survive save, reload and show on the public page', async ({ authedPage, page, api }) => {
    const organizer = await api.createOrganizer(uniqueName('Designer Org'));

    await authedPage.goto(`/manage/organizer/${organizer.id}/organizer-homepage-designer`);
    const accent = authedPage.getByLabel(/^Accent Color/);
    const background = authedPage.getByLabel(/^Background Color/);
    const saveStatus = authedPage.getByTestId('homepage-designer-save-status');
    await expect(accent).toBeVisible();
    await expect(saveStatus).toHaveText('All changes are saved.');

    await accent.fill(ACCENT);
    await background.fill(BACKGROUND);
    await expect(saveStatus).toContainText('unsaved changes');

    const save = authedPage.waitForResponse((response) => response.url().includes(`/organizers/${organizer.id}/settings`) && response.request().method() === 'PATCH');
    await authedPage.getByTestId('homepage-designer-save-button').click();
    expect((await save).status()).toBe(200);
    await expect(authedPage.getByText('Successfully Updated Homepage Design')).toBeVisible();
    await expect(saveStatus).toHaveText('All changes are saved.');

    await authedPage.reload();
    await expect(authedPage.getByLabel(/^Accent Color/)).toHaveValue(ACCENT);
    await expect(authedPage.getByLabel(/^Background Color/)).toHaveValue(BACKGROUND);

    await api.updateOrganizerStatus(organizer.id, 'LIVE');
    await page.goto(`/events/${organizer.id}/${organizer.slug}`);
    const main = page.locator('main[data-mode]');
    await expect(main).toBeVisible();
    await expect(main).toHaveAttribute('data-mode', 'light');
    const cssAccent = await main.evaluate((element) => getComputedStyle(element).getPropertyValue('--organizer-primary-color').trim());
    const cssBackground = await main.evaluate((element) => getComputedStyle(element).getPropertyValue('--organizer-bg-color').trim());
    expect(cssAccent.toLowerCase()).toBe(ACCENT);
    expect(cssBackground.toLowerCase()).toBe(BACKGROUND);
  });
});
