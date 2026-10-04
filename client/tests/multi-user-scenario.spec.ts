import { test, expect, BrowserContext, Page } from '@playwright/test'
import { initProfile } from './helpers/profile'

/**
 * Two independent profiles each create a session, then one creates an assessment.
 * Sessions must stay distinct (user id and key pair).
 */

test.describe('Multi-User Assessment Scenario', () => {
  let context1: BrowserContext
  let context2: BrowserContext
  let page1: Page
  let page2: Page

  test.beforeAll(async ({ browser }) => {
    context1 = await browser.newContext()
    context2 = await browser.newContext()
    page1 = await context1.newPage()
    page2 = await context2.newPage()
  })

  test.afterAll(async () => {
    await page1?.close()
    await page2?.close()
    await context1?.close()
    await context2?.close()
  })

  test('Initialize profile 1, create assessment, then initialize user 2', async () => {
    await test.step('Initialize Profile 1', async () => {
      await initProfile(page1, '[Test] User 1')
    })

    await test.step('Create an assessment', async () => {
      await page1.getByTestId('assessment-create-button').click()
      await page1.waitForURL('**/create_assessment', { timeout: 10000 })

      await page1.getByTestId('assessment-no-subject').click()
      await page1.getByTestId('assessment-name').fill('[Test] Shared Midterm')
      await page1.getByTestId('assessment-subject').selectOption('Math')
      await page1.getByTestId('assessment-date').fill('2026-10-15')
      await page1.getByTestId('assessment-submit').click()

      await page1.waitForURL(/\/assessment\/[^/]+$/, { timeout: 15000 })
      await expect(page1.getByTestId('assessment-details-heading')).toContainText('[Test] Shared Midterm')
    })

    await test.step('Initialize User 2 in another browser', async () => {
      await initProfile(page2, '[Test] User 2')

      const localStorage1 = await page1.evaluate(() => localStorage.getItem('corrai-session'))
      const localStorage2 = await page2.evaluate(() => localStorage.getItem('corrai-session'))

      expect(localStorage1).toBeTruthy()
      expect(localStorage2).toBeTruthy()

      const session1 = JSON.parse(localStorage1 || '{}')
      const session2 = JSON.parse(localStorage2 || '{}')

      expect(session1.keyPair?.publicKey).toBeTruthy()
      expect(session2.keyPair?.publicKey).toBeTruthy()
      expect(session1.keyPair.publicKey).not.toBe(session2.keyPair.publicKey)

      expect(session1.user_id).toBeTruthy()
      expect(session2.user_id).toBeTruthy()
      expect(session1.user_id).not.toBe(session2.user_id)

      expect(session1.user_name).toBe('[Test] User 1')
      expect(session2.user_name).toBe('[Test] User 2')
    })
  })
})
