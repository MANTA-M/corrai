import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect, type Page } from '@playwright/test'
import { initProfile } from '../helpers/profile'
import { payStripeCheckout } from '../helpers/stripeCheckout'

/**
 * Real dictation correction through the teacher UI.
 *
 * Excluded from `npm test`. From client/: npm run test:dictation
 *
 * Subject analysis calls Gemini. Test correction and full correction enqueue
 * the DictationFranceCM2 pipeline. Full correction pays with the Stripe test card.
 */

const fixtureDir = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../test/dictée_roi_soleil'
)
const subjectPath = path.join(fixtureDir, 'sujet.md')
const copyNames = [
  'copie_alice_roux.png',
  'copie_anais_nicolas.png',
  'copie_camille_faure.png',
]
const copyPaths = copyNames.map((name) => path.join(fixtureDir, name))
const teacherName = '[Test] Dictée Roi-Soleil'

test.describe('Dictation France CM2', () => {
  test.describe.configure({ mode: 'serial', timeout: 12 * 60 * 1000 })

  test.afterEach(async ({ page }) => {
    await deleteTeacherIfPresent(page).catch((error: unknown) => {
      console.error('Teacher cleanup failed:', error)
    })
  })

  test('create a teacher, correct the Roi-Soleil dictation, then delete both', async ({ page }) => {
    let assessmentId = ''

    await test.step('Create a teacher profile', async () => {
      await initProfile(page, teacherName)
      await expect(page.getByTestId('assessments-empty')).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Create an assessment from the subject file', async () => {
      await page.getByTestId('assessment-create-button').click()
      await page.waitForURL('**/create_assessment', { timeout: 10_000 })
      await page.getByTestId('assessment-subject-file').setInputFiles(subjectPath)

      const nameField = page.getByTestId('assessment-name')
      const analyzeError = page.locator('.error-message')
      await expect(nameField.or(analyzeError)).toBeVisible({ timeout: 200_000 })
      if (await analyzeError.isVisible()) {
        throw new Error(await analyzeError.innerText())
      }

      const currentName = (await nameField.inputValue()).trim()
      if (!currentName.startsWith('[Test]')) {
        await nameField.fill(currentName ? `[Test] ${currentName}` : teacherName)
      }

      await page.getByTestId('assessment-submit').click()
      try {
        await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 20_000 })
      } catch (error) {
        const subject = page.getByTestId('assessment-subject')
        if (!(await subject.isVisible())) throw error
        if ((await subject.inputValue()) === '') {
          await subject.selectOption('Dictation')
        }
        await page.getByTestId('assessment-submit').click()
        await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 20_000 })
      }

      assessmentId = assessmentIdFromUrl(page.url())
      await expect(page.getByTestId('assessment-details-heading')).toBeVisible()
    })

    await test.step('Use DictationFranceCM2', async () => {
      await page.getByTestId('assessment-edit-subject').click()
      await page.waitForURL(/\/assessment\/[^/]+\/sujet$/, { timeout: 15_000 })

      await setSubjectField(page, 'subject', 'Dictation', 'Français (dictée)')
      await setSubjectField(page, 'country', 'fr', 'France')
      await setSubjectField(page, 'level', 'CM2', 'Dictée CM2 France')

      await page.getByTestId('subject-back').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 15_000 })
      await expect(page.getByTestId('assessment-level-value')).toHaveText('Dictée CM2 France')
      await expect(page.getByTestId('assessment-subject-value')).toHaveText('Français (dictée)')
    })

    await test.step('Add copies', async () => {
      await page.getByTestId('assessment-add-file').click()
      await expect(page.getByTestId('add-file-popup')).toBeVisible()
      await expect(page.getByTestId('add-file-popup').locator('h2')).toHaveText('Ajouter des copies')
      await page.getByTestId('add-file-input').setInputFiles(copyPaths)

      await expect(page.getByTestId('add-file-popup')).toHaveCount(0, { timeout: 120_000 })

      const unassigned = page.getByTestId('unassigned-files')
      await expect(unassigned.getByTestId('assessment-file-item')).toHaveCount(3)
      for (const name of copyNames) {
        await expect(unassigned.getByTestId('assessment-file-item').filter({ hasText: name })).toBeVisible()
      }
    })

    await test.step('Launch test correction', async () => {
      await page.getByTestId('assessment-test-correction').click()
      await expect(page.getByText('Correction lancée!')).toBeVisible({ timeout: 120_000 })
      await expect(page.getByText('Échec du lancement de la correction')).toHaveCount(0)
      await expect(page.getByText('Correction lancée!')).toBeHidden({ timeout: 10_000 })
      await expect(page.getByTestId('assessment-start-correction')).toBeEnabled()
    })

    await test.step('Launch full correction', async () => {
      await page.getByTestId('assessment-start-correction').click()
      await expect(page.getByTestId('start-correction-popup')).toBeVisible()
      const price = page.getByTestId('start-correction-price')
      if (await price.isVisible()) {
        await expect(price).toContainText('× 3')
      }

      await page.getByTestId('start-correction-launch').click()
      const launched = await waitForCorrectionLaunch(page)
      if (launched === 'stripe') {
        await payStripeCheckout(page, assessmentId)
      } else {
        await expect(page.getByText('Correction lancée!')).toBeVisible()
      }
      await expect(page.getByText('Paiement échoué')).toHaveCount(0)
    })

    await test.step('Delete the assessment', async () => {
      if (!page.url().includes(`/assessment/${assessmentId}`)) {
        await page.goto(`assessment/${assessmentId}`)
      }
      page.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('supprimer cette évaluation')
        await dialog.accept()
      })
      await page.getByTestId('assessment-delete').click()
      await page.waitForURL('**/assessment-list', { timeout: 20_000 })
      await expect(page.getByTestId('assessments-empty')).toBeVisible()
      await expect(page.getByTestId('assessment-item')).toHaveCount(0)
    })

    await test.step('Delete the teacher', async () => {
      await deleteTeacher(page)
    })
  })
})

