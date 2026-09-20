import { expect, type Page } from '@playwright/test'

/**
 * Create a new profile via the InitProfile UI and wait for the exams list.
 * Creates an Independent (IND) school user on the server.
 */
export async function initProfile(page: Page, userName: string): Promise<void> {
  await page.goto('/')
  await page.waitForSelector('text=Create New Profile', { timeout: 10000 })
  await page.click('button:has-text("Create New Profile")')

  const userNameInput = page.locator('input[id="userName"]')
  await userNameInput.fill(userName)

  const createButton = page.locator('button[type="submit"]:has-text("Create Profile")')
  await createButton.click()

  await page.waitForURL('**/exam-list', { timeout: 20000 })
  await expect(page.getByTestId('exams-heading')).toHaveText('Exams')
}
