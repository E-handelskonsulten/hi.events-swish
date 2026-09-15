import { test, expect } from '../../fixtures';
import { CheckInPage } from '../../pages/check-in.page';
import { createCompletedOrder, createLiveEventWithFreeTicket } from '../../api/factory';
import { uniqueName } from '../../utils/unique';

test.describe('order tickets page', () => {
  test('a buyer opens the SMS link in a fresh browser, swipes through every ticket and each code scans at the door', async ({ browser, api, publicApi, account }) => {
    const event = await createLiveEventWithFreeTicket(api, account.organizerId);
    const order = await createCompletedOrder(publicApi, event, { quantity: 3, buyerFirstName: 'Anna' });
    const list = await api.createCheckInList(event.eventId, { name: uniqueName('Door'), product_ids: [event.productId] });

    const freshContext = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: undefined });
    const page = await freshContext.newPage();
    await page.goto(`/t/${order.orderShortId}`);

    const counter = page.getByTestId('order-tickets-counter');
    await expect(counter).toContainText('Ticket 1 of 3');
    await expect(page.getByTestId('order-tickets-previous')).toBeDisabled();

    const shownIds: string[] = [];
    for (let position = 1; position <= 3; position++) {
      await expect(counter).toContainText(`Ticket ${position} of 3`);
      await expect(page.locator('svg[viewBox]').first()).toBeVisible();
      const ticketId = (await page.getByText(/^A-[A-Z0-9]+$/).first().textContent())?.trim();
      expect(ticketId).toBeTruthy();
      shownIds.push(ticketId as string);
      if (position < 3) {
        await page.getByTestId('order-tickets-next').click();
      }
    }

    await expect(page.getByTestId('order-tickets-next')).toBeDisabled();
    expect(new Set(shownIds).size).toBe(3);
    expect(shownIds.sort()).toEqual(order.attendees.map((attendee) => attendee.publicId).sort());
    expect((await freshContext.cookies()).length).toBe(0);

    const checkIn = new CheckInPage(page);
    await checkIn.goto(list.short_id);
    for (const [position, publicId] of shownIds.entries()) {
      await expect(page.getByText('USB scanner listening')).toBeVisible();
      await page.keyboard.type(publicId, { delay: 10 });
      await page.keyboard.press('Enter');
      await expect(checkIn.progressChip()).toHaveText(`${position + 1}/3`);
    }

    await freshContext.close();
  });

  test('a guessed token shows not found instead of another order', async ({ page, api, publicApi, account }) => {
    const event = await createLiveEventWithFreeTicket(api, account.organizerId);
    const order = await createCompletedOrder(publicApi, event, { quantity: 2 });

    await page.goto(`/t/${order.orderShortId.slice(0, -1)}x`);

    await expect(page.getByText('Tickets Not Found')).toBeVisible();
    await expect(page.getByTestId('order-tickets-counter')).toHaveCount(0);
  });
});