async function setSubjectField(
  page: Page,
  field: 'subject' | 'country' | 'level',
  value: string,
  expected: string
): Promise<void> {
  await page.getByTestId(`subject-${field}-edit`).click()
  const input = page.getByTestId(`subject-${field}-input`)
  const tag = await input.evaluate((element) => element.tagName)
  if (tag === 'SELECT') {
    await input.selectOption(value)
  } else {
    await input.fill(value)
  }
  await page.getByTestId(`subject-${field}-save`).click()
  await expect(page.getByTestId(`subject-${field}-value`)).toHaveText(expected, { timeout: 15_000 })
}

async function waitForCorrectionLaunch(page: Page): Promise<'stripe' | 'started'> {
  const deadline = Date.now() + 90_000
  while (Date.now() < deadline) {
    if (page.url().includes('checkout.stripe.com')) return 'stripe'
    try {
      if (await page.getByText('Correction lancée!').count()) return 'started'
      const popupError = page.getByTestId('start-correction-popup').locator('.error-message')
      if (await popupError.count()) {
        const text = (await popupError.first().innerText()).trim()
        if (text) throw new Error(text)
      }
    } catch (error) {
      if (page.url().includes('checkout.stripe.com')) return 'stripe'
      throw error
    }
    await page.waitForTimeout(250)
  }
  throw new Error('Full correction did not start')
}

function assessmentIdFromUrl(url: string): string {
  const match = url.match(/\/assessment\/([^/?#]+)/)
  if (!match) throw new Error(`Assessment id missing from ${url}`)
  return match[1]
}

async function deleteTeacher(page: Page): Promise<void> {
  await page.locator('.desktop-only .account-button').click()
  await page.waitForURL('**/settings_page', { timeout: 15_000 })
  await page.getByTestId('settings-delete-account').click()
  await expect(page.getByTestId('delete-account-popup')).toBeVisible()
  await page.getByTestId('delete-account-confirm-button').click()
  await expect(page.getByRole('button', { name: 'Créer un nouveau profil' })).toBeVisible({
    timeout: 20_000,
  })
}

async function deleteTeacherIfPresent(page: Page): Promise<void> {
  if (page.isClosed()) return
  if (!page.url().includes('/corrai_test/')) {
    await page.goto('')
  }
  const createProfile = page.getByRole('button', { name: 'Créer un nouveau profil' })
  if (await createProfile.isVisible().catch(() => false)) return

  await page.goto('settings_page')
  const deleteAccount = page.getByTestId('settings-delete-account')
  if (!(await deleteAccount.isVisible().catch(() => false))) return
  await deleteAccount.click()
  await page.getByTestId('delete-account-confirm-button').click()
  await expect(createProfile).toBeVisible({ timeout: 20_000 })
}
