import { expect, type Page } from '@playwright/test'

const CARD_NUMBER = '4242424242424242'
const CARD_EXPIRY = '1234'
const CARD_CVC = '123'
const CARD_POSTAL = '12345'

/**
 * Pay the hosted Stripe Checkout page with the standard test card, then return
 * to the assessment so the app can confirm the payment.
 */
export async function payStripeCheckout(page: Page, assessmentId: string): Promise<void> {
  if (!page.url().includes('checkout.stripe.com')) {
    await page.waitForURL(/checkout\.stripe\.com/, { timeout: 90_000 })
  }

  const email = page.locator('#email, input[name="email"], input[type="email"]').first()
  await email.waitFor({ state: 'visible', timeout: 30_000 })
  await email.fill(`e2e-dictation-${Date.now()}@example.com`)

  const cardRadio = page.getByRole('radio', { name: /card|carte/i })
  if (await cardRadio.count()) {
    await cardRadio.first().click()
  }

  await fillStripeInput(page, ['cardnumber', 'cardNumber', 'number'], CARD_NUMBER)
  await fillStripeInput(page, ['exp-date', 'expiry', 'cardExpiry'], CARD_EXPIRY)
  await fillStripeInput(page, ['cvc', 'cardCvc'], CARD_CVC)
  await fillStripeInput(page, ['postal', 'postalCode'], CARD_POSTAL, false)

  await page.getByRole('button', { name: /^(payer|pay)\b/i }).last().click()
  await page.waitForURL((url) => !url.hostname.includes('checkout.stripe.com'), { timeout: 120_000 })
  await returnToAssessment(page, assessmentId)
}

async function returnToAssessment(page: Page, assessmentId: string): Promise<void> {
  const current = new URL(page.url())
  const sessionId = current.searchParams.get('session_id')
  const onTestApp = current.pathname.includes('/corrai_test/')
  if (!onTestApp) {
    if (!sessionId) {
      throw new Error(`Left Stripe without a session id: ${page.url()}`)
    }
    await page.goto(
      `assessment/${assessmentId}?checkout=success&session_id=${encodeURIComponent(sessionId)}`
    )
  }

  await expect(page.getByText('Correction lancée!')).toBeVisible({ timeout: 60_000 })
  await expect(page.getByText('Paiement échoué')).toHaveCount(0)
}

async function fillStripeInput(
  page: Page,
  names: string[],
  value: string,
  required = true
): Promise<void> {
  const selector = names
    .map((name) => `input[name="${name}"], input[data-elements-stable-field-name="${name}"]`)
    .join(', ')
  const deadline = Date.now() + (required ? 30_000 : 2_000)

  while (Date.now() < deadline) {
    for (const frame of page.frames()) {
      const input = frame.locator(selector).first()
      if ((await input.count().catch(() => 0)) === 0) continue
      await input.click()
      await input.pressSequentially(value, { delay: 30 })
      return
    }
    await page.waitForTimeout(200)
  }

  if (!required) return

  const frames = page.frames().map((frame) => frame.url()).join('\n')
  throw new Error(`Stripe field not found (${names.join(', ')}).\nFrames:\n${frames}`)
}
