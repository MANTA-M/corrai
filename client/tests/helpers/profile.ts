import { expect, type Page } from '@playwright/test'

/**
 * Create a new profile via the InitProfile UI and wait for the assessments list.
 * Creates an Independent (IND) school user on the server.
 */
export async function initProfile(page: Page, userName: string): Promise<void> {
  const dialogs: string[] = []
  const onDialog = async (dialog: { message: () => string; accept: () => Promise<void> }) => {
    dialogs.push(dialog.message())
    await dialog.accept()
  }
  page.on('dialog', onDialog)

  await page.goto('')
  await page.waitForSelector('text=Créer un nouveau profil', { timeout: 10000 })
  await page.click('button:has-text("Créer un nouveau profil")')

  const userNameInput = page.locator('input[id="userName"]')
  await userNameInput.fill(userName)

  const createButton = page.locator('button[type="submit"]:has-text("Créer le profil")')
  await createButton.click()

  const heading = page.getByTestId('assessments-heading')
  try {
    await expect(heading).toHaveText('Évaluations', { timeout: 20000 })
  } catch (error) {
    const detail = dialogs.length ? ` Dialog: ${dialogs.join(' | ')}` : ''
    throw new Error(`Profile creation did not show the assessment list.${detail}`, { cause: error })
  } finally {
    page.off('dialog', onDialog)
  }
}
