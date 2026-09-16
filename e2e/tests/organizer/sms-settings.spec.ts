import { test, expect } from '../../fixtures';

test.describe('organizer SMS delivery settings', () => {
  test('the lead-time helper text follows the selected option and the choice survives a save', async ({ authedPage, account }) => {
    await authedPage.goto(`/manage/organizer/${account.organizerId}/settings#sms`);
    const leadTime = authedPage.getByTestId('sms-settings-lead-hours');
    await expect(leadTime).toBeVisible();
    await expect(leadTime).toHaveValue('3 hours before');
    await expect(authedPage.getByText('Orders placed closer to the start than this')).toBeVisible();

    await leadTime.click();
    await authedPage.getByRole('option', { name: 'Immediately at purchase' }).click();
    await expect(authedPage.getByText('The ticket SMS is sent as soon as the order is paid.')).toBeVisible();
    await expect(authedPage.getByText('Orders placed closer to the start than this')).toHaveCount(0);

    const save = authedPage.waitForResponse((response) => response.url().includes('/billing-settings') && response.request().method() === 'PUT');
    await authedPage.getByTestId('sms-settings-save-button').click();
    const body = await (await save).json();
    expect(body.data.sms_lead_hours).toBeNull();

    await authedPage.reload();
    await expect(authedPage.getByTestId('sms-settings-lead-hours')).toHaveValue('Immediately at purchase');
    await expect(authedPage.getByText('The ticket SMS is sent as soon as the order is paid.')).toBeVisible();

    await authedPage.getByTestId('sms-settings-lead-hours').click();
    await authedPage.getByRole('option', { name: '1 hour before' }).click();
    await expect(authedPage.getByText('Orders placed closer to the start than this')).toBeVisible();
  });
});
