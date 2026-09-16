import { test, expect } from '../../fixtures';
import { createEventWithAttendee } from '../../api/factory';

test.describe('messages with SMS', () => {
  test('an organizer with the SMS add-on composes a marketing SMS, reviews the cost and sends it', async ({ authedPage, api, account }) => {
    const event = await createEventWithAttendee(api, account.organizerId);
    await api.enableSms(account.organizerId, 'Klubben');

    await authedPage.goto(`/manage/event/${event.eventId}/messages`);
    await authedPage.getByTestId('message-compose-button').click();
    await authedPage.getByRole('heading', { name: 'Send a message' }).waitFor();

    await authedPage.getByRole('combobox', { name: 'Recipients' }).click();
    await authedPage.getByRole('option', { name: 'All attendees of this event' }).click();

    await authedPage.getByTestId('message-purpose').getByText('Marketing').click();
    await expect(authedPage.getByText('Only buyers who ticked the marketing box at checkout')).toBeVisible();

    await authedPage.getByTestId('message-channel').getByText('SMS', { exact: true }).click();
    await expect(authedPage.getByLabel(/^Subject/)).toHaveCount(0);

    await authedPage.getByLabel(/^SMS text/).fill('Hej! Nytt event i november, boka tidigt.');
    await expect(authedPage.getByText(/Sender: Klubben .* 1 part\(s\) .* per recipient .* unsubscribe link is added automatically/)).toBeVisible();
    await expect(authedPage.getByTestId('message-recipient-summary')).toContainText('SMS: 0 recipients');
    await expect(authedPage.getByTestId('message-recipient-summary')).toContainText('1 excluded without marketing consent');

    await authedPage.getByRole('checkbox', { name: /I confirm this is marketing/ }).check();
    await authedPage.getByRole('button', { name: 'Review and send' }).click();
    await expect(authedPage.getByTestId('message-review-summary')).toContainText('Marketing · SMS');
    await expect(authedPage.getByTestId('message-review-summary')).toContainText('SMS: 0 recipients');

    await authedPage.getByTestId('message-review-back').click();
    await expect(authedPage.getByTestId('message-review-summary')).toHaveCount(0);
    await authedPage.getByRole('button', { name: 'Review and send' }).click();

    const send = authedPage.waitForResponse((response) => response.url().endsWith(`/events/${event.eventId}/messages`) && response.request().method() === 'POST');
    await authedPage.getByRole('button', { name: 'Send Message' }).click();
    const body = await (await send).json();
    expect(body.data.channel).toBe('SMS');
    expect(body.data.purpose).toBe('MARKETING');

    await expect(authedPage.getByText('Hej! Nytt event i november, boka tidigt.').first()).toBeVisible();
  });

  test('an invalid unsubscribe link explains itself instead of failing', async ({ page }) => {
    await page.goto('/u/12345.notarealsignature');
    await expect(page.getByText('This unsubscribe link is not valid')).toBeVisible();
  });
});
